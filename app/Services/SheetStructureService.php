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

    /** The editor's grid: ShowSpreadsheet::$totalRows and $totalCols. */
    public const GRID_ROWS = 1000000;

    public const GRID_COLS = 100;

    /**
     * Every key FormulaEvaluatorService keeps in the cache for a tab. Forgetting only some of
     * them leaves the evaluator working from the layout before the change (named_ranges had a
     * five minute life, so a fresh =SUM(Sales) was computed over the old range).
     */
    public const CACHE_KEYS = ['cell_values', 'formula_dependency_graph', 'named_ranges'];

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
        $this->refuseWhenDataWouldLeaveTheSheet($spreadsheetId, $sheetTabId, $axis, $index);

        try {
            DB::transaction(function () use ($spreadsheetId, $sheetTabId, $axis, $index) {
                $this->shiftCells($spreadsheetId, $sheetTabId, $axis, $index, 1);
                $resizedNames = $this->shiftNamedRanges($spreadsheetId, $sheetTabId, $axis, $index, 1);
                $this->rewriteFormulas($spreadsheetId, $sheetTabId, $axis, $index, 1, $resizedNames);
                StructureVersion::bump($spreadsheetId);
            });
        } finally {
            // Also after a rollback: the evaluator may have cached the half-done layout.
            $this->forgetCaches($spreadsheetId, $sheetTabId);
        }
    }

    protected function delete(int $spreadsheetId, int $sheetTabId, string $axis, int $index): void
    {
        try {
            DB::transaction(function () use ($spreadsheetId, $sheetTabId, $axis, $index) {
                $this->cells($spreadsheetId, $sheetTabId)->where($axis, $index)->delete();
                $this->shiftCells($spreadsheetId, $sheetTabId, $axis, $index + 1, -1);
                $resizedNames = $this->shiftNamedRanges($spreadsheetId, $sheetTabId, $axis, $index, -1);
                $this->rewriteFormulas($spreadsheetId, $sheetTabId, $axis, $index, -1, $resizedNames);
                StructureVersion::bump($spreadsheetId);
            });
        } finally {
            $this->forgetCaches($spreadsheetId, $sheetTabId);
        }
    }

    /**
     * An insert pushes everything at or after $index one step on. Data that would land beyond
     * the editor's last row or column could never be seen again, so refuse, as Excel does.
     */
    protected function refuseWhenDataWouldLeaveTheSheet(int $spreadsheetId, int $sheetTabId, string $axis, int $index): void
    {
        $limit = $axis === 'row_index' ? self::GRID_ROWS : self::GRID_COLS;
        $max = $this->cells($spreadsheetId, $sheetTabId)->max($axis);

        if ($max === null || (int) $max < $index || (int) $max < $limit - 1) {
            return;
        }

        throw new StructureChangeRefused($axis === 'row_index'
            ? 'Can not insert a row: there is data in the last row of the sheet and it would be pushed off the end. Clear or move it first.'
            : 'Can not insert a column: there is data in the last column of the sheet and it would be pushed off the end. Clear or move it first.');
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

    /**
     * @return list<string> lower-case names of the named ranges that grew, shrank or went away:
     *                      formulas that use them change value although their text does not
     */
    protected function shiftNamedRanges(int $spreadsheetId, int $sheetTabId, string $axis, int $index, int $delta): array
    {
        $startField = $axis === 'row_index' ? 'start_row' : 'start_col';
        $endField = $axis === 'row_index' ? 'end_row' : 'end_col';
        $resized = [];

        NamedRange::where('spreadsheet_id', $spreadsheetId)
            ->where('sheet_tab_id', $sheetTabId)
            ->get()
            ->each(function (NamedRange $range) use ($startField, $endField, $index, $delta, &$resized) {
                $start = (int) $range->{$startField};
                $end = (int) $range->{$endField};
                $bounds = $this->shiftSpan($start, $end, $index, $delta);

                if ($bounds === null) {
                    $resized[] = strtolower($range->name);
                    $range->delete();

                    return;
                }

                if ($end - $start !== $bounds[1] - $bounds[0]) {
                    $resized[] = strtolower($range->name);
                }

                [$range->{$startField}, $range->{$endField}] = $bounds;
                $range->save();
            });

        return $resized;
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

    /**
     * Rewrite every reference first, then recompute only the formulas whose answer can have
     * changed: a range that grew or shrank, a reference that was deleted, or a name that now
     * points somewhere else. A formula that only moved keeps its stored value. (Recomputing
     * each changed formula and clearing the cache after every one took 45 s for 500 formulas.)
     *
     * @param  list<string>  $resizedNames
     */
    protected function rewriteFormulas(int $spreadsheetId, int $sheetTabId, string $axis, int $index, int $delta, array $resizedNames): void
    {
        $recompute = [];

        $this->cells($spreadsheetId, $sheetTabId)
            // Only real formulas. A text cell that starts with "=" (formula column empty) is data.
            ->whereNotNull('formula')
            ->chunkById(500, function ($cells) use ($axis, $index, $delta, $resizedNames, &$recompute) {
                foreach ($cells as $cell) {
                    $updates = [];
                    $answerChanges = false;

                    $original = (string) $cell->formula;
                    [$rewritten, $changesAnswer] = $this->rewriteReferencesDetailed($original, $axis, $index, $delta);
                    $answerChanges = $changesAnswer;
                    if ($rewritten !== $original) {
                        $updates['formula'] = $rewritten;
                        // Imported formulas also keep their text in raw_value.
                        if ($cell->raw_value === $original) {
                            $updates['raw_value'] = $rewritten;
                        }
                    }

                    if ($updates !== []) {
                        $cell->forceFill($updates)->save();
                    }

                    if ($answerChanges || $this->usesAnyName((string) $cell->formula, $resizedNames)) {
                        $recompute[] = $cell;
                    }
                }
            });

        if ($recompute === []) {
            return;
        }

        // One cache clear and one evaluator for the whole pass; the evaluator reads the cells
        // and named ranges once and keeps them.
        $this->forgetCaches($spreadsheetId, $sheetTabId);
        $evaluator = new FormulaEvaluatorService;

        foreach ($recompute as $cell) {
            $text = (string) $cell->formula;

            if (str_contains($text, self::REF_ERROR)) {
                $computed = self::REF_ERROR;
            } else {
                $result = $evaluator->evaluate($text, $spreadsheetId, $sheetTabId);
                $computed = $result['error'] ? '#ERROR' : $result['value'];
            }

            $cell->forceFill(['computed_value' => $computed])->save();
        }
    }

    /**
     * @param  list<string>  $names
     */
    protected function usesAnyName(string $formula, array $names): bool
    {
        foreach ($names as $name) {
            if (preg_match('/(?<![A-Za-z0-9_.])'.preg_quote($name, '/').'(?![A-Za-z0-9_(])/i', $formula) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Rewrite the A1-style references in one formula. Same shape of reference the
     * formula evaluator understands: 1 to 3 letters then digits, optionally as a range.
     */
    public function rewriteReferences(string $formula, string $axis, int $index, int $delta): string
    {
        return $this->rewriteReferencesDetailed($formula, $axis, $index, $delta)[0];
    }

    /**
     * @return array{0: string, 1: bool} the new text, and whether the answer can differ from
     *                                   the stored one (a range changed size or a reference was deleted)
     */
    public function rewriteReferencesDetailed(string $formula, string $axis, int $index, int $delta): array
    {
        $changesAnswer = false;
        $ref = '(?<![A-Za-z0-9_.])([A-Za-z]{1,3})(\d+)(?![A-Za-z0-9_(])';
        // Text inside double quotes is a string, not a reference.
        $pattern = "/\"[^\"]*\"(*SKIP)(*FAIL)|{$ref}(?::([A-Za-z]{1,3})(\\d+)(?![A-Za-z0-9_(]))?/";

        $text = preg_replace_callback($pattern, function (array $m) use ($axis, $index, $delta, &$changesAnswer) {
            $startCol = $this->letterToIndex($m[1]);
            $startRow = (int) $m[2] - 1;
            $isRange = isset($m[3]) && $m[3] !== '';
            $endCol = $isRange ? $this->letterToIndex($m[3]) : $startCol;
            $endRow = $isRange ? (int) $m[4] - 1 : $startRow;

            $lowRowIn = min($startRow, $endRow);
            $highRowIn = max($startRow, $endRow);
            $lowColIn = min($startCol, $endCol);
            $highColIn = max($startCol, $endCol);

            if ($axis === 'row_index') {
                $span = $this->shiftSpan($lowRowIn, $highRowIn, $index, $delta);
                if ($span === null) {
                    $changesAnswer = true;

                    return self::REF_ERROR;
                }
                [$lowRow, $highRow] = $span;
                [$lowCol, $highCol] = [$lowColIn, $highColIn];
                $resized = ($highRow - $lowRow) !== ($highRowIn - $lowRowIn);
            } else {
                $span = $this->shiftSpan($lowColIn, $highColIn, $index, $delta);
                if ($span === null) {
                    $changesAnswer = true;

                    return self::REF_ERROR;
                }
                [$lowCol, $highCol] = $span;
                [$lowRow, $highRow] = [$lowRowIn, $highRowIn];
                $resized = ($highCol - $lowCol) !== ($highColIn - $lowColIn);
            }

            $changesAnswer = $changesAnswer || $resized;

            $first = $this->indexToLetter($lowCol).($lowRow + 1);
            $rewritten = $isRange ? $first.':'.$this->indexToLetter($highCol).($highRow + 1) : $first;

            // Leave a reference that did not move exactly as the user typed it (a1 stays a1).
            return strcasecmp($rewritten, $m[0]) === 0 ? $m[0] : $rewritten;
        }, $formula);

        return [$text ?? $formula, $changesAnswer];
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
        foreach (self::CACHE_KEYS as $key) {
            Cache::forget("spreadsheet:{$spreadsheetId}:tab:{$sheetTabId}:{$key}");
        }
    }
}
