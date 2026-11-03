<?php

namespace App\Services;

use App\Models\Cell;
use App\Services\Formula\FormulaText;

/**
 * Working out what copying or moving a rectangle of cells writes. Formulas read from their new place,
 * and a spilled answer arrives as its value, because the formula that made it stays behind.
 */
class RangeCopy
{
    /** Most cells one copy may write. */
    public const MAX_CELLS = 5000;

    /**
     * Where a copy lands: the source's size, repeated to fill the selection when it divides evenly.
     *
     * @param  array{r1: int, r2: int, c1: int, c2: int}  $area
     * @return array{r1: int, r2: int, c1: int, c2: int}
     */
    public static function target(array $area, int $row, int $col, int $rows, int $cols): array
    {
        $height = $area['r2'] - $area['r1'] + 1;
        $width = $area['c2'] - $area['c1'] + 1;

        return [
            'r1' => $row,
            'r2' => $row + ($rows % $height === 0 ? $rows : $height) - 1,
            'c1' => $col,
            'c2' => $col + ($cols % $width === 0 ? $cols : $width) - 1,
        ];
    }

    /**
     * The edits, sheet by sheet. Moving clears the source, except where it lands on itself.
     *
     * @param  array{r1: int, r2: int, c1: int, c2: int}  $area
     * @param  array{r1: int, r2: int, c1: int, c2: int}  $target
     * @return array<int, list<array{row: int, col: int, value: string, x: array<string, mixed>|null}>>
     */
    public function plan(int $fromTab, array $area, int $toTab, array $target, bool $cut): array
    {
        $source = Cell::where('sheet_tab_id', $fromTab)
            ->whereBetween('row_index', [$area['r1'], $area['r2']])
            ->whereBetween('col_index', [$area['c1'], $area['c2']])
            ->get()->keyBy(fn (Cell $cell) => $cell->row_index.':'.$cell->col_index);

        $height = $area['r2'] - $area['r1'] + 1;
        $width = $area['c2'] - $area['c1'] + 1;

        $edits = [];
        if ($cut) {
            for ($row = $area['r1']; $row <= $area['r2']; $row++) {
                for ($col = $area['c1']; $col <= $area['c2']; $col++) {
                    if ($source->has($row.':'.$col) && $source[$row.':'.$col]->spill_row === null) {
                        $edits[$fromTab][$row.':'.$col] = ['row' => $row, 'col' => $col, 'value' => '', 'x' => null];
                    }
                }
            }
        }

        for ($row = $target['r1']; $row <= $target['r2']; $row++) {
            for ($col = $target['c1']; $col <= $target['c2']; $col++) {
                $fromRow = $area['r1'] + ($row - $target['r1']) % $height;
                $fromCol = $area['c1'] + ($col - $target['c1']) % $width;
                $edits[$toTab][$row.':'.$col] = [
                    'row' => $row,
                    'col' => $col,
                    // A moved formula keeps reading what it read.
                    'value' => $this->contents($source[$fromRow.':'.$fromCol] ?? null, $cut ? 0 : $row - $fromRow, $cut ? 0 : $col - $fromCol),
                    'x' => ($source[$fromRow.':'.$fromCol]->formatting ?? null) ?: null,
                ];
            }
        }

        return array_map(array_values(...), $edits);
    }

    private function contents(?Cell $cell, int $rows, int $cols): string
    {
        if ($cell === null) {
            return '';
        }
        if ($cell->spill_row !== null) {
            return (string) $cell->computed_value;
        }

        return $cell->formula !== null ? FormulaText::moved($cell->formula, $rows, $cols) : (string) $cell->raw_value;
    }
}
