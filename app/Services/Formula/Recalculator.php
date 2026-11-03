<?php

namespace App\Services\Formula;

use App\Models\NamedRange;
use App\Models\SheetTab;
use Illuminate\Support\Facades\DB;

/**
 * Keeps every formula's stored result current.
 *
 * The dependency graph lives in cell_precedents: one row per range a formula reads. When cells change,
 * the formulas whose ranges contain them are found, then theirs, and so on; all of them are worked out
 * again in an order where each one sees its inputs' new values. Formulas that depend on their own
 * result (a circular reference) are found as strongly connected components and answer #REF! with a
 * sentence saying why.
 *
 * Writes that touch many cells at once (imports, pastes, restores, sorts) mark the sheet stale
 * instead; ensureBuilt() works a stale sheet out the next time it is read.
 *
 * Nodes are "tab:row:col" strings. Callers run this inside their own write transaction.
 */
class Recalculator
{
    public const CIRCULAR_VALUE = '#REF!';

    public const CIRCULAR = 'Circular reference: this formula depends on its own result.';

    /** Most formulas one change may recalculate at once; past it the sheets are marked stale instead. */
    public const MAX_RECALCULATED = 20000;

    private const CHUNK = 500;

    public function __construct(private FormulaEngine $engine) {}

    /**
     * Cells of one sheet were written: refresh the dependencies of those that now hold formulas, then
     * work out those formulas and everything that depends on any of the cells.
     *
     * @param  list<array{0: int, 1: int}>  $coordinates  [row, col]
     * @return list<string> the formulas worked out, as "tab:row:col"
     */
    public function cellsWritten(int $spreadsheetId, int $sheetTabId, array $coordinates): array
    {
        $seeds = [];
        $formulas = [];
        foreach ($coordinates as [$row, $col]) {
            if ($this->refreshCell($spreadsheetId, $sheetTabId, (int) $row, (int) $col)) {
                $formulas[] = $sheetTabId.':'.$row.':'.$col;
            }
            $seeds[] = [$sheetTabId, (int) $row, (int) $col];
        }

        $nodes = $this->dependentsOf($seeds, $formulas);
        if ($nodes === null) {
            $this->markStale($spreadsheetId, $sheetTabId);

            return [];
        }

        return $this->recalculate($spreadsheetId, $nodes);
    }

    /**
     * Rebuild a sheet's dependencies from its formulas and work every formula out, with the formulas on
     * other sheets that read this one.
     *
     * @param  array<int, true>  $building  sheets being worked out already (sheets that read each other)
     * @return list<string>
     */
    public function rebuildTab(int $spreadsheetId, int $sheetTabId, array $building = []): array
    {
        $formulas = $this->refreshDependencies($spreadsheetId, $sheetTabId);

        // The stale sheets this one reads are worked out first, so their results are current here.
        $building[$sheetTabId] = true;
        $readsFrom = DB::table('cell_precedents')->where('sheet_tab_id', $sheetTabId)->where('precedent_tab_id', '!=', $sheetTabId)
            ->distinct()->pluck('precedent_tab_id')->map(fn ($id) => (int) $id)->all();
        foreach (SheetTab::whereIn('id', $readsFrom)->whereNull('formulas_built_at')->pluck('id') as $stale) {
            if (! isset($building[(int) $stale])) {
                $this->rebuildTab($spreadsheetId, (int) $stale, $building);
            }
        }

        // With the formulas on other sheets that read this one, and whatever reads those.
        $readers = DB::table('cell_precedents')->where('precedent_tab_id', $sheetTabId)->where('sheet_tab_id', '!=', $sheetTabId)
            ->select('sheet_tab_id', 'row_index', 'col_index')->distinct()->get()
            ->map(fn ($r) => $r->sheet_tab_id.':'.$r->row_index.':'.$r->col_index)->all();

        $nodes = $this->dependentsOf([], [...$formulas, ...$readers]);
        if ($nodes === null) {
            // Too many to follow now: this sheet's own formulas, and the other sheets on their next read.
            $nodes = array_fill_keys($formulas, true);
            $this->markStale($spreadsheetId, $sheetTabId);
        }

        $recalculated = $this->recalculate($spreadsheetId, $nodes);
        SheetTab::whereKey($sheetTabId)->update(['formulas_built_at' => now()]);

        return $recalculated;
    }

    /**
     * Replace a sheet's dependency rows with those of the formulas it holds now.
     *
     * @return list<string> its formulas
     */
    public function refreshDependencies(int $spreadsheetId, int $sheetTabId): array
    {
        DB::table('cell_precedents')->where('sheet_tab_id', $sheetTabId)->delete();

        $names = $this->namedRanges($spreadsheetId, $sheetTabId);
        $tabs = $this->tabIds($spreadsheetId);
        $rows = [];
        $formulas = [];
        foreach (DB::table('cells')->where('spreadsheet_id', $spreadsheetId)->where('sheet_tab_id', $sheetTabId)
            ->whereNotNull('formula')->select('row_index', 'col_index', 'formula')->cursor() as $cell) {
            $formulas[] = $sheetTabId.':'.$cell->row_index.':'.$cell->col_index;
            foreach ($this->precedents((string) $cell->formula, $sheetTabId, $names, $tabs) as $range) {
                $rows[] = ['spreadsheet_id' => $spreadsheetId, 'sheet_tab_id' => $sheetTabId, 'row_index' => (int) $cell->row_index, 'col_index' => (int) $cell->col_index, ...$range];
                if (count($rows) >= self::CHUNK) {
                    DB::table('cell_precedents')->insert($rows);
                    $rows = [];
                }
            }
        }
        if ($rows !== []) {
            DB::table('cell_precedents')->insert($rows);
        }

        return $formulas;
    }

    /**
     * Many cells of a sheet changed at once: this sheet, and every sheet with formulas that read it, is
     * worked out again the next time it is read.
     */
    public function markStale(int $spreadsheetId, int $sheetTabId): void
    {
        $readers = DB::table('cell_precedents')->where('precedent_tab_id', $sheetTabId)->distinct()->pluck('sheet_tab_id')->all();

        SheetTab::where('spreadsheet_id', $spreadsheetId)->whereIn('id', [$sheetTabId, ...$readers])->update(['formulas_built_at' => null]);
    }

    /**
     * A sheet was added, renamed or deleted: a sheet name in any formula may now mean something else.
     */
    public function markWorkbookStale(int $spreadsheetId): void
    {
        SheetTab::where('spreadsheet_id', $spreadsheetId)->update(['formulas_built_at' => null]);
    }

    /**
     * Work a stale sheet out before it is read. True when it was.
     */
    public function ensureBuilt(int $spreadsheetId, int $sheetTabId): bool
    {
        $built = SheetTab::whereKey($sheetTabId)->where('spreadsheet_id', $spreadsheetId)->value('formulas_built_at');
        if ($built !== null || ! SheetTab::whereKey($sheetTabId)->exists()) {
            return false;
        }

        DB::transaction(fn () => $this->rebuildTab($spreadsheetId, $sheetTabId));

        return true;
    }

    /**
     * Replace one cell's dependencies with those of its formula. True when it holds a formula.
     */
    protected function refreshCell(int $spreadsheetId, int $sheetTabId, int $row, int $col): bool
    {
        DB::table('cell_precedents')->where('sheet_tab_id', $sheetTabId)->where('row_index', $row)->where('col_index', $col)->delete();

        $formula = DB::table('cells')->where('spreadsheet_id', $spreadsheetId)->where('sheet_tab_id', $sheetTabId)
            ->where('row_index', $row)->where('col_index', $col)->value('formula');
        if ($formula === null) {
            return false;
        }

        $rows = array_map(
            fn (array $range) => ['spreadsheet_id' => $spreadsheetId, 'sheet_tab_id' => $sheetTabId, 'row_index' => $row, 'col_index' => $col, ...$range],
            $this->precedents((string) $formula, $sheetTabId, $this->namedRanges($spreadsheetId, $sheetTabId), $this->tabIds($spreadsheetId)),
        );
        if ($rows !== []) {
            DB::table('cell_precedents')->insert($rows);
        }

        return true;
    }

    /**
     * The ranges a formula reads, on the sheets that exist.
     *
     * @param  array<string, string>  $names
     * @param  array<string, int>  $tabs  lower-case name => id
     * @return list<array{precedent_tab_id: int, row_from: int, row_to: int, col_from: int, col_to: int}>
     */
    protected function precedents(string $formula, int $sheetTabId, array $names, array $tabs): array
    {
        $ranges = [];
        foreach (FormulaText::references(FormulaText::withNamedRanges($formula, $names)) as $reference) {
            $tabId = $reference['sheet'] === null ? $sheetTabId : ($tabs[strtolower($reference['sheet'])] ?? null);
            if ($tabId === null) {
                continue;
            }

            $ranges[] = ['precedent_tab_id' => $tabId, 'row_from' => $reference['r1'], 'row_to' => $reference['r2'], 'col_from' => $reference['c1'], 'col_to' => $reference['c2']];
        }

        return $ranges;
    }

    /**
     * The formulas that read any of the seed cells, then the formulas that read those, and so on; with
     * the given formulas themselves. Null when there are more than MAX_RECALCULATED.
     *
     * @param  list<array{0: int, 1: int, 2: int}>  $seeds  [tab, row, col]
     * @param  list<string>  $formulas
     * @return array<string, true>|null
     */
    protected function dependentsOf(array $seeds, array $formulas): ?array
    {
        $found = array_fill_keys($formulas, true);
        $queue = $seeds;
        foreach ($formulas as $node) {
            $queue[] = array_map('intval', explode(':', $node));
        }
        $seen = [];

        while ($queue !== []) {
            [$tab, $row, $col] = array_pop($queue);
            if (isset($seen["{$tab}:{$row}:{$col}"])) {
                continue;
            }
            $seen["{$tab}:{$row}:{$col}"] = true;

            $readers = DB::table('cell_precedents')
                ->where('precedent_tab_id', $tab)
                ->where('col_from', '<=', $col)->where('col_to', '>=', $col)
                ->where('row_from', '<=', $row)->where('row_to', '>=', $row)
                ->select('sheet_tab_id', 'row_index', 'col_index')->distinct()->get();

            foreach ($readers as $reader) {
                $node = $reader->sheet_tab_id.':'.$reader->row_index.':'.$reader->col_index;
                if (! isset($found[$node])) {
                    $found[$node] = true;
                    if (count($found) > self::MAX_RECALCULATED) {
                        return null;
                    }
                }
                $queue[] = [(int) $reader->sheet_tab_id, (int) $reader->row_index, (int) $reader->col_index];
            }
        }

        return $found;
    }

    /**
     * Work out these formulas, each after the ones among them it reads.
     *
     * @param  array<string, true>  $nodes
     * @return list<string>
     */
    protected function recalculate(int $spreadsheetId, array $nodes): array
    {
        if ($nodes === []) {
            return [];
        }

        // Each formula's ranges, and which of the other formulas fall in them.
        $ranges = [];
        foreach (array_keys($nodes) as $node) {
            [$tab, $row, $col] = array_map('intval', explode(':', $node));
            $ranges[$node] = DB::table('cell_precedents')->where('sheet_tab_id', $tab)->where('row_index', $row)->where('col_index', $col)
                ->get(['precedent_tab_id', 'row_from', 'row_to', 'col_from', 'col_to'])->all();
        }

        $byTab = [];
        foreach (array_keys($nodes) as $node) {
            [$tab, $row, $col] = array_map('intval', explode(':', $node));
            $byTab[$tab][] = [$node, $row, $col];
        }

        $reads = [];   // node => nodes it reads
        foreach ($ranges as $node => $list) {
            $reads[$node] = [];
            foreach ($list as $range) {
                foreach ($byTab[(int) $range->precedent_tab_id] ?? [] as [$other, $row, $col]) {
                    if ($row >= $range->row_from && $row <= $range->row_to && $col >= $range->col_from && $col <= $range->col_to) {
                        $reads[$node][$other] = true;
                    }
                }
            }
        }

        $circular = $this->circular($reads);

        // Kahn's order over the rest; a circular formula counts as already worked out.
        $waiting = [];
        $readers = [];
        foreach ($reads as $node => $inputs) {
            if (isset($circular[$node])) {
                continue;
            }
            $waiting[$node] = 0;
            foreach (array_keys($inputs) as $input) {
                if ($input !== $node && ! isset($circular[$input])) {
                    $waiting[$node]++;
                    $readers[$input][] = $node;
                }
            }
        }

        $order = array_keys(array_filter($waiting, fn (int $count) => $count === 0));
        for ($i = 0; $i < count($order); $i++) {
            foreach ($readers[$order[$i]] ?? [] as $reader) {
                if (--$waiting[$reader] === 0) {
                    $order[] = $reader;
                }
            }
        }

        foreach (array_keys($circular) as $node) {
            $this->store($spreadsheetId, $node, ['value' => self::CIRCULAR_VALUE, 'error' => self::CIRCULAR]);
        }

        $this->engine->begin($spreadsheetId, $this->rangesRead($ranges));
        try {
            foreach ($order as $node) {
                [$tab, $row, $col] = array_map('intval', explode(':', $node));
                $formula = DB::table('cells')->where('spreadsheet_id', $spreadsheetId)->where('sheet_tab_id', $tab)
                    ->where('row_index', $row)->where('col_index', $col)->value('formula');
                if ($formula === null) {
                    continue;
                }

                $this->store($spreadsheetId, $node, $this->engine->evaluate($tab, $row, $col, (string) $formula));
            }
        } finally {
            $this->engine->finish();
        }

        return [...array_keys($circular), ...$order];
    }

    /**
     * @param  array<string, list<object>>  $ranges
     * @return list<array{0: int, 1: int, 2: int, 3: int, 4: int}>
     */
    protected function rangesRead(array $ranges): array
    {
        $read = [];
        foreach ($ranges as $list) {
            foreach ($list as $range) {
                $read[] = [(int) $range->precedent_tab_id, (int) $range->row_from, (int) $range->row_to, (int) $range->col_from, (int) $range->col_to];
            }
        }

        return $read;
    }

    /**
     * The formulas that take part in a cycle: Tarjan's strongly connected components of more than one
     * formula, or one formula that reads itself.
     *
     * @param  array<string, array<string, true>>  $reads
     * @return array<string, true>
     */
    protected function circular(array $reads): array
    {
        $index = 0;
        $indices = [];
        $low = [];
        $stack = [];
        $onStack = [];
        $circular = [];

        $connect = function (string $node) use (&$connect, &$index, &$indices, &$low, &$stack, &$onStack, &$circular, $reads): void {
            $indices[$node] = $low[$node] = $index++;
            $stack[] = $node;
            $onStack[$node] = true;

            foreach (array_keys($reads[$node] ?? []) as $input) {
                if (! isset($reads[$input])) {
                    continue;
                }
                if (! isset($indices[$input])) {
                    $connect($input);
                    $low[$node] = min($low[$node], $low[$input]);
                } elseif (isset($onStack[$input])) {
                    $low[$node] = min($low[$node], $indices[$input]);
                }
            }

            if ($low[$node] === $indices[$node]) {
                $component = [];
                do {
                    $member = array_pop($stack);
                    unset($onStack[$member]);
                    $component[] = $member;
                } while ($member !== $node);

                if (count($component) > 1 || isset($reads[$node][$node])) {
                    foreach ($component as $member) {
                        $circular[$member] = true;
                    }
                }
            }
        };

        foreach (array_keys($reads) as $node) {
            if (! isset($indices[$node])) {
                $connect($node);
            }
        }

        return $circular;
    }

    /**
     * @param  array{value: string, error: ?string}  $result
     */
    protected function store(int $spreadsheetId, string $node, array $result): void
    {
        [$tab, $row, $col] = array_map('intval', explode(':', $node));

        DB::table('cells')->where('spreadsheet_id', $spreadsheetId)->where('sheet_tab_id', $tab)
            ->where('row_index', $row)->where('col_index', $col)
            ->update(['computed_value' => $result['value'], 'formula_error' => $result['error']]);
    }

    /**
     * @return array<string, string>
     */
    protected function namedRanges(int $spreadsheetId, int $sheetTabId): array
    {
        return NamedRange::where('spreadsheet_id', $spreadsheetId)->where('sheet_tab_id', $sheetTabId)->get()
            ->mapWithKeys(fn (NamedRange $range) => [strtolower($range->name) => $range->toReferenceText()])
            ->all();
    }

    /**
     * @return array<string, int> lower-case name => id
     */
    protected function tabIds(int $spreadsheetId): array
    {
        return SheetTab::where('spreadsheet_id', $spreadsheetId)->get(['id', 'name'])
            ->mapWithKeys(fn (SheetTab $tab) => [strtolower($tab->name) => (int) $tab->id])
            ->all();
    }
}
