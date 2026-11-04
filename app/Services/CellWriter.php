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
    /** Most cells worked out one by one in the write; past it, the whole sheet is worked out after. */
    private const MAX_WORKED_OUT_INLINE = 200;

    private const COVERED_MESSAGE = 'That cell is part of a merged cell. Its contents go in the first cell.';

    public function __construct(private Recalculator $recalculator) {}

    /**
     * @param  list<array{row: int, col: int, value: string, x?: array<string, mixed>|null}>  $edits  x replaces the cell's formatting; left out, it stays
     * @return array{refused: list<array{row: int, col: int, message: string}>, recalculated: list<string>, undo: list<array{row: int, col: int, value: string, x: array<string, mixed>|null}>, stale: bool}
     */
    public function write(int $spreadsheetId, int $sheetTabId, array $edits, int $userId): array
    {
        $layout = SheetTab::whereKey($sheetTabId)->value('layout');
        $rules = is_array($layout['validation_rules'] ?? null) ? $layout['validation_rules'] : [];
        $cells = $this->cellsAt($sheetTabId, $edits);
        $merges = $edits === [] ? [] : SheetMerges::near(SheetMerges::of($layout), [
            'r1' => min(array_column($edits, 'row')), 'r2' => max(array_column($edits, 'row')),
            'c1' => min(array_column($edits, 'col')), 'c2' => max(array_column($edits, 'col')),
        ]);

        $refused = [];
        $written = [];
        $undo = [];
        foreach ($edits as $edit) {
            ['row' => $row, 'col' => $col, 'value' => $value] = $edit;
            $covered = SheetMerges::covers($merges, $row, $col);
            if ($covered) {
                $edit['x'] = null;   // nothing shows here, so nothing is kept here
            }
            $reason = $value === '' ? null : ($covered ? self::COVERED_MESSAGE : CellRules::refuse($rules, $row, $col, $value));
            if ($reason !== null) {
                $refused[] = ['row' => $row, 'col' => $col, 'message' => $reason];

                continue;
            }

            $cell = $cells[$row.':'.$col] ?? null;
            $undo[] = $this->asFound($row, $col, $cell);
            $this->writeOne($spreadsheetId, $sheetTabId, $cell, $edit, $userId);
            $written[] = [$row, $col];
        }

        // Past this many cells, the sheet is worked out once as a whole, after the write has committed.
        $stale = count($written) > self::MAX_WORKED_OUT_INLINE;
        if ($stale) {
            $this->recalculator->markStale($spreadsheetId, $sheetTabId);
        }

        return [
            'refused' => $refused,
            'recalculated' => $written === [] || $stale ? [] : $this->recalculator->cellsWritten($spreadsheetId, $sheetTabId, $written),
            'undo' => $undo,
            'stale' => $stale,
        ];
    }

    /**
     * Change formatting keys on a rectangle of cells, creating the cells that do not exist yet.
     *
     * @param  array{r1: int, r2: int, c1: int, c2: int}  $area
     * @param  array<string, mixed>  $patch
     * @return list<array{row: int, col: int, value: string, x: array<string, mixed>|null}> what to write back to undo it
     */
    public function format(int $spreadsheetId, int $sheetTabId, array $area, array $patch): array
    {
        $undo = [];
        $merges = SheetMerges::near(SheetMerges::onTab($sheetTabId), $area);
        for ($row = $area['r1']; $row <= $area['r2']; $row++) {
            for ($col = $area['c1']; $col <= $area['c2']; $col++) {
                if (SheetMerges::covers($merges, $row, $col)) {
                    continue;
                }
                $cell = Cell::firstOrNew(['spreadsheet_id' => $spreadsheetId, 'sheet_tab_id' => $sheetTabId, 'row_index' => $row, 'col_index' => $col]);
                $undo[] = $this->asFound($row, $col, $cell->exists ? $cell : null);
                $cell->formatting = array_replace(is_array($cell->formatting) ? $cell->formatting : [], $patch);
                $cell->updated_at = now();
                $cell->save();
            }
        }

        return $undo;
    }

    /**
     * @param  list<array{row: int, col: int}>  $edits
     * @return array<string, Cell> "row:col" => the cell
     */
    private function cellsAt(int $sheetTabId, array $edits): array
    {
        if ($edits === []) {
            return [];
        }

        $rows = array_column($edits, 'row');
        $cols = array_column($edits, 'col');

        return Cell::where('sheet_tab_id', $sheetTabId)
            ->whereBetween('row_index', [min($rows), max($rows)])
            ->whereBetween('col_index', [min($cols), max($cols)])
            ->get()->keyBy(fn (Cell $cell) => $cell->row_index.':'.$cell->col_index)->all();
    }

    /**
     * The edit that puts a cell back as it is now. A spilled answer has no contents of its own.
     *
     * @return array{row: int, col: int, value: string, x: array<string, mixed>|null}
     */
    private function asFound(int $row, int $col, ?Cell $cell): array
    {
        $contents = $cell === null || $cell->spill_row !== null ? '' : (string) ($cell->formula ?? $cell->raw_value);

        return ['row' => $row, 'col' => $col, 'value' => $contents, 'x' => ($cell->formatting ?? null) ?: null];
    }

    /**
     * @param  array{row: int, col: int, value: string, x?: array<string, mixed>|null}  $edit
     */
    private function writeOne(int $spreadsheetId, int $sheetTabId, ?Cell $cell, array $edit, int $userId): void
    {
        ['row' => $row, 'col' => $col, 'value' => $value] = $edit;
        $restyled = array_key_exists('x', $edit);
        $formatting = $restyled ? $edit['x'] : $cell?->formatting;

        if ($value === '' && $cell !== null && $cell->spill_row !== null) {
            return;   // a spilled answer cannot be cleared: the formula holds it
        }

        if ($value === '' && empty($formatting)) {
            $cell?->delete();

            return;
        }

        $before = $cell === null ? null : ($cell->formula ?? $cell->raw_value);
        $isFormula = str_starts_with($value, '=') && strlen($value) > 1;
        $attributes = [
            'raw_value' => $isFormula ? '' : $value,
            'computed_value' => null,
            'formula' => $isFormula ? $value : null,
            'updated_at' => now(),
            ...($restyled ? ['formatting' => $formatting] : []),
        ];

        if ($cell === null) {
            $cell = Cell::create(['spreadsheet_id' => $spreadsheetId, 'sheet_tab_id' => $sheetTabId, 'row_index' => $row, 'col_index' => $col, ...$attributes]);
        } else {
            $cell->update($attributes);
        }

        if ((string) $before !== $value) {
            CellHistory::create(['cell_id' => $cell->id, 'user_id' => $userId, 'old_value' => $before, 'new_value' => $value, 'created_at' => now()]);
        }
    }
}
