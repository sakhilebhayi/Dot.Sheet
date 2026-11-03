<?php

namespace App\Services\Formula;

use App\Services\SheetStructureService;

/**
 * Reading a formula's text: which cells it refers to, and rewriting the parts the engine needs to
 * see differently (named ranges, sheet names). Text inside "string literals" is never treated as a
 * reference: =IF(A1="B2",1,0) reads A1 only.
 *
 * Rows and columns are 0-based, as in the cells table: A1 is row 0, column 0.
 */
final class FormulaText
{
    /** An optional sheet name: Sheet2! or 'Sales 2026'! (a quote inside a quoted name is doubled). */
    private const SHEET = "(?:(?:'((?:[^']|'')+)'|([A-Za-z_][A-Za-z0-9_.]*))!)?";

    /** A cell (B2), a range (B2:D9), whole columns (B:D) or whole rows (2:9), each with optional $. */
    private const REF = '(\$?[A-Za-z]{1,3}\$?\d+(?::\$?[A-Za-z]{1,3}\$?\d+)?|\$?[A-Za-z]{1,3}:\$?[A-Za-z]{1,3}|\$?\d+:\$?\d+)';

    /**
     * The formula with every string literal's content blanked out, same length: positions found in it
     * are positions in the original.
     */
    public static function maskStrings(string $formula): string
    {
        return (string) preg_replace_callback('/"(?:[^"]|"")*"/', fn (array $m) => '"'.str_repeat(' ', strlen($m[0]) - 2).'"', $formula);
    }

    /**
     * Every reference in the formula, in order.
     *
     * @return list<array{sheet: ?string, r1: int, r2: int, c1: int, c2: int, offset: int, length: int, sheetLength: int}>
     */
    public static function references(string $formula): array
    {
        $masked = self::maskStrings($formula);
        $pattern = '/(?<![A-Za-z0-9_.$!\'])'.self::SHEET.self::REF.'(?![A-Za-z0-9_(!])/';

        if (! preg_match_all($pattern, $masked, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            return [];
        }

        $references = [];
        foreach ($matches as $m) {
            $sheet = null;
            if ($m[1][1] >= 0 && $m[1][0] !== '') {
                $sheet = str_replace("''", "'", substr($formula, $m[1][1], strlen($m[1][0])));
            } elseif ($m[2][1] >= 0 && $m[2][0] !== '') {
                $sheet = $m[2][0];
            }

            $bounds = self::bounds($m[3][0]);
            if ($bounds === null) {
                continue;
            }

            $references[] = [
                'sheet' => $sheet,
                ...$bounds,
                'offset' => $m[0][1],
                'length' => strlen($m[0][0]),
                'sheetLength' => strlen($m[0][0]) - strlen($m[3][0]),
            ];
        }

        return $references;
    }

    /**
     * The formula with each sheet name replaced by what $alias returns for it (left as written when it
     * returns null, so an unknown sheet still answers #REF!).
     *
     * @param  callable(string): ?string  $alias
     */
    public static function withSheetAliases(string $formula, callable $alias): string
    {
        foreach (array_reverse(self::references($formula)) as $reference) {
            if ($reference['sheet'] === null || ($replacement = $alias($reference['sheet'])) === null) {
                continue;
            }

            $formula = substr_replace($formula, $replacement.'!', $reference['offset'], $reference['sheetLength']);
        }

        return $formula;
    }

    /**
     * The formula with named ranges replaced by the reference each stands for, outside string literals.
     * Longer names first, so one name is never matched inside a longer one.
     *
     * @param  array<string, string>  $names  lower-case name => A1-style reference
     */
    public static function withNamedRanges(string $formula, array $names): string
    {
        if ($names === []) {
            return $formula;
        }

        uksort($names, fn (string $a, string $b) => strlen($b) <=> strlen($a));

        foreach ($names as $name => $reference) {
            $masked = self::maskStrings($formula);
            $pattern = '/(?<![A-Za-z0-9_.$!\'])'.preg_quote($name, '/').'(?![A-Za-z0-9_(!])/i';
            if (! preg_match_all($pattern, $masked, $matches, PREG_OFFSET_CAPTURE)) {
                continue;
            }

            foreach (array_reverse($matches[0]) as [$text, $offset]) {
                $formula = substr_replace($formula, $reference, $offset, strlen($text));
            }
        }

        return $formula;
    }

    public static function columnIndex(string $letters): int
    {
        $index = 0;
        foreach (str_split(strtoupper($letters)) as $letter) {
            $index = $index * 26 + (ord($letter) - 64);
        }

        return $index - 1;
    }

    public static function columnLetters(int $index): string
    {
        $letters = '';
        for ($n = $index + 1; $n > 0; $n = intdiv($n - 1, 26)) {
            $letters = chr(65 + ($n - 1) % 26).$letters;
        }

        return $letters;
    }

    /**
     * @return array{r1: int, r2: int, c1: int, c2: int}|null
     */
    private static function bounds(string $reference): ?array
    {
        $reference = str_replace('$', '', strtoupper($reference));
        $lastRow = SheetStructureService::GRID_ROWS - 1;
        $lastCol = SheetStructureService::GRID_COLS - 1;

        if (preg_match('/^([A-Z]{1,3})(\d+)(?::([A-Z]{1,3})(\d+))?$/', $reference, $m)) {
            [$c1, $r1] = [self::columnIndex($m[1]), (int) $m[2] - 1];
            [$c2, $r2] = isset($m[3]) ? [self::columnIndex($m[3]), (int) $m[4] - 1] : [$c1, $r1];
        } elseif (preg_match('/^([A-Z]{1,3}):([A-Z]{1,3})$/', $reference, $m)) {
            [$c1, $c2, $r1, $r2] = [self::columnIndex($m[1]), self::columnIndex($m[2]), 0, $lastRow];
        } elseif (preg_match('/^(\d+):(\d+)$/', $reference, $m)) {
            [$r1, $r2, $c1, $c2] = [(int) $m[1] - 1, (int) $m[2] - 1, 0, $lastCol];
        } else {
            return null;
        }

        if ($r1 < 0 || $r2 < 0) {
            return null;   // row 0 does not exist
        }

        return ['r1' => min($r1, $r2), 'r2' => min(max($r1, $r2), $lastRow), 'c1' => min($c1, $c2), 'c2' => max($c1, $c2)];
    }
}
