<?php

namespace App\Services;

use App\Models\Cell;
use App\Models\NamedRange;
use App\Models\SheetTab;
use App\Models\Spreadsheet;
use Illuminate\Database\QueryException;
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
     * The change holds the single SQLite write lock (BEGIN IMMEDIATE) from start to commit, and every
     * other writer (sessions, cache, other editors) waits behind it for at most busy_timeout. So the
     * limit is on time, not on size: a work budget estimated before the transaction starts and added to
     * while it runs, and a clock check inside it. Past either the change is rolled back whole and refused
     * with a plain sentence. Weights are microseconds measured on a development laptop with SQLite WAL,
     * IMMEDIATE, CACHE_STORE=database (see the table in the pull request); the budget keeps the whole
     * transaction under about two seconds there.
     */
    public const TIME_LIMIT_SECONDS = 2.0;

    public const WORK_BUDGET = 1800000;

    public const COST_CELL_MOVED = 2.5;

    public const COST_FORMULA_REWRITTEN = 22.0;

    public const COST_CELL_SCANNED = 0.5;

    public const COST_FORMULA_RECOMPUTED = 20.0;

    public const COST_REFERENCE_READ = 0.2;

    public const COST_CELL_LOADED = 1.0;

    /** Cells the recomputed formulas read; they are held in memory while recomputing. */
    public const MAX_REFERENCED_CELLS = 200000;

    /** Import/paste batches older than this are not treated as still running. */
    public const BATCH_STALE_AFTER_SECONDS = 3600;

    public const TOO_LARGE_MESSAGE = 'This sheet is too large to insert or delete rows or columns here. Export it as CSV, make the change, and import it again.';

    public const DISABLED_MESSAGE = 'Inserting and deleting rows and columns is not available yet.';

    public const IMPORT_RUNNING_MESSAGE = 'An import is running for this sheet; try again when it finishes.';

    public const PASTE_RUNNING_MESSAGE = 'A paste is running for this sheet; try again when it finishes.';

    public const BUSY_MESSAGE = WriteLock::BUSY_MESSAGE;

    public const CACHE_KEYS = ['cell_values', 'formula_dependency_graph', 'named_ranges'];

    private float $workBudget = self::WORK_BUDGET;

    private float $timeLimit = self::TIME_LIMIT_SECONDS;

    private float $deadline = 0.0;

    private float $work = 0.0;

    /**
     * A copy with other limits, for tests.
     */
    public function withBudget(float $workBudget, float $timeLimitSeconds): static
    {
        $copy = clone $this;
        $copy->workBudget = $workBudget;
        $copy->timeLimit = $timeLimitSeconds;

        return $copy;
    }

    public function insertRow(int $spreadsheetId, int $sheetTabId, int $row, ?int $expectedVersion = null): void
    {
        $this->insert($spreadsheetId, $sheetTabId, 'row_index', $row, $expectedVersion);
    }

    public function deleteRow(int $spreadsheetId, int $sheetTabId, int $row, ?int $expectedVersion = null): void
    {
        $this->delete($spreadsheetId, $sheetTabId, 'row_index', $row, $expectedVersion);
    }

    public function insertColumn(int $spreadsheetId, int $sheetTabId, int $col, ?int $expectedVersion = null): void
    {
        $this->insert($spreadsheetId, $sheetTabId, 'col_index', $col, $expectedVersion);
    }

    public function deleteColumn(int $spreadsheetId, int $sheetTabId, int $col, ?int $expectedVersion = null): void
    {
        $this->delete($spreadsheetId, $sheetTabId, 'col_index', $col, $expectedVersion);
    }

    protected function insert(int $spreadsheetId, int $sheetTabId, string $axis, int $index, ?int $expectedVersion = null): void
    {
        $this->refuseWhenSwitchedOff();
        $this->refuseWhenABatchIsRunning($spreadsheetId);
        $this->refuseWhenTooLarge($spreadsheetId, $sheetTabId, $axis, $index);
        $this->refuseWhenDataWouldLeaveTheSheet($spreadsheetId, $sheetTabId, $axis, $index);

        $this->inShortTransaction($spreadsheetId, $sheetTabId, $expectedVersion, function () use ($spreadsheetId, $sheetTabId, $axis, $index) {
            $this->shiftCells($spreadsheetId, $sheetTabId, $axis, $index, 1);
            $this->checkClock();
            $resizedNames = $this->shiftNamedRanges($spreadsheetId, $sheetTabId, $axis, $index, 1);
            $this->shiftLayoutSettings($spreadsheetId, $axis, $index, 1);
            $this->rewriteFormulas($spreadsheetId, $sheetTabId, $axis, $index, 1, $resizedNames);
            StructureVersion::bump($spreadsheetId);
        });
    }

    protected function delete(int $spreadsheetId, int $sheetTabId, string $axis, int $index, ?int $expectedVersion = null): void
    {
        $this->refuseWhenSwitchedOff();
        $this->refuseWhenABatchIsRunning($spreadsheetId);
        $this->refuseWhenTooLarge($spreadsheetId, $sheetTabId, $axis, $index + 1);

        $this->inShortTransaction($spreadsheetId, $sheetTabId, $expectedVersion, function () use ($spreadsheetId, $sheetTabId, $axis, $index) {
            $this->cells($spreadsheetId, $sheetTabId)->where($axis, $index)->delete();
            $this->shiftCells($spreadsheetId, $sheetTabId, $axis, $index + 1, -1);
            $this->checkClock();
            $resizedNames = $this->shiftNamedRanges($spreadsheetId, $sheetTabId, $axis, $index, -1);
            $this->shiftLayoutSettings($spreadsheetId, $axis, $index, -1);
            $this->rewriteFormulas($spreadsheetId, $sheetTabId, $axis, $index, -1, $resizedNames);
            StructureVersion::bump($spreadsheetId);
        });
    }

    /**
     * One transaction (BEGIN IMMEDIATE on SQLite: the whole database is write-locked until it ends, so it
     * must stay short). When another writer holds the lock for longer than busy_timeout, say so politely
     * instead of answering 500. Caches are cleared afterwards, also after a rollback.
     */
    protected function inShortTransaction(int $spreadsheetId, int $sheetTabId, ?int $expectedVersion, callable $work): void
    {
        try {
            DB::transaction(function () use ($spreadsheetId, $sheetTabId, $expectedVersion, $work) {
                $this->deadline = microtime(true) + $this->timeLimit;
                // Checked again under the lock: a batch may have started since the check before it.
                $this->refuseWhenABatchIsRunning($spreadsheetId);

                // The caller's check happened before the lock; this one is under it.
                if ($expectedVersion !== null) {
                    if (! SheetTab::whereKey($sheetTabId)->exists()) {
                        throw new StaleStructure(StructureVersion::TAB_GONE_MESSAGE);
                    }
                    if (StructureVersion::current($spreadsheetId, true) !== $expectedVersion) {
                        throw new StaleStructure(StructureVersion::STALE_MESSAGE);
                    }
                }

                $work();
            });
        } catch (QueryException|\PDOException $e) {
            if (self::isLockTimeout($e)) {
                throw new StructureChangeRefused(self::BUSY_MESSAGE, 0, $e);
            }

            throw $e;
        } finally {
            $this->forgetCaches($spreadsheetId, $sheetTabId);
        }
    }

    public static function isLockTimeout(\Throwable $e): bool
    {
        return WriteLock::isTimeout($e);
    }

    /**
     * The switch (config sheet.structure_ops_enabled) is checked here too, so no caller, route or
     * crafted request can reach the structure code while it is off.
     */
    protected function refuseWhenSwitchedOff(): void
    {
        if (! config('sheet.structure_ops_enabled')) {
            throw new StructureChangeRefused(self::DISABLED_MESSAGE);
        }
    }

    /**
     * Estimate the work from three counts before taking the lock; recompute work is added once it is known.
     */
    protected function refuseWhenTooLarge(int $spreadsheetId, int $sheetTabId, string $axis, int $firstMovedIndex): void
    {
        $total = $this->cells($spreadsheetId, $sheetTabId)->count();
        $moved = $this->cells($spreadsheetId, $sheetTabId)->where($axis, '>=', $firstMovedIndex)->count();
        $formulas = $this->cells($spreadsheetId, $sheetTabId)->whereNotNull('formula')->count();

        $this->work = $moved * self::COST_CELL_MOVED + $formulas * self::COST_FORMULA_REWRITTEN + $total * self::COST_CELL_SCANNED;

        if ($this->work > $this->workBudget) {
            throw new StructureChangeRefused(self::TOO_LARGE_MESSAGE);
        }
    }

    /**
     * An import or paste for this sheet is still writing: its rows would land on a layout that moved.
     */
    protected function refuseWhenABatchIsRunning(int $spreadsheetId): void
    {
        $running = DB::table('job_batches')
            ->whereIn('name', ["import:spreadsheet:{$spreadsheetId}", "bulk-paste:spreadsheet:{$spreadsheetId}"])
            ->whereNull('finished_at')
            ->whereNull('cancelled_at')
            ->where('created_at', '>', time() - self::BATCH_STALE_AFTER_SECONDS)
            ->pluck('name');

        if ($running->isNotEmpty()) {
            throw new StructureChangeRefused(str_starts_with((string) $running->first(), 'import:') ? self::IMPORT_RUNNING_MESSAGE : self::PASTE_RUNNING_MESSAGE);
        }
    }

    /**
     * Add work done and abort (rolling the transaction back) when the budget or the clock runs out.
     */
    protected function spend(float $units): void
    {
        $this->work += $units;
        if ($this->work > $this->workBudget) {
            throw new StructureChangeRefused(self::TOO_LARGE_MESSAGE);
        }
        $this->checkClock();
    }

    protected function checkClock(): void
    {
        if ($this->deadline > 0 && microtime(true) > $this->deadline) {
            throw new StructureChangeRefused(self::TOO_LARGE_MESSAGE);
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
     * Row heights, hidden rows, column widths, hidden columns and the rules that carry a row or column
     * (validation, conditional format, filters) live in the spreadsheet's settings and follow the move in
     * the same transaction, so no other editor ever sees cells moved and these not. A rule on a deleted
     * row or column is dropped.
     */
    protected function shiftLayoutSettings(int $spreadsheetId, string $axis, int $index, int $delta): void
    {
        $isRow = $axis === 'row_index';
        $field = $isRow ? 'row' : 'col';

        $move = function ($position) use ($index, $delta) {
            $position = (int) $position;
            if ($delta > 0) {
                return $position >= $index ? $position + 1 : $position;
            }

            return $position === $index ? null : ($position > $index ? $position - 1 : $position);
        };

        Spreadsheet::updateSettings($spreadsheetId, function (array $settings) use ($isRow, $field, $move) {
            $sizesKey = $isRow ? 'row_heights' : 'col_widths';
            $hiddenKey = $isRow ? 'hidden_rows' : 'hidden_cols';

            $sizes = [];
            foreach (is_array($settings[$sizesKey] ?? null) ? $settings[$sizesKey] : [] as $position => $size) {
                if (($moved = $move($position)) !== null) {
                    $sizes[$moved] = $size;
                }
            }
            $settings[$sizesKey] = $sizes;

            $settings[$hiddenKey] = array_values(array_filter(array_map($move, is_array($settings[$hiddenKey] ?? null) ? $settings[$hiddenKey] : []), fn ($p) => $p !== null));

            $ruleKeys = $isRow ? ['validation_rules', 'conditional_formatting_rules'] : ['validation_rules', 'conditional_formatting_rules', 'filter_rules'];
            foreach ($ruleKeys as $key) {
                $rules = [];
                foreach (is_array($settings[$key] ?? null) ? $settings[$key] : [] as $rule) {
                    if (is_array($rule) && array_key_exists($field, $rule)) {
                        if (($moved = $move($rule[$field])) === null) {
                            continue;
                        }
                        $rule[$field] = $moved;
                    }
                    $rules[] = $rule;
                }
                $settings[$key] = $rules;
            }

            return $settings;
        });
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
     * points somewhere else. A formula that only moved keeps its stored value.
     *
     * Memory stays flat: formulas are read in chunks as bare rows (no models), the recompute list holds
     * only id, position and text, and the evaluator gets just the cells those formulas read (one pass with
     * a cursor), not the whole tab and not through the cache (with CACHE_STORE=database every evaluate()
     * unserialised the entire tab: 167 MB for 1,000 running totals).
     *
     * @param  list<string>  $resizedNames
     */
    protected function rewriteFormulas(int $spreadsheetId, int $sheetTabId, string $axis, int $index, int $delta, array $resizedNames): void
    {
        /** @var list<array{id: int, row: int, col: int, formula: string}> $recompute */
        $recompute = [];

        DB::table('cells')
            ->where('spreadsheet_id', $spreadsheetId)
            ->where('sheet_tab_id', $sheetTabId)
            // Only real formulas. A text cell that starts with "=" (formula column empty) is data.
            ->whereNotNull('formula')
            ->select('id', 'row_index', 'col_index', 'formula', 'raw_value')
            ->chunkById(1000, function ($cells) use ($axis, $index, $delta, $resizedNames, &$recompute) {
                foreach ($cells as $cell) {
                    $original = (string) $cell->formula;
                    [$rewritten, $changesAnswer] = $this->rewriteReferencesDetailed($original, $axis, $index, $delta);

                    if ($rewritten !== $original) {
                        $updates = ['formula' => $rewritten];
                        // Imported formulas also keep their text in raw_value.
                        if ($cell->raw_value === $original) {
                            $updates['raw_value'] = $rewritten;
                        }
                        DB::table('cells')->where('id', $cell->id)->update($updates);
                    }

                    if ($changesAnswer || $this->usesAnyName($rewritten, $resizedNames)) {
                        $recompute[] = ['id' => (int) $cell->id, 'row' => (int) $cell->row_index, 'col' => (int) $cell->col_index, 'formula' => $rewritten];
                    }
                }
                $this->checkClock();
            });

        if ($recompute === []) {
            return;
        }

        $evaluator = new FormulaEvaluatorService;
        $namedRanges = $evaluator->namedRangeMap($spreadsheetId, $sheetTabId);

        $needed = [];
        foreach ($recompute as $formula) {
            if (! str_contains($formula['formula'], self::REF_ERROR)) {
                $keys = $evaluator->referencedKeys($formula['formula'], $namedRanges);
                // Each recomputed formula reads every cell it refers to, however often they repeat.
                $this->spend(self::COST_FORMULA_RECOMPUTED + count($keys) * self::COST_REFERENCE_READ);
                foreach ($keys as $key) {
                    $needed[$key] = true;
                }
            }
            if (count($needed) > self::MAX_REFERENCED_CELLS) {
                throw new StructureChangeRefused(self::TOO_LARGE_MESSAGE);
            }
        }
        $this->spend(count($needed) * self::COST_CELL_LOADED);

        $values = [];
        foreach (DB::table('cells')->where('spreadsheet_id', $spreadsheetId)->where('sheet_tab_id', $sheetTabId)
            ->select('row_index', 'col_index', 'raw_value', 'computed_value')->cursor() as $cell) {
            $key = $cell->row_index.'_'.$cell->col_index;
            if (isset($needed[$key])) {
                // Only numbers matter to the evaluator (anything else reads as 0): do not hold long text.
                $value = $cell->computed_value ?? $cell->raw_value;
                $values[$key] = is_numeric($value) ? $value : 0;
            }
        }
        $this->checkClock();

        // In row order, so a formula that reads an earlier recomputed one sees its new value.
        usort($recompute, fn ($a, $b) => [$a['row'], $a['col']] <=> [$b['row'], $b['col']]);

        foreach ($recompute as $formula) {
            if (str_contains($formula['formula'], self::REF_ERROR)) {
                $computed = self::REF_ERROR;
            } else {
                $result = $evaluator->evaluateAgainst($formula['formula'], $values, $namedRanges);
                $computed = $result['error'] ? '#ERROR' : $result['value'];
            }

            DB::table('cells')->where('id', $formula['id'])->update(['computed_value' => $computed]);
            $this->checkClock();

            $key = $formula['row'].'_'.$formula['col'];
            if (isset($needed[$key])) {
                $values[$key] = is_numeric($computed) ? $computed : 0;
            }
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
