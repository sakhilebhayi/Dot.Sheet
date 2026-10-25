<?php

namespace App\Services;

use App\Models\Cell;
use App\Models\CellHistory;
use App\Models\ChartConfig;
use App\Models\Comment;
use App\Models\NamedRange;
use App\Models\SheetTab;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Sheets imported before the import/export indexes were fixed hold their cells one row and one
 * column off: the editor counts from 0, the importer counted from 1, so the file's A1 sits at
 * B2 and row 1 and column A are empty.
 *
 * Nothing here runs by itself. detect() only reads. repair() is called by an explicit command
 * for one tab at a time, writes a backup of the tab's cells first and moves cell positions only
 * (not formulas: an imported formula already uses the file's own coordinates, so once the cells
 * are back where the file had them the formulas point at the right cells again).
 */
class OffsetImportRepair
{
    /** Most cells one repair will handle: the backup is streamed, but the lift/drop UPDATEs hold the write lock. */
    public const MAX_CELLS = 200000;

    public function __construct(private int $maxCells = self::MAX_CELLS) {}

    /**
     * Tabs that look like an offset import, with how each was decided.
     *
     * @return list<array{tab: SheetTab, cells: int, formulas: int, min_row: int, min_col: int}>
     */
    public function detect(): array
    {
        $found = [];

        $rows = DB::table('cells')
            ->select('sheet_tab_id', DB::raw('min(row_index) as min_row'), DB::raw('min(col_index) as min_col'), DB::raw('count(*) as cells'))
            ->whereNotNull('sheet_tab_id')
            ->groupBy('sheet_tab_id')
            ->havingRaw('min(row_index) = 1 and min(col_index) = 1')
            ->get();

        foreach ($rows as $row) {
            $tab = SheetTab::find($row->sheet_tab_id);
            if ($tab === null || $this->reasonToLeaveAlone($tab) !== null) {
                continue;
            }

            $found[] = [
                'tab' => $tab,
                'cells' => (int) $row->cells,
                'formulas' => Cell::where('sheet_tab_id', $tab->id)->whereNotNull('formula')->count(),
                'min_row' => (int) $row->min_row,
                'min_col' => (int) $row->min_col,
            ];
        }

        return $found;
    }

    /**
     * Why this tab must not be shifted, or null when it matches the offset-import pattern.
     * Anything edited in the editor, or anything that stores coordinates of its own, is left alone.
     */
    public function reasonToLeaveAlone(SheetTab $tab): ?string
    {
        $bounds = DB::table('cells')->where('sheet_tab_id', $tab->id)->selectRaw('min(row_index) as r, min(col_index) as c, count(*) as n')->first();

        if (! $bounds || (int) $bounds->n === 0) {
            return 'the tab has no cells';
        }

        if ((int) $bounds->r !== 1 || (int) $bounds->c !== 1) {
            return 'the first row and column are not both at index 1 (the editor shows a cell in row 1 or column A, or the data starts elsewhere)';
        }

        if ((int) $bounds->n > $this->maxCells) {
            return 'the tab has '.number_format((int) $bounds->n).' cells; this command moves at most '.number_format($this->maxCells).' in one go (it holds the database write lock while it does). Export the sheet as CSV and import it again instead';
        }

        $edited = CellHistory::whereIn('cell_id', Cell::where('sheet_tab_id', $tab->id)->select('id'))->count();
        if ($edited > 0) {
            return "someone edited cells of this tab in the editor ({$edited} history rows); an import leaves none";
        }

        if (Comment::where('sheet_tab_id', $tab->id)->exists()) {
            return 'the tab has comments, which keep their own coordinates';
        }

        if (NamedRange::where('sheet_tab_id', $tab->id)->exists()) {
            return 'the tab has named ranges, which keep their own coordinates';
        }

        if (ChartConfig::where('sheet_tab_id', $tab->id)->exists()) {
            return 'the tab has charts, which keep their own ranges';
        }

        return null;
    }

    /**
     * Move every cell of the tab one row up and one column left, after saving a backup.
     *
     * @return array{backup: string, cells: int, formulas: int}
     *
     * @throws RuntimeException when the tab does not match the pattern, or a formula would not resolve
     */
    public function repair(SheetTab $tab): array
    {
        if (($reason = $this->reasonToLeaveAlone($tab)) !== null) {
            throw new RuntimeException("Tab {$tab->id} is not an offset import: {$reason}.");
        }

        $backup = $this->writeBackup($tab);

        try {
            $result = DB::transaction(function () use ($tab) {
                $query = fn () => Cell::where('sheet_tab_id', $tab->id);
                $lift = (int) max($query()->max('row_index'), $query()->max('col_index')) + 2;

                // Lift clear of every coordinate first: SQLite checks the unique key row by row.
                $query()->update(['row_index' => DB::raw("row_index + {$lift}"), 'col_index' => DB::raw("col_index + {$lift}")]);
                $drop = $lift + 1;
                $query()->update(['row_index' => DB::raw("row_index - {$drop}"), 'col_index' => DB::raw("col_index - {$drop}")]);

                $formulas = $this->assertFormulasResolve($tab);
                StructureVersion::bump($tab->spreadsheet_id);

                return ['cells' => $query()->count(), 'formulas' => $formulas];
            });
        } finally {
            foreach (SheetStructureService::CACHE_KEYS as $key) {
                cache()->forget("spreadsheet:{$tab->spreadsheet_id}:tab:{$tab->id}:{$key}");
            }
        }

        return ['backup' => $backup] + $result;
    }

    /**
     * Every formula must evaluate at its new place, and a single-cell reference must not point beyond
     * the tab's data (a range may run past it: SUM(B2:B50) over ten rows is fine). Otherwise the
     * transaction is rolled back. Reads only the cells the formulas refer to, not the whole tab, and
     * not through the cache.
     */
    protected function assertFormulasResolve(SheetTab $tab): int
    {
        foreach (SheetStructureService::CACHE_KEYS as $key) {
            cache()->forget("spreadsheet:{$tab->spreadsheet_id}:tab:{$tab->id}:{$key}");
        }

        $maxRow = (int) Cell::where('sheet_tab_id', $tab->id)->max('row_index');
        $maxCol = (int) Cell::where('sheet_tab_id', $tab->id)->max('col_index');
        $evaluator = new FormulaEvaluatorService;
        $namedRanges = $evaluator->namedRangeMap($tab->spreadsheet_id, $tab->id);

        $formulas = DB::table('cells')->where('sheet_tab_id', $tab->id)->whereNotNull('formula')->select('row_index', 'col_index', 'formula')->get();

        $needed = [];
        foreach ($formulas as $cell) {
            foreach ($evaluator->referencedKeys($cell->formula, $namedRanges) as $key) {
                $needed[$key] = true;
            }
        }
        $values = [];
        foreach (DB::table('cells')->where('sheet_tab_id', $tab->id)->select('row_index', 'col_index', 'raw_value', 'computed_value')->cursor() as $cell) {
            $key = $cell->row_index.'_'.$cell->col_index;
            if (isset($needed[$key])) {
                $values[$key] = $cell->computed_value ?? $cell->raw_value;
            }
        }

        foreach ($formulas as $cell) {
            $result = $evaluator->evaluateAgainst($cell->formula, $values, $namedRanges);
            // Division by zero is a value in a spreadsheet, not a broken reference.
            if ($result['error'] && ! str_contains(strtolower($result['errorMessage'] ?? ''), 'division by zero')) {
                throw new RuntimeException("Formula {$cell->formula} at row {$cell->row_index}, column {$cell->col_index} does not evaluate after the move; nothing was changed.");
            }

            // Single-cell references only; text inside double quotes is not a reference.
            $withoutText = preg_replace('/"[^"]*"/', '""', $cell->formula) ?? $cell->formula;
            $withoutRanges = preg_replace('/[A-Za-z]{1,3}\d+:[A-Za-z]{1,3}\d+/', '', $withoutText) ?? $withoutText;
            preg_match_all('/(?<![A-Za-z0-9_.])([A-Za-z]{1,3})(\d+)(?![A-Za-z0-9_(])/', $withoutRanges, $refs, PREG_SET_ORDER);
            foreach ($refs as [, $letters, $number]) {
                $col = 0;
                foreach (str_split(strtoupper($letters)) as $char) {
                    $col = $col * 26 + (ord($char) - 64);
                }
                if ((int) $number - 1 > $maxRow || $col - 1 > $maxCol) {
                    throw new RuntimeException("Formula {$cell->formula} refers to {$letters}{$number}, outside the data of the tab after the move; nothing was changed.");
                }
            }
        }

        return $formulas->count();
    }

    /**
     * Save the tab's cells under storage/app/private/backups, before anything is changed, one JSON
     * object per line (a header line first), written with a cursor so a big tab never sits in memory.
     * Returns the path.
     */
    protected function writeBackup(SheetTab $tab): string
    {
        $path = 'backups/offset-imports/tab-'.$tab->id.'-'.now()->format('Ymd-His').'.jsonl';
        Storage::disk('local')->makeDirectory('backups/offset-imports');
        $full = Storage::disk('local')->path($path);

        $handle = fopen($full, 'wb');
        if ($handle === false) {
            throw new RuntimeException('Could not write the backup file, so nothing was changed.');
        }

        try {
            fwrite($handle, json_encode([
                'note' => 'Cells of one tab before sheet:fix-offset-imports moved them one row up and one column left. One JSON object per line after this one. To undo: add 1 to row_index and col_index of every id below.',
                'created_at' => now()->toIso8601String(),
                'spreadsheet_id' => $tab->spreadsheet_id,
                'sheet_tab_id' => $tab->id,
            ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");

            $cells = DB::table('cells')->where('sheet_tab_id', $tab->id)->orderBy('id')
                ->select('id', 'spreadsheet_id', 'sheet_tab_id', 'row_index', 'col_index', 'raw_value', 'computed_value', 'formula', 'formatting', 'updated_at')->cursor();

            foreach ($cells as $cell) {
                if (fwrite($handle, json_encode($cell, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n") === false) {
                    throw new RuntimeException('Could not write the whole backup file (disk full?), so nothing was changed.');
                }
            }
        } finally {
            fclose($handle);
        }

        return $full;
    }
}
