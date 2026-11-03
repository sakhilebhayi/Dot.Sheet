<?php

namespace App\Services;

use App\Models\Cell;
use App\Models\CellHistory;
use App\Models\SheetTab;
use App\Services\Formula\Recalculator;

/**
 * Writing people's edits to a sheet: validation, the cell, its history and everything that depends on
 * it. Call inside the write transaction.
 */
class CellWriter
{
    public function __construct(private Recalculator $recalculator) {}

    /**
     * @param  list<array{row: int, col: int, value: string}>  $edits
     * @return array{refused: list<array{row: int, col: int, message: string}>, recalculated: list<string>}
     */
    public function write(int $spreadsheetId, int $sheetTabId, array $edits, int $userId): array
    {
        $layout = SheetTab::whereKey($sheetTabId)->value('layout');
        $rules = is_array($layout['validation_rules'] ?? null) ? $layout['validation_rules'] : [];

        $refused = [];
        $written = [];
        foreach ($edits as ['row' => $row, 'col' => $col, 'value' => $value]) {
            $reason = $value === '' ? null : CellRules::refuse($rules, $row, $col, $value);
            if ($reason !== null) {
                $refused[] = ['row' => $row, 'col' => $col, 'message' => $reason];

                continue;
            }

            $this->writeOne($spreadsheetId, $sheetTabId, $row, $col, $value, $userId);
            $written[] = [$row, $col];
        }

        return [
            'refused' => $refused,
            'recalculated' => $written === [] ? [] : $this->recalculator->cellsWritten($spreadsheetId, $sheetTabId, $written),
        ];
    }

    /**
     * Change formatting keys on a rectangle of cells, creating the cells that do not exist yet.
     *
     * @param  array{r1: int, r2: int, c1: int, c2: int}  $area
     * @param  array<string, mixed>  $patch
     */
    public function format(int $spreadsheetId, int $sheetTabId, array $area, array $patch): void
    {
        for ($row = $area['r1']; $row <= $area['r2']; $row++) {
            for ($col = $area['c1']; $col <= $area['c2']; $col++) {
                $cell = Cell::firstOrNew(['spreadsheet_id' => $spreadsheetId, 'sheet_tab_id' => $sheetTabId, 'row_index' => $row, 'col_index' => $col]);
                $cell->formatting = array_replace(is_array($cell->formatting) ? $cell->formatting : [], $patch);
                $cell->updated_at = now();
                $cell->save();
            }
        }
    }

    private function writeOne(int $spreadsheetId, int $sheetTabId, int $row, int $col, string $value, int $userId): void
    {
        $cell = Cell::where('spreadsheet_id', $spreadsheetId)->where('sheet_tab_id', $sheetTabId)
            ->where('row_index', $row)->where('col_index', $col)->first();

        $before = $cell === null ? null : ($cell->formula ?? $cell->raw_value);

        if ($value === '' && $cell !== null && $cell->spill_row !== null) {
            return;   // a spilled answer cannot be cleared: the formula holds it
        }

        if ($value === '' && $cell !== null && empty($cell->formatting)) {
            $cell->delete();

            return;
        }

        $isFormula = str_starts_with($value, '=') && strlen($value) > 1;
        $attributes = [
            'raw_value' => $isFormula ? '' : $value,
            'computed_value' => null,
            'formula' => $isFormula ? $value : null,
            'updated_at' => now(),
        ];

        if ($cell === null) {
            if ($value === '') {
                return;
            }
            $cell = Cell::create(['spreadsheet_id' => $spreadsheetId, 'sheet_tab_id' => $sheetTabId, 'row_index' => $row, 'col_index' => $col, ...$attributes]);
        } else {
            $cell->update($attributes);
        }

        CellHistory::create(['cell_id' => $cell->id, 'user_id' => $userId, 'old_value' => $before, 'new_value' => $value, 'created_at' => now()]);
    }
}
