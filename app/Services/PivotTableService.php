<?php

namespace App\Services;

use App\Models\Cell;
use App\Models\PivotTable;
use Illuminate\Support\Collection;

/**
 * Computes a pivot table's result live from Cell data every time it's
 * requested -- PivotTable only stores the *configuration* (source range,
 * which column feeds rows/columns/values, aggregation), never the result
 * itself, so a saved pivot always reflects current data.
 *
 * Uses the same 0-based row/col-index and A1-reference conventions as
 * FormulaEvaluatorService/NamedRange/ShowSpreadsheet.
 */
class PivotTableService
{
    /**
     * @return array{
     *   rowLabels: list<string>,
     *   columnLabels: list<string>,
     *   matrix: array<string, array<string, float>>,
     *   rowTotals: array<string, float>,
     *   columnTotals: array<string, float>,
     *   grandTotal: float,
     * }
     */
    public function compute(PivotTable $pivot): array
    {
        $bounds = $this->parseRange($pivot->source_range);
        if (! $bounds) {
            return $this->emptyResult();
        }

        [$startRow, $startCol, $endRow, $endCol] = $bounds;

        // First row of the range is the header row; everything below it is data.
        $headerRow = $startRow;
        $dataStartRow = $startRow + 1;

        if ($dataStartRow > $endRow) {
            return $this->emptyResult();
        }

        $rowFieldCol = $this->colLetterToIndex($pivot->row_field);
        $columnFieldCol = $pivot->column_field ? $this->colLetterToIndex($pivot->column_field) : null;
        $valueFieldCol = $this->colLetterToIndex($pivot->value_field);

        $cells = Cell::where('spreadsheet_id', $pivot->spreadsheet_id)
            ->where('sheet_tab_id', $pivot->sheet_tab_id)
            ->whereBetween('row_index', [$dataStartRow, $endRow])
            ->whereBetween('col_index', [$startCol, $endCol])
            ->get(['row_index', 'col_index', 'raw_value', 'computed_value'])
            ->groupBy('row_index');

        // [rowLabel][columnLabel] => list of numeric values to aggregate
        $buckets = [];
        $rowLabels = [];
        $columnLabels = [];

        foreach ($cells as $rowIndex => $rowCells) {
            $rowValue = $this->cellDisplayValue($rowCells, $rowFieldCol);
            if ($rowValue === null || $rowValue === '') {
                continue;
            }

            $columnValue = $columnFieldCol !== null
                ? ($this->cellDisplayValue($rowCells, $columnFieldCol) ?? '(blank)')
                : 'Value';

            $rawValue = $this->cellDisplayValue($rowCells, $valueFieldCol);
            $numericValue = is_numeric($rawValue) ? (float) $rawValue : null;

            $rowLabels[$rowValue] = true;
            $columnLabels[$columnValue] = true;

            $buckets[$rowValue][$columnValue][] = $numericValue;
        }

        $rowLabels = array_keys($rowLabels);
        sort($rowLabels);
        $columnLabels = array_keys($columnLabels);
        sort($columnLabels);

        $matrix = [];
        $rowTotals = [];
        $columnTotals = array_fill_keys($columnLabels, 0.0);
        $grandTotal = 0.0;

        foreach ($rowLabels as $rowLabel) {
            $rowTotal = 0.0;

            foreach ($columnLabels as $columnLabel) {
                $values = $buckets[$rowLabel][$columnLabel] ?? [];
                $aggregated = $this->aggregate($values, $pivot->aggregation);

                $matrix[$rowLabel][$columnLabel] = $aggregated;
                $rowTotal += $aggregated;
                $columnTotals[$columnLabel] += $aggregated;
            }

            $rowTotals[$rowLabel] = $rowTotal;
            $grandTotal += $rowTotal;
        }

        return [
            'rowLabels' => $rowLabels,
            'columnLabels' => $columnLabels,
            'matrix' => $matrix,
            'rowTotals' => $rowTotals,
            'columnTotals' => $columnTotals,
            'grandTotal' => $grandTotal,
        ];
    }

    private function aggregate(array $rawValues, string $aggregation): float
    {
        $numeric = array_values(array_filter($rawValues, fn ($v) => $v !== null));

        if ($aggregation === 'count') {
            // COUNT counts populated cells in the bucket, numeric or not.
            return (float) count($rawValues);
        }

        if (empty($numeric)) {
            return 0.0;
        }

        return match ($aggregation) {
            'average' => array_sum($numeric) / count($numeric),
            'min' => min($numeric),
            'max' => max($numeric),
            default => array_sum($numeric), // sum
        };
    }

    private function cellDisplayValue(Collection $rowCells, int $colIndex): ?string
    {
        $cell = $rowCells->firstWhere('col_index', $colIndex);
        if (! $cell) {
            return null;
        }

        $value = $cell->computed_value ?? $cell->raw_value;

        return $value === null ? null : (string) $value;
    }

    /**
     * @return array{0:int,1:int,2:int,3:int}|null [startRow, startCol, endRow, endCol], 0-based
     */
    private function parseRange(string $range): ?array
    {
        if (! preg_match('/^([A-Z]{1,3})(\d+):([A-Z]{1,3})(\d+)$/i', strtoupper(trim($range)), $m)) {
            return null;
        }

        $startCol = $this->colLetterToIndex($m[1]);
        $startRow = ((int) $m[2]) - 1;
        $endCol = $this->colLetterToIndex($m[3]);
        $endRow = ((int) $m[4]) - 1;

        return [
            min($startRow, $endRow),
            min($startCol, $endCol),
            max($startRow, $endRow),
            max($startCol, $endCol),
        ];
    }

    private function colLetterToIndex(string $letter): int
    {
        $index = 0;
        foreach (str_split(strtoupper($letter)) as $ch) {
            $index = $index * 26 + (ord($ch) - 64);
        }

        return $index - 1;
    }

    private function emptyResult(): array
    {
        return [
            'rowLabels' => [],
            'columnLabels' => [],
            'matrix' => [],
            'rowTotals' => [],
            'columnTotals' => [],
            'grandTotal' => 0.0,
        ];
    }
}
