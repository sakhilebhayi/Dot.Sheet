<?php

namespace App\Services;

use App\Models\Cell;
use App\Models\NamedRange;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Inserting and deleting whole rows and columns of one sheet tab.
 *
 * Cells carry a unique key on (spreadsheet_id, sheet_tab_id, row_index, col_index). A
 * single "UPDATE ... SET row_index = row_index + 1" is only safe if the engine applies it
 * in the right order; SQLite (production) checks the key row by row in whatever order it
 * visits them, so the first cell that lands on a neighbour's coordinate aborts the whole
 * statement. The shift therefore goes in two steps: lift the affected cells clear of every
 * existing coordinate, then drop them back at their final place. The same code runs on
 * MySQL and PostgreSQL.
 *
 * References in formulas and named ranges of the same tab follow the move, like in
 * Excel and Google Sheets: a reference to a deleted row or column becomes #REF!, a range
 * shrinks or grows with the rows inside it.
 */
class SheetStructureService
{
    public const REF_ERROR = '#REF!';

    public function insertRow(int $spreadsheetId, int $sheetTabId, int $row): void
    {
        $this->insert($spreadsheetId, $sheetTabId, 'row_index', $row);
    }

    public function deleteRow(int $spreadsheetId, int $sheetTabId, int $row): void
    {
        $this->delete($spreadsheetId, $sheetTabId, 'row_index', $row);
    }

    public function insertColumn(int $spreadsheetId, int $sheetTabId, int $col): void
    {
        $this->insert($spreadsheetId, $sheetTabId, 'col_index', $col);
    }

    public function deleteColumn(int $spreadsheetId, int $sheetTabId, int $col): void
    {
        $this->delete($spreadsheetId, $sheetTabId, 'col_index', $col);
    }

    protected function insert(int $spreadsheetId, int $sheetTabId, string $axis, int $index): void
    {
        DB::transaction(function () use ($spreadsheetId, $sheetTabId, $axis, $index) {
            $this->shiftCells($spreadsheetId, $sheetTabId, $axis, $index, 1);
            $this->shiftNamedRanges($spreadsheetId, $sheetTabId, $axis, $index, 1);
            $this->rewriteFormulas($spreadsheetId, $sheetTabId, $axis, $index, 1);
        });

        $this->forgetCaches($spreadsheetId, $sheetTabId);
    }

    protected function delete(int $spreadsheetId, int $sheetTabId, string $axis, int $index): void
    {
        DB::transaction(function () use ($spreadsheetId, $sheetTabId, $axis, $index) {
            $this->cells($spreadsheetId, $sheetTabId)->where($axis, $index)->delete();
            $this->shiftCells($spreadsheetId, $sheetTabId, $axis, $index + 1, -1);
            $this->shiftNamedRanges($spreadsheetId, $sheetTabId, $axis, $index, -1);
            $this->rewriteFormulas($spreadsheetId, $sheetTabId, $axis, $index, -1);
        });

        $this->forgetCaches($spreadsheetId, $sheetTabId);
    }

    protected function cells(int $spreadsheetId, int $sheetTabId)
    {
        return Cell::where('spreadsheet_id', $spreadsheetId)->where('sheet_tab_id', $sheetTabId);
    }

    /**
     * Move every cell whose $axis index is >= $from by $delta (+1 or -1).
     */
    protected function shiftCells(int $spreadsheetId, int $sheetTabId, string $axis, int $from, int $delta): void
    {
        $max = $this->cells($spreadsheetId, $sheetTabId)->max($axis);
        if ($max === null || (int) $max < $from) {
            return;
        }

        // Everything moved lands above the highest existing coordinate, so it cannot meet
        // a cell that has not been moved yet, nor another moved cell.
        $lift = (int) $max + 2;

        $this->cells($spreadsheetId, $sheetTabId)->where($axis, '>=', $from)->update([$axis => DB::raw("{$axis} + {$lift}")]);

        $drop = $lift - $delta;
        $this->cells($spreadsheetId, $sheetTabId)->where($axis, '>=', $from + $lift)->update([$axis => DB::raw("{$axis} - {$drop}")]);
    }

    protected function shiftNamedRanges(int $spreadsheetId, int $sheetTabId, string $axis, int $index, int $delta): void
    {
        $startField = $axis === 'row_index' ? 'start_row' : 'start_col';
        $endField = $axis === 'row_index' ? 'end_row' : 'end_col';

        NamedRange::where('spreadsheet_id', $spreadsheetId)
            ->where('sheet_tab_id', $sheetTabId)
            ->get()
            ->each(function (NamedRange $range) use ($startField, $endField, $index, $delta) {
                $bounds = $this->shiftSpan((int) $range->{$startField}, (int) $range->{$endField}, $index, $delta);

                if ($bounds === null) {
                    $range->delete();

                    return;
                }

                [$range->{$startField}, $range->{$endField}] = $bounds;
                $range->save();
            });
    }

    /**
     * Where a span of rows/columns [start, end] ends up after inserting (+1) or deleting (-1)
     * the row/column at $index. Null when the span is gone.
     *
     * @return array{0: int, 1: int}|null
     */
    protected function shiftSpan(int $start, int $end, int $index, int $delta): ?array
    {
        if ($delta > 0) {
            return [$start >= $index ? $start + 1 : $start, $end >= $index ? $end + 1 : $end];
        }

        if ($start === $end && $start === $index) {
            return null;
        }

        return [$start > $index ? $start - 1 : $start, $end >= $index ? $end - 1 : $end];
    }

    protected function rewriteFormulas(int $spreadsheetId, int $sheetTabId, string $axis, int $index, int $delta): void
    {
        $changed = [];

        $this->cells($spreadsheetId, $sheetTabId)
            ->where(fn ($q) => $q->whereNotNull('formula')->orWhere('raw_value', 'like', '=%'))
            ->get()
            ->each(function (Cell $cell) use ($axis, $index, $delta, &$changed) {
                $updates = [];

                foreach (['formula', 'raw_value'] as $field) {
                    $text = $cell->{$field};
                    if (! is_string($text) || ! str_starts_with($text, '=')) {
                        continue;
                    }

                    $rewritten = $this->rewriteReferences($text, $axis, $index, $delta);
                    if ($rewritten !== $text) {
                        $updates[$field] = $rewritten;
                    }
                }

                if ($updates === []) {
                    return;
                }

                $cell->forceFill($updates)->save();
                $changed[] = $cell;
            });

        if ($changed === []) {
            return;
        }

        // Moving a reference does not change what it points at, but a range that grew or
        // shrank, or a reference that was deleted, does change the answer.
        $this->forgetCaches($spreadsheetId, $sheetTabId);
        $evaluator = new FormulaEvaluatorService;

        foreach ($changed as $cell) {
            $text = (string) ($cell->formula ?? $cell->raw_value);

            if (str_contains($text, self::REF_ERROR)) {
                $computed = self::REF_ERROR;
            } else {
                $result = $evaluator->evaluate($text, $spreadsheetId, $sheetTabId);
                $computed = $result['error'] ? '#ERROR' : $result['value'];
            }

            $cell->forceFill(['computed_value' => $computed])->save();
            $this->forgetCaches($spreadsheetId, $sheetTabId);
        }
    }

    /**
     * Rewrite the A1-style references in one formula. Same shape of reference the
     * formula evaluator understands: 1 to 3 letters then digits, optionally as a range.
     */
    public function rewriteReferences(string $formula, string $axis, int $index, int $delta): string
    {
        $ref = '(?<![A-Za-z0-9_.])([A-Za-z]{1,3})(\d+)(?![A-Za-z0-9_(])';
        // Text inside double quotes is a string, not a reference.
        $pattern = "/\"[^\"]*\"(*SKIP)(*FAIL)|{$ref}(?::([A-Za-z]{1,3})(\\d+)(?![A-Za-z0-9_(]))?/";

        return preg_replace_callback($pattern, function (array $m) use ($axis, $index, $delta) {
            $startCol = $this->letterToIndex($m[1]);
            $startRow = (int) $m[2] - 1;
            $isRange = isset($m[3]) && $m[3] !== '';
            $endCol = $isRange ? $this->letterToIndex($m[3]) : $startCol;
            $endRow = $isRange ? (int) $m[4] - 1 : $startRow;

            if ($axis === 'row_index') {
                $span = $this->shiftSpan(min($startRow, $endRow), max($startRow, $endRow), $index, $delta);
                if ($span === null) {
                    return self::REF_ERROR;
                }
                [$lowRow, $highRow] = $span;
                [$lowCol, $highCol] = [min($startCol, $endCol), max($startCol, $endCol)];
            } else {
                $span = $this->shiftSpan(min($startCol, $endCol), max($startCol, $endCol), $index, $delta);
                if ($span === null) {
                    return self::REF_ERROR;
                }
                [$lowCol, $highCol] = $span;
                [$lowRow, $highRow] = [min($startRow, $endRow), max($startRow, $endRow)];
            }

            $first = $this->indexToLetter($lowCol).($lowRow + 1);
            $rewritten = $isRange ? $first.':'.$this->indexToLetter($highCol).($highRow + 1) : $first;

            // Leave a reference that did not move exactly as the user typed it (a1 stays a1).
            return strcasecmp($rewritten, $m[0]) === 0 ? $m[0] : $rewritten;
        }, $formula) ?? $formula;
    }

    protected function letterToIndex(string $letters): int
    {
        $index = 0;
        foreach (str_split(strtoupper($letters)) as $char) {
            $index = $index * 26 + (ord($char) - 64);
        }

        return $index - 1;
    }

    protected function indexToLetter(int $index): string
    {
        $letters = '';
        for ($n = $index + 1; $n > 0; $n = intdiv($n - 1, 26)) {
            $letters = chr(65 + (($n - 1) % 26)).$letters;
        }

        return $letters;
    }

    protected function forgetCaches(int $spreadsheetId, int $sheetTabId): void
    {
        Cache::forget("spreadsheet:{$spreadsheetId}:tab:{$sheetTabId}:cell_values");
        Cache::forget("spreadsheet:{$spreadsheetId}:tab:{$sheetTabId}:formula_dependency_graph");
    }
}
