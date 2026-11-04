<?php

namespace App\Services;

use App\Models\Cell;
use App\Services\Formula\FormulaText;
use Illuminate\Support\Facades\DB;

/**
 * Working out what sorting rows writes. Rows change places as ordinary edits, so a row's formulas follow
 * it as they do when copied, and undo puts everything back. Blanks go last whichever way the sort runs,
 * and rows that tie keep their order.
 */
class RangeSort
{
    /** @var array<int, int> after a plan, the row each moved row was on => the row it goes to */
    public array $moved = [];

    /**
     * The block of data around a cell, with no empty row or column inside it. Frozen rows stay out, and
     * so does a first row that reads as a header: no figures in it, and figures under it.
     *
     * @return array{area: array{r1: int, r2: int, c1: int, c2: int}, header: int|null}|null
     */
    public function block(int $tabId, int $row, int $col, int $frozenRows): ?array
    {
        $filled = fn () => DB::table('cells')->where('sheet_tab_id', $tabId)
            ->where(fn ($cells) => $cells->whereNotNull('formula')->orWhere('raw_value', '!=', '')->orWhereNotNull('spill_row'));

        $columns = $filled()->distinct()->orderBy('col_index')->pluck('col_index')->map(fn ($c) => (int) $c)->all();
        [$c1, $c2] = $this->runAround($columns, $col);
        if ($c1 === null) {
            return null;
        }

        $rows = $filled()->whereBetween('col_index', [$c1, $c2])->distinct()->orderBy('row_index')->pluck('row_index')->map(fn ($r) => (int) $r)->all();
        [$r1, $r2] = $this->runAround($rows, max($row, $frozenRows));
        if ($r1 === null) {
            return null;
        }

        $r1 = max($r1, $frozenRows);
        $header = null;
        if ($r1 >= $r2) {
            return null;
        }
        if ($frozenRows > 0) {
            $header = $r1 === $frozenRows ? $frozenRows - 1 : null;
        } elseif ($this->readsAsHeader($tabId, $r1, $c1, $c2)) {
            $header = $r1;
            $r1++;
        }

        return ['area' => ['r1' => $r1, 'r2' => $r2, 'c1' => $c1, 'c2' => $c2], 'header' => $header];
    }

    /**
     * The new contents of the rows that move.
     *
     * @param  array{r1: int, r2: int, c1: int, c2: int}  $area
     * @param  array<int, mixed>  $rules  the sheet's validation rules
     * @return list<array{row: int, col: int, value: string, x: array<string, mixed>|null}>
     *
     * @throws SortRefused
     */
    public function plan(int $tabId, array $area, int $keyCol, bool $descending, array $rules): array
    {
        $cells = Cell::where('sheet_tab_id', $tabId)
            ->whereBetween('row_index', [$area['r1'], $area['r2']])
            ->whereBetween('col_index', [$area['c1'], $area['c2']])
            ->get()->keyBy(fn (Cell $cell) => $cell->row_index.':'.$cell->col_index);

        foreach ($cells as $cell) {
            if ($cell->spill_row !== null || $cell->spill_rows !== null) {
                throw new SortRefused('A formula spills across these rows. Change the formula first.');
            }
        }

        $key = fn (int $row) => $this->valueOf($cells[$row.':'.$keyCol] ?? null);
        $order = range($area['r1'], $area['r2']);
        usort($order, function (int $a, int $b) use ($key, $descending) {
            [$left, $right] = [$key($a), $key($b)];

            // Blanks stay at the foot both ways.
            if ($left === '' || $right === '') {
                return ($left === '') <=> ($right === '');
            }

            return ($descending ? -1 : 1) * $this->compare($left, $right);
        });

        $this->moved = [];
        $edits = [];
        foreach ($order as $i => $from) {
            $to = $area['r1'] + $i;
            if ($from === $to) {
                continue;
            }
            $this->moved[$from] = $to;

            for ($col = $area['c1']; $col <= $area['c2']; $col++) {
                $source = $cells[$from.':'.$col] ?? null;
                if ($source === null && ! isset($cells[$to.':'.$col])) {
                    continue;
                }

                $value = $source === null ? '' : ($source->formula !== null ? FormulaText::moved($source->formula, $to - $from, 0) : (string) $source->raw_value);
                if ($value !== '' && ($reason = CellRules::refuse($rules, $to, $col, $value)) !== null) {
                    throw new SortRefused("A rule on these cells stops this sort. {$reason}");
                }
                $edits[] = ['row' => $to, 'col' => $col, 'value' => $value, 'x' => ($source->formatting ?? null) ?: null];
            }
        }

        return $edits;
    }

    /**
     * Numbers before text, and numbers by size. Text in natural order, so Unit 2 comes before Unit 10.
     */
    private function compare(string $left, string $right): int
    {
        $numbers = is_numeric($left) <=> is_numeric($right);
        if ($numbers !== 0) {
            return -$numbers;
        }

        return is_numeric($left) ? (float) $left <=> (float) $right : strnatcasecmp($left, $right);
    }

    private function valueOf(?Cell $cell): string
    {
        return $cell === null ? '' : (string) ($cell->formula !== null ? $cell->computed_value : $cell->raw_value);
    }

    /**
     * @param  list<int>  $positions  in order
     * @return array{0: int|null, 1: int|null} the unbroken run through a position
     */
    private function runAround(array $positions, int $at): array
    {
        $held = array_flip($positions);
        if (! isset($held[$at])) {
            return [null, null];
        }

        [$first, $last] = [$at, $at];
        while (isset($held[$first - 1])) {
            $first--;
        }
        while (isset($held[$last + 1])) {
            $last++;
        }

        return [$first, $last];
    }

    private function readsAsHeader(int $tabId, int $row, int $c1, int $c2): bool
    {
        $values = fn (int $at) => Cell::where('sheet_tab_id', $tabId)->where('row_index', $at)->whereBetween('col_index', [$c1, $c2])->get()->map(fn (Cell $cell) => $this->valueOf($cell));

        return $values($row)->every(fn (string $value) => ! is_numeric($value)) && $values($row + 1)->contains(fn (string $value) => is_numeric($value));
    }
}
