<?php

namespace App\Services;

use App\Models\Cell;
use App\Models\SheetTab;
use App\Models\Spreadsheet;
use App\Services\Formula\Recalculator;

/**
 * A sheet laid out for paper: rows of what the cells show, leaving out what is hidden and folding merged
 * cells together. Print and PDF both draw this.
 */
class SheetPage
{
    /**
     * @return array{rows: list<list<array{text: string, x: array<string, mixed>|null, colspan: int, rowspan: int}>>, too_large: bool}
     */
    public function of(Spreadsheet $spreadsheet, SheetTab $tab): array
    {
        app(Recalculator::class)->ensureBuilt($spreadsheet->id, $tab->id);

        $limit = (int) config('sheet.page_max_cells');
        $query = Cell::where('sheet_tab_id', $tab->id);
        if ($limit > 0 && (clone $query)->count() > $limit) {
            return ['rows' => [], 'too_large' => true];
        }

        $held = [];
        foreach ((clone $query)->get(['row_index', 'col_index', 'raw_value', 'computed_value', 'formula', 'formatting']) as $cell) {
            $value = $cell->formula === null ? ($cell->computed_value ?? $cell->raw_value) : $cell->computed_value;
            $held[$cell->row_index][$cell->col_index] = ['text' => CellDisplay::text($value, $cell->formatting), 'x' => is_array($cell->formatting) ? $cell->formatting : null];
        }
        if ($held === []) {
            return ['rows' => [], 'too_large' => false];
        }

        $layout = is_array($tab->layout) ? $tab->layout : [];
        $hiddenRows = array_flip(array_map('intval', $layout['hidden_rows'] ?? []));
        $hiddenCols = array_flip(array_map('intval', $layout['hidden_cols'] ?? []));
        $lastRow = max(array_keys($held));
        $lastCol = max(array_map(fn (array $row) => max(array_keys($row)), $held));

        $starts = [];
        $covered = [];
        foreach (SheetMerges::of($layout) as $merge) {
            $starts[$merge['r1'].':'.$merge['c1']] = $merge;
            for ($r = $merge['r1']; $r <= $merge['r2']; $r++) {
                for ($c = $merge['c1']; $c <= $merge['c2']; $c++) {
                    if ($r !== $merge['r1'] || $c !== $merge['c1']) {
                        $covered["{$r}:{$c}"] = true;
                    }
                }
            }
        }

        $rows = [];
        for ($r = 0; $r <= $lastRow; $r++) {
            if (isset($hiddenRows[$r])) {
                continue;
            }
            $row = [];
            for ($c = 0; $c <= $lastCol; $c++) {
                if (isset($hiddenCols[$c]) || isset($covered["{$r}:{$c}"])) {
                    continue;
                }
                $merge = $starts["{$r}:{$c}"] ?? null;
                $row[] = ($held[$r][$c] ?? ['text' => '', 'x' => null]) + [
                    'colspan' => $merge === null ? 1 : $merge['c2'] - $merge['c1'] + 1,
                    'rowspan' => $merge === null ? 1 : $merge['r2'] - $merge['r1'] + 1,
                ];
            }
            $rows[] = $row;
        }

        return ['rows' => $rows, 'too_large' => false];
    }
}
