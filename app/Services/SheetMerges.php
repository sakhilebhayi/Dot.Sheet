<?php

namespace App\Services;

use App\Models\Cell;
use App\Models\SheetTab;

/**
 * Merged cells: rectangles a sheet shows as one cell. They live in the sheet's layout. The first cell of
 * a rectangle holds the contents and the rest of it stays empty, so formulas read a merged cell through
 * its first cell, as they do in Excel.
 *
 * @phpstan-type Area array{r1: int, r2: int, c1: int, c2: int}
 */
class SheetMerges
{
    /** Most merged cells one sheet may hold. */
    public const MAX_MERGES = 1000;

    /** Most cells one merged cell may take in. */
    public const MAX_CELLS = RangeCopy::MAX_CELLS;

    public const SPLIT_MESSAGE = 'That would cut through a merged cell. Unmerge it first.';

    public const PART_MESSAGE = 'Select the whole merged cell.';

    /**
     * The merged cells in a sheet's layout, leaving out anything that is not a rectangle of two or more
     * cells: the classic editor's settings form can write to the layout.
     *
     * @return list<Area>
     */
    public static function of(mixed $layout): array
    {
        $found = [];
        foreach (is_array($layout) && is_array($layout['merges'] ?? null) ? $layout['merges'] : [] as $merge) {
            if (! is_array($merge) || ! isset($merge['r1'], $merge['r2'], $merge['c1'], $merge['c2'])) {
                continue;
            }
            $area = ['r1' => (int) $merge['r1'], 'r2' => (int) $merge['r2'], 'c1' => (int) $merge['c1'], 'c2' => (int) $merge['c2']];
            if ($area['r1'] >= 0 && $area['c1'] >= 0 && $area['r2'] >= $area['r1'] && $area['c2'] >= $area['c1'] && self::count($area) > 1) {
                $found[] = $area;
            }
        }

        return $found;
    }

    /**
     * @return list<Area>
     */
    public static function onTab(int $tabId): array
    {
        return self::of(SheetTab::whereKey($tabId)->value('layout'));
    }

    /**
     * @param  Area  $area
     */
    public static function count(array $area): int
    {
        return ($area['r2'] - $area['r1'] + 1) * ($area['c2'] - $area['c1'] + 1);
    }

    /**
     * The merged cells that reach into an area.
     *
     * @param  list<Area>  $merges
     * @param  Area  $area
     * @return list<Area>
     */
    public static function near(array $merges, array $area): array
    {
        return array_values(array_filter($merges, fn (array $m) => $m['r1'] <= $area['r2'] && $m['r2'] >= $area['r1'] && $m['c1'] <= $area['c2'] && $m['c2'] >= $area['c1']));
    }

    /**
     * True for the cells of a merge other than its first: the ones that hold nothing.
     *
     * @param  list<Area>  $merges
     */
    public static function covers(array $merges, int $row, int $col): bool
    {
        foreach ($merges as $m) {
            if ($row >= $m['r1'] && $row <= $m['r2'] && $col >= $m['c1'] && $col <= $m['c2'] && ($row !== $m['r1'] || $col !== $m['c1'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * The merged cells an area holds whole, and those it cuts through.
     *
     * @param  list<Area>  $merges
     * @param  Area  $area
     * @return array{inside: list<Area>, split: list<Area>}
     */
    public static function within(array $merges, array $area): array
    {
        $found = ['inside' => [], 'split' => []];
        foreach (self::near($merges, $area) as $m) {
            $whole = $m['r1'] >= $area['r1'] && $m['r2'] <= $area['r2'] && $m['c1'] >= $area['c1'] && $m['c2'] <= $area['c2'];
            $found[$whole ? 'inside' : 'split'][] = $m;
        }

        return $found;
    }

    /**
     * Change a sheet's merged cells. Rectangles come off first, so a merge can move onto itself, and a new
     * one takes in any that lie wholly inside it.
     *
     * @param  list<Area>  $add
     * @param  list<Area>  $remove
     * @return array{list: list<Area>, undo: array{add: list<Area>, remove: list<Area>}}
     *
     * @throws MergeRefused
     */
    public static function apply(int $tabId, array $add, array $remove): array
    {
        $undo = ['add' => [], 'remove' => []];
        $list = [];

        SheetTab::updateLayout($tabId, function (array $layout) use ($add, $remove, &$undo, &$list) {
            $merges = self::of($layout);

            foreach ($remove as $area) {
                if (in_array($area, $merges, true)) {
                    $merges = self::without($merges, [$area]);
                    $undo['add'][] = $area;
                }
            }

            foreach ($add as $area) {
                if ($area['r2'] >= SheetStructureService::GRID_ROWS || $area['c2'] >= SheetStructureService::GRID_COLS) {
                    throw new MergeRefused('That does not fit on the sheet.');
                }
                if (self::count($area) > self::MAX_CELLS) {
                    throw new MergeRefused('Merge fewer cells at a time: '.number_format(self::MAX_CELLS).' at most.');
                }

                ['inside' => $inside, 'split' => $split] = self::within($merges, $area);
                if ($split !== []) {
                    throw new MergeRefused(self::SPLIT_MESSAGE);
                }

                $merges = [...self::without($merges, $inside), $area];
                array_push($undo['add'], ...$inside);
                $undo['remove'][] = $area;
            }

            if (count($merges) > self::MAX_MERGES) {
                throw new MergeRefused('A sheet holds at most '.number_format(self::MAX_MERGES).' merged cells.');
            }

            $list = $merges;
            $layout['merges'] = $merges;

            return $layout;
        });

        return ['list' => $list, 'undo' => $undo];
    }

    /**
     * What merging an area writes: the first thing in it moves to the first cell and the rest is cleared.
     *
     * @param  Area  $area
     * @return array{edits: list<array{row: int, col: int, value: string, x: array<string, mixed>|null}>, cleared: int}
     *
     * @throws MergeRefused
     */
    public static function contents(int $tabId, array $area): array
    {
        $cells = Cell::where('sheet_tab_id', $tabId)
            ->whereBetween('row_index', [$area['r1'], $area['r2']])
            ->whereBetween('col_index', [$area['c1'], $area['c2']])
            ->orderBy('row_index')->orderBy('col_index')
            ->get();

        $edits = [];
        $held = 0;
        $kept = null;
        $first = null;
        foreach ($cells as $cell) {
            if ($cell->spill_row !== null || $cell->spill_rows !== null) {
                throw new MergeRefused('A formula spills across these cells. Change the formula first.');
            }

            $isFirst = (int) $cell->row_index === $area['r1'] && (int) $cell->col_index === $area['c1'];
            if ($isFirst) {
                $first = $cell;
            } else {
                $edits[] = ['row' => (int) $cell->row_index, 'col' => (int) $cell->col_index, 'value' => '', 'x' => null];
            }

            if ($cell->formula !== null || (string) $cell->raw_value !== '') {
                $held++;
                $kept ??= $cell;
            }
        }

        if ($kept !== null && $kept !== $first) {
            $edits[] = ['row' => $area['r1'], 'col' => $area['c1'], 'value' => (string) ($kept->formula ?? $kept->raw_value), 'x' => $kept->formatting ?: null];
        }

        return ['edits' => $edits, 'cleared' => max(0, $held - 1)];
    }

    /**
     * Merged cells after a row or column goes in or comes out: one inside a merge makes it bigger or
     * smaller, and a merge left with a single cell is no merge at all.
     *
     * @param  list<Area>  $merges
     * @param  callable(int, int): (array{0: int, 1: int}|null)  $span  where a span of rows or columns ends up
     * @return list<Area>
     */
    public static function shifted(array $merges, bool $isRow, callable $span): array
    {
        [$from, $to] = $isRow ? ['r1', 'r2'] : ['c1', 'c2'];

        $moved = [];
        foreach ($merges as $merge) {
            $ends = $span($merge[$from], $merge[$to]);
            if ($ends === null) {
                continue;
            }
            $merge[$from] = $ends[0];
            $merge[$to] = $ends[1];
            if (self::count($merge) > 1) {
                $moved[] = $merge;
            }
        }

        return $moved;
    }

    /**
     * @param  list<Area>  $merges
     * @param  list<Area>  $gone
     * @return list<Area>
     */
    private static function without(array $merges, array $gone): array
    {
        return array_values(array_filter($merges, fn (array $m) => ! in_array($m, $gone, true)));
    }
}
