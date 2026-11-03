<?php

namespace App\Services;

use App\Models\Spreadsheet;
use Illuminate\Support\Facades\DB;

/**
 * The picture of a spreadsheet: where its cells are and what its formulas read, taken from the top left
 * of its fullest sheet. It holds kinds of cell, never their values, so it can sit beside a name.
 */
class SheetPrint
{
    public const ROWS = 24;

    public const COLS = 16;

    private const MAX_THREADS = 90;

    /**
     * @return array{rows: int, cols: int, sheet: ?string, cells: list<array{0: int, 1: int, 2: string}>, threads: list<array{0: int, 1: int, 2: int, 3: int}>, more: bool}
     */
    public function of(Spreadsheet $spreadsheet): array
    {
        $fullest = DB::table('cells')->where('spreadsheet_id', $spreadsheet->id)
            ->selectRaw('sheet_tab_id, count(*) as held')->groupBy('sheet_tab_id')->orderByDesc('held')->first();

        if ($fullest === null) {
            return ['rows' => self::ROWS, 'cols' => self::COLS, 'sheet' => null, 'cells' => [], 'threads' => [], 'more' => false];
        }

        $tab = (int) $fullest->sheet_tab_id;

        $cells = DB::table('cells')->where('sheet_tab_id', $tab)
            ->where('row_index', '<', self::ROWS)->where('col_index', '<', self::COLS)
            ->get(['row_index', 'col_index', 'raw_value', 'computed_value', 'formula', 'spill_row'])
            ->map(fn ($cell) => [(int) $cell->row_index, (int) $cell->col_index, $this->kind($cell)])
            ->reject(fn (array $cell) => $cell[2] === '')
            ->values()->all();

        $threads = DB::table('cell_precedents')->where('sheet_tab_id', $tab)->where('precedent_tab_id', $tab)
            ->where('row_index', '<', self::ROWS)->where('col_index', '<', self::COLS)
            ->where('row_from', '<', self::ROWS)->where('col_from', '<', self::COLS)
            ->limit(self::MAX_THREADS)
            ->get(['row_index', 'col_index', 'row_from', 'col_from'])
            ->map(fn ($thread) => [(int) $thread->row_index, (int) $thread->col_index, (int) $thread->row_from, (int) $thread->col_from])
            ->all();

        return [
            'rows' => self::ROWS,
            'cols' => self::COLS,
            'sheet' => DB::table('sheet_tabs')->where('id', $tab)->value('name'),
            'cells' => $cells,
            'threads' => $threads,
            'more' => DB::table('cells')->where('sheet_tab_id', $tab)
                ->where(fn ($query) => $query->where('row_index', '>=', self::ROWS)->orWhere('col_index', '>=', self::COLS))
                ->exists(),
        ];
    }

    /**
     * f a formula, n a number, l a negative number, t text, or nothing for an empty cell.
     */
    private function kind(object $cell): string
    {
        if ($cell->formula !== null) {
            return 'f';
        }

        $value = $cell->spill_row !== null ? $cell->computed_value : $cell->raw_value;
        if ($value === null || $value === '') {
            return '';
        }
        if (! is_numeric($value)) {
            return 't';
        }

        return (float) $value < 0 ? 'l' : 'n';
    }
}
