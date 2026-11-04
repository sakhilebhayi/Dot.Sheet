<?php

namespace App\Services;

use App\Models\NamedRange;

/** Ranges as people type them: A1 or A1:B10, with or without $ signs. */
final class RangeText
{
    /**
     * Where a range is, or null when it is not on the sheet.
     *
     * @return array{r1: int, c1: int, r2: int, c2: int}|null
     */
    public static function parse(string $range): ?array
    {
        if (! preg_match('/^([A-Z]{1,3})([1-9]\d*)(?::([A-Z]{1,3})([1-9]\d*))?$/', strtoupper(str_replace('$', '', trim($range))), $m)) {
            return null;
        }

        [$firstCol, $firstRow] = [self::column($m[1]), (int) $m[2] - 1];
        [$lastCol, $lastRow] = isset($m[3]) ? [self::column($m[3]), (int) $m[4] - 1] : [$firstCol, $firstRow];

        if (max($firstCol, $lastCol) >= SheetStructureService::GRID_COLS || max($firstRow, $lastRow) >= SheetStructureService::GRID_ROWS) {
            return null;
        }

        return ['r1' => min($firstRow, $lastRow), 'c1' => min($firstCol, $lastCol), 'r2' => max($firstRow, $lastRow), 'c2' => max($firstCol, $lastCol)];
    }

    /**
     * @param  array{r1: int, c1: int, r2: int, c2: int}  $area
     */
    public static function of(array $area): string
    {
        return NamedRange::colIndexToLetter($area['c1']).($area['r1'] + 1).':'.NamedRange::colIndexToLetter($area['c2']).($area['r2'] + 1);
    }

    private static function column(string $letters): int
    {
        $index = 0;
        foreach (str_split($letters) as $letter) {
            $index = $index * 26 + (ord($letter) - 64);
        }

        return $index - 1;
    }
}
