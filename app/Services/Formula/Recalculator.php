<?php

namespace App\Services\Formula;

use App\Jobs\WorkOutSheet;
use App\Models\NamedRange;
use App\Models\SheetTab;
use App\Services\SheetMerges;
use App\Services\SheetStructureService;
use Illuminate\Support\Facades\Cache;
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

    /** Most readers one trace returns: threads to more than this are noise. */
    public const MAX_TRACED = 24;

    /** Most cells one formula's answer may land on. */
    public const MAX_SPILLED_CELLS = 10000;

    /** Rounds of "what reads the cells that were just spilled on". */
    private const MAX_SPILL_PASSES = 5;

    /** Most formulas one change may recalculate at once; past it the sheets are marked stale instead. */
    public const MAX_RECALCULATED = 20000;

    private const CHUNK = 500;

    /** Areas asked of the database one by one, before a sheet's dependencies are loaded instead. */
    private const QUERIED_AREAS = 64;

    /** How long the background job is expected to take at most, while the sheet is left to it. */
    private const FINISHING_SECONDS = 900;

    private ?float $deadline = null;

    /** @var array<int, true> sheets with formulas left over when time ran out */
    private array $unfinished = [];

    /** @var list<array{0: int, 1: int, 2: int, 3: int, 4: int}> areas spilled on in the current pass */
    private array $spilled = [];

    /** @var array<string, true> the formulas that spilled in the current pass */
    private array $spilledFrom = [];

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
            } else {
                // It may have held a formula whose answer is still on the sheet.
                $area = $this->retireSpill($sheetTabId, (int) $row, (int) $col);
                if ($area !== null) {
                    $seeds[] = $area;
                }
            }
            $seeds[] = [$sheetTabId, (int) $row, (int) $row, (int) $col, (int) $col];
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
        $this->clearSpills($sheetTabId);

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
        if (! isset($this->unfinished[$sheetTabId])) {
            SheetTab::whereKey($sheetTabId)->update(['formulas_built_at' => now()]);
        }

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
        if ($built !== null || ! SheetTab::whereKey($sheetTabId)->exists() || self::isFinishing($sheetTabId)) {
            return false;
        }

        DB::transaction(fn () => $this->rebuildTab($spreadsheetId, $sheetTabId));

        return true;
    }

    /** The job that finishes a sheet has it: working it out here as well would only get in the way. */
    public static function isFinishing(int $sheetTabId): bool
    {
        return Cache::has('sheet-finishing:'.$sheetTabId);
    }

    /**
     * Finish a sheet with no one waiting. Outside one transaction: a sheet that takes minutes must not hold the
     * database for minutes, and a sheet stopped halfway is simply worked out again.
     */
    public function finishSheet(int $spreadsheetId, int $sheetTabId): void
    {
        try {
            if (SheetTab::whereKey($sheetTabId)->whereNull('formulas_built_at')->exists()) {
                $this->rebuildTab($spreadsheetId, $sheetTabId);
            }
        } finally {
            Cache::forget('sheet-finishing:'.$sheetTabId);
        }
    }

    /**
     * What a cell reads, and which formulas read it, for drawing threads.
     *
     * @return array{reads: list<array{tab: int, r1: int, r2: int, c1: int, c2: int}>, readers: list<array{tab: int, row: int, col: int}>}
     */
    public function trace(int $sheetTabId, int $row, int $col): array
    {
        $reads = DB::table('cell_precedents')->where('sheet_tab_id', $sheetTabId)->where('row_index', $row)->where('col_index', $col)
            ->get(['precedent_tab_id', 'row_from', 'row_to', 'col_from', 'col_to'])
            ->map(fn ($r) => ['tab' => (int) $r->precedent_tab_id, 'r1' => (int) $r->row_from, 'r2' => (int) $r->row_to, 'c1' => (int) $r->col_from, 'c2' => (int) $r->col_to])
            ->all();

        $readers = DB::table('cell_precedents')->where('precedent_tab_id', $sheetTabId)
            ->where('col_from', '<=', $col)->where('col_to', '>=', $col)
            ->where('row_from', '<=', $row)->where('row_to', '>=', $row)
            ->select('sheet_tab_id', 'row_index', 'col_index')->distinct()->limit(self::MAX_TRACED)->get()
            ->map(fn ($r) => ['tab' => (int) $r->sheet_tab_id, 'row' => (int) $r->row_index, 'col' => (int) $r->col_index])
            ->all();

        return ['reads' => $reads, 'readers' => $readers];
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
     * The formulas that read any of the seed areas, then the formulas that read those, and so on; with
     * the given formulas themselves, and any formula whose answer lands on (or is blocked by) a seed
     * area. Null when there are more than MAX_RECALCULATED.
     *
     * @param  list<array{0: int, 1: int, 2: int, 3: int, 4: int}>  $seeds  [tab, r1, r2, c1, c2]
     * @param  list<string>  $formulas
     * @param  array<string, true>  $skip  formulas not to bring back in (those that just spilled there)
     * @return array<string, true>|null
     */
    protected function dependentsOf(array $seeds, array $formulas, array $skip = []): ?array
    {
        $found = array_fill_keys($formulas, true);
        $queue = $seeds;
        foreach ($formulas as $node) {
            [$tab, $row, $col] = array_map('intval', explode(':', $node));
            $queue[] = [$tab, $row, $row, $col, $col];
        }
        $seen = [];
        $map = count($queue) > self::QUERIED_AREAS ? new PrecedentMap : null;

        while ($queue !== []) {
            $area = array_pop($queue);
            [$tab, $r1, $r2, $c1, $c2] = $area;
            $key = implode(':', $area);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            if ($map === null && count($seen) > self::QUERIED_AREAS) {
                $map = new PrecedentMap;
            }

            foreach ($map?->readers($tab, $r1, $r2, $c1, $c2) ?? $this->readersAsked($tab, $r1, $r2, $c1, $c2) as $node) {
                if (isset($skip[$node])) {
                    continue;
                }
                if (! isset($found[$node])) {
                    $found[$node] = true;
                    if (count($found) > self::MAX_RECALCULATED) {
                        return null;
                    }
                }
                [$readerTab, $row, $col] = array_map('intval', explode(':', $node));
                $queue[] = [$readerTab, $row, $row, $col, $col];
            }
        }

        return $found;
    }

    /**
     * @return list<string> what reads this area, or spills over it, as "tab:row:col"
     */
    private function readersAsked(int $tab, int $r1, int $r2, int $c1, int $c2): array
    {
        $readers = DB::table('cell_precedents')
            ->where('precedent_tab_id', $tab)
            ->where('col_from', '<=', $c2)->where('col_to', '>=', $c1)
            ->where('row_from', '<=', $r2)->where('row_to', '>=', $r1)
            ->select('sheet_tab_id', 'row_index', 'col_index')->distinct()->get();

        $anchors = DB::table('cells')->where('sheet_tab_id', $tab)->whereNotNull('spill_rows')
            ->where('row_index', '<=', $r2)->whereRaw('row_index + spill_rows - 1 >= ?', [$r1])
            ->where('col_index', '<=', $c2)->whereRaw('col_index + spill_cols - 1 >= ?', [$c1])
            ->selectRaw('sheet_tab_id, row_index, col_index')->get();

        return array_map(fn (object $cell) => $cell->sheet_tab_id.':'.$cell->row_index.':'.$cell->col_index, [...$readers, ...$anchors]);
    }

    /**
     * Work out these formulas, each after the ones among them it reads.
     *
     * @param  array<string, true>  $nodes
     * @return list<string>
     */
    protected function recalculate(int $spreadsheetId, array $nodes): array
    {
        $worked = [];

        // A spilled answer lands on cells no dependency row knows about until it exists, so whatever
        // reads those cells is found afterwards, in a further pass.
        $this->startClock();

        for ($pass = 0; $pass < self::MAX_SPILL_PASSES && $nodes !== []; $pass++) {
            $worked = [...$worked, ...$this->pass($spreadsheetId, $nodes)];
            $nodes = $this->readersOfSpills();
        }

        $this->leaveTheRestForLater($spreadsheetId);

        return array_values(array_unique($worked));
    }

    /** Only while a person waits: a job has as long as it takes. */
    private function startClock(): void
    {
        $seconds = (int) config('sheet.recalculate_seconds');
        if ($this->deadline === null && $seconds > 0 && (! app()->runningInConsole() || config('sheet.recalculate_always_timed'))) {
            $this->deadline = microtime(true) + $seconds;
        }
    }

    private function outOfTime(): bool
    {
        return $this->deadline !== null && microtime(true) > $this->deadline;
    }

    private function leaveTheRestForLater(int $spreadsheetId): void
    {
        foreach (array_keys($this->unfinished) as $tab) {
            SheetTab::whereKey($tab)->update(['formulas_built_at' => null]);
            Cache::put('sheet-finishing:'.$tab, true, self::FINISHING_SECONDS);
            WorkOutSheet::dispatch($spreadsheetId, $tab)->afterCommit();
        }
    }

    /**
     * What reads the cells spilled on in the pass just finished, which then starts afresh.
     *
     * @return array<string, true>
     */
    protected function readersOfSpills(): array
    {
        [$areas, $from] = [$this->spilled, $this->spilledFrom];
        $this->spilled = [];
        $this->spilledFrom = [];

        return $areas === [] ? [] : ($this->dependentsOf($areas, [], $from) ?? []);
    }

    /**
     * @param  array<string, true>  $nodes
     * @return list<string>
     */
    protected function pass(int $spreadsheetId, array $nodes): array
    {

        $ranges = $this->rangesOf($nodes);
        [$order, $circular] = $this->inOrder($nodes, $ranges);

        foreach (array_keys($circular) as $node) {
            $this->store($spreadsheetId, $node, ['value' => self::CIRCULAR_VALUE, 'error' => self::CIRCULAR]);
        }

        $this->engine->begin($spreadsheetId, $this->rangesRead($ranges));
        try {
            foreach ($order as $node) {
                [$tab, $row, $col] = array_map('intval', explode(':', $node));
                if ($this->outOfTime()) {
                    $this->unfinished[$tab] = true;

                    continue;
                }

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
     * Each formula's ranges.
     *
     * @param  array<string, true>  $nodes
     * @return array<string, list<object>>
     */
    private function rangesOf(array $nodes): array
    {
        $columns = ['sheet_tab_id', 'row_index', 'col_index', 'precedent_tab_id', 'row_from', 'row_to', 'col_from', 'col_to'];
        $ranges = array_fill_keys(array_keys($nodes), []);

        if (count($nodes) <= self::QUERIED_AREAS) {
            foreach (array_keys($nodes) as $node) {
                [$tab, $row, $col] = array_map('intval', explode(':', $node));
                $ranges[$node] = DB::table('cell_precedents')->where('sheet_tab_id', $tab)->where('row_index', $row)->where('col_index', $col)->get($columns)->all();
            }

            return $ranges;
        }

        $tabs = array_unique(array_map(fn (string $node) => (int) strtok($node, ':'), array_keys($nodes)));
        foreach (DB::table('cell_precedents')->whereIn('sheet_tab_id', $tabs)->select($columns)->cursor() as $range) {
            $node = $range->sheet_tab_id.':'.$range->row_index.':'.$range->col_index;
            if (isset($ranges[$node])) {
                $ranges[$node][] = $range;
            }
        }

        return $ranges;
    }

    /**
     * The order to work these formulas out in, each after the ones it reads, and those in a cycle. Tarjan's strongly
     * connected components, which come out inputs first: a formula that reads thousands of others costs time here, not
     * memory, because what each one reads is found as it is walked rather than stored.
     *
     * @param  array<string, true>  $nodes
     * @param  array<string, list<object>>  $ranges
     * @return array{0: list<string>, 1: array<string, true>}
     */
    private function inOrder(array $nodes, array $ranges): array
    {
        $rowsOf = [];
        foreach (array_keys($nodes) as $node) {
            [$tab, $row, $col] = array_map('intval', explode(':', $node));
            $rowsOf[$tab][$col][] = $row;
        }
        foreach ($rowsOf as &$columns) {
            foreach ($columns as &$rows) {
                sort($rows);
            }
        }
        unset($columns, $rows);

        $index = 0;
        $indices = [];
        $low = [];
        $stack = [];
        $onStack = [];
        $order = [];
        $circular = [];

        foreach (array_keys($nodes) as $root) {
            if (isset($indices[$root])) {
                continue;
            }

            // Walked by hand: a column of formulas that each read the one above is as deep as the column is long.
            $indices[$root] = $low[$root] = $index++;
            $stack[] = $root;
            $onStack[$root] = true;
            $walk = [[$root, $this->inputsOf($root, $ranges[$root], $rowsOf), false]];

            while ($walk !== []) {
                $top = count($walk) - 1;
                [$node, $inputs] = $walk[$top];

                if ($inputs->valid()) {
                    $input = $inputs->current();
                    $inputs->next();
                    if ($input === $node) {
                        $walk[$top][2] = true;
                    } elseif (! isset($indices[$input])) {
                        $indices[$input] = $low[$input] = $index++;
                        $stack[] = $input;
                        $onStack[$input] = true;
                        $walk[] = [$input, $this->inputsOf($input, $ranges[$input], $rowsOf), false];
                    } elseif (isset($onStack[$input])) {
                        $low[$node] = min($low[$node], $indices[$input]);
                    }

                    continue;
                }

                $readsItself = $walk[$top][2];
                array_pop($walk);
                if ($walk !== []) {
                    $parent = $walk[count($walk) - 1][0];
                    $low[$parent] = min($low[$parent], $low[$node]);
                }

                if ($low[$node] === $indices[$node]) {
                    $component = [];
                    do {
                        $member = array_pop($stack);
                        unset($onStack[$member]);
                        $component[] = $member;
                    } while ($member !== $node);

                    if (count($component) > 1 || $readsItself) {
                        foreach ($component as $member) {
                            $circular[$member] = true;
                        }
                    } else {
                        $order[] = $node;
                    }
                }
            }
        }

        return [$order, $circular];
    }

    /**
     * The formulas among those being worked out that fall in these ranges.
     *
     * @param  list<object>  $ranges
     * @param  array<int, array<int, list<int>>>  $rowsOf  tab => col => rows, sorted
     * @return \Generator<int, string>
     */
    private function inputsOf(string $node, array $ranges, array $rowsOf): \Generator
    {
        foreach ($ranges as $range) {
            $tab = (int) $range->precedent_tab_id;
            foreach ($rowsOf[$tab] ?? [] as $col => $rows) {
                if ($col < $range->col_from || $col > $range->col_to) {
                    continue;
                }
                [$low, $high] = [0, count($rows)];
                while ($low < $high) {
                    $middle = ($low + $high) >> 1;
                    $rows[$middle] < $range->row_from ? $low = $middle + 1 : $high = $middle;
                }
                for ($i = $low, $last = count($rows); $i < $last && $rows[$i] <= $range->row_to; $i++) {
                    yield $tab.':'.$rows[$i].':'.$col;
                }
            }
        }
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
     * Store a formula's answer. An answer of many cells lands on the cells below and to the right, unless
     * something is in the way or it would leave the sheet: then the formula answers #SPILL!.
     *
     * @param  array{value: string, error: ?string, spill?: list<list<string>>}  $result
     */
    protected function store(int $spreadsheetId, string $node, array $result): void
    {
        [$tab, $row, $col] = array_map('intval', explode(':', $node));

        $before = DB::table('cells')->where('sheet_tab_id', $tab)->where('row_index', $row)->where('col_index', $col)->first(['spill_rows', 'spill_cols']);
        if (! isset($result['spill']) && $before?->spill_rows === null) {
            // The common case by far: one cell, one answer, nothing spilled before or now.
            DB::table('cells')->where('spreadsheet_id', $spreadsheetId)->where('sheet_tab_id', $tab)->where('row_index', $row)->where('col_index', $col)
                ->update(['computed_value' => $result['value'], 'formula_error' => $result['error']]);

            return;
        }

        $landed = DB::table('cells')->where('sheet_tab_id', $tab)->where('spill_row', $row)->where('spill_col', $col)
            ->orderBy('row_index')->orderBy('col_index')->pluck('computed_value')->all();
        $this->removeSpill($tab, $row, $col);

        $grid = $result['spill'] ?? null;
        $height = $grid === null ? null : count($grid);
        $width = $grid === null ? null : max(array_map('count', $grid));

        if ($grid !== null) {
            $reason = $this->spillRefused($tab, $row, $col, $height, $width);
            if ($reason !== null) {
                $result = ['value' => '#SPILL!', 'error' => $reason];
            } else {
                $this->writeSpill($spreadsheetId, $tab, $row, $col, $grid);
            }
        }

        DB::table('cells')->where('spreadsheet_id', $spreadsheetId)->where('sheet_tab_id', $tab)
            ->where('row_index', $row)->where('col_index', $col)
            ->update(['computed_value' => $result['value'], 'formula_error' => $result['error'], 'spill_rows' => $height, 'spill_cols' => $width]);

        // The same answer again changes nothing for what reads it.
        $landing = $result['value'] === '#SPILL!' || $grid === null ? [] : array_slice(array_merge(...$grid), 1);
        if ($landing === $landed && (int) $before?->spill_rows === (int) $height && (int) $before?->spill_cols === (int) $width) {
            return;
        }

        // What changed: the area before and the area now. Whatever reads it is the next pass.
        foreach ([[$before?->spill_rows, $before?->spill_cols], [$height, $width]] as [$rows, $cols]) {
            if ($rows !== null) {
                $this->spilled[] = [$tab, $row, $row + (int) $rows - 1, $col, $col + (int) $cols - 1];
                $this->spilledFrom[$node] = true;
            }
        }
    }

    protected function spillRefused(int $tab, int $row, int $col, int $height, int $width): ?string
    {
        if ($height * $width > self::MAX_SPILLED_CELLS) {
            return 'The answer is too large to show: more than '.number_format(self::MAX_SPILLED_CELLS).' cells.';
        }
        if ($row + $height > SheetStructureService::GRID_ROWS || $col + $width > SheetStructureService::GRID_COLS) {
            return 'The answer needs more cells than the sheet has.';
        }

        if (SheetMerges::near(SheetMerges::onTab($tab), ['r1' => $row, 'r2' => $row + $height - 1, 'c1' => $col, 'c2' => $col + $width - 1]) !== []) {
            return FormulaEngine::EXPLANATIONS['#SPILL!'];
        }

        $blocked = DB::table('cells')->where('sheet_tab_id', $tab)
            ->whereBetween('row_index', [$row, $row + $height - 1])->whereBetween('col_index', [$col, $col + $width - 1])
            ->where(fn ($q) => $q->where('row_index', '!=', $row)->orWhere('col_index', '!=', $col))
            ->where(fn ($q) => $q->whereNotNull('formula')->orWhere('raw_value', '!=', '')->orWhereNotNull('spill_row'))
            ->exists();

        return $blocked ? FormulaEngine::EXPLANATIONS['#SPILL!'] : null;
    }

    /**
     * @param  list<list<string>>  $grid
     */
    protected function writeSpill(int $spreadsheetId, int $tab, int $row, int $col, array $grid): void
    {
        $rows = [];
        $now = now();
        foreach ($grid as $r => $line) {
            foreach ($line as $c => $value) {
                if ($r === 0 && $c === 0) {
                    continue;
                }

                $this->engine->place($tab, $row + $r, $col + $c, $value);
                $rows[] = ['spreadsheet_id' => $spreadsheetId, 'sheet_tab_id' => $tab, 'row_index' => $row + $r, 'col_index' => $col + $c, 'raw_value' => '', 'computed_value' => $value, 'spill_row' => $row, 'spill_col' => $col, 'updated_at' => $now];
            }
        }

        // A cell that only held formatting keeps it.
        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            DB::table('cells')->upsert($chunk, ['spreadsheet_id', 'sheet_tab_id', 'row_index', 'col_index'], ['computed_value', 'spill_row', 'spill_col', 'updated_at']);
        }
    }

    /**
     * Take a formula's spilled answer off the sheet.
     */
    protected function removeSpill(int $tab, int $row, int $col): void
    {
        $spilled = DB::table('cells')->where('sheet_tab_id', $tab)->where('spill_row', $row)->where('spill_col', $col);

        (clone $spilled)->whereNull('formatting')->delete();
        (clone $spilled)->update(['computed_value' => null, 'spill_row' => null, 'spill_col' => null]);

        // Keep what the pass reads in step with the sheet.
        $rows = DB::table('cells')->where('sheet_tab_id', $tab)->where('row_index', $row)->where('col_index', $col)->first(['spill_rows', 'spill_cols']);
        for ($r = 0; $r < (int) ($rows->spill_rows ?? 0); $r++) {
            for ($c = 0; $c < (int) ($rows->spill_cols ?? 0); $c++) {
                if ($r > 0 || $c > 0) {
                    $this->engine->place($tab, $row + $r, $col + $c, null);
                }
            }
        }
    }

    /**
     * A cell no longer holds a formula: take away the answer it spilled, and say where that was.
     *
     * @return array{0: int, 1: int, 2: int, 3: int, 4: int}|null
     */
    protected function retireSpill(int $tab, int $row, int $col): ?array
    {
        $cell = DB::table('cells')->where('sheet_tab_id', $tab)->where('row_index', $row)->where('col_index', $col)->first(['spill_rows', 'spill_cols']);
        $spilled = DB::table('cells')->where('sheet_tab_id', $tab)->where('spill_row', $row)->where('spill_col', $col);
        $rows = $spilled->max('row_index');
        $cols = $spilled->max('col_index');

        if ($cell?->spill_rows === null && $rows === null) {
            return null;
        }

        $this->removeSpill($tab, $row, $col);
        DB::table('cells')->where('sheet_tab_id', $tab)->where('row_index', $row)->where('col_index', $col)
            ->update(['spill_rows' => null, 'spill_cols' => null]);

        return [$tab, $row, max($row, (int) $rows), $col, max($col, (int) $cols)];
    }

    /**
     * Every spilled answer on a sheet off, before the sheet is worked out from scratch: rows or columns
     * may have moved under them.
     */
    protected function clearSpills(int $sheetTabId): void
    {
        DB::table('cells')->where('sheet_tab_id', $sheetTabId)->whereNotNull('spill_row')->whereNull('formatting')->delete();
        DB::table('cells')->where('sheet_tab_id', $sheetTabId)->whereNotNull('spill_row')->update(['computed_value' => null, 'spill_row' => null, 'spill_col' => null]);
        DB::table('cells')->where('sheet_tab_id', $sheetTabId)->whereNotNull('spill_rows')->update(['spill_rows' => null, 'spill_cols' => null]);
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
