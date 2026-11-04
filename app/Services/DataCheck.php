<?php

namespace App\Services;

use App\Models\Cell;
use App\Services\Formula\FormulaFromWords;

/**
 * Looks over a table for what usually goes wrong: repeated rows, gaps, the same word spelt two ways, spare
 * spaces, numbers and dates held as text, and figures far from the rest. It works from the cells alone,
 * so it needs no AI, and it changes nothing: a fix is a list of changes for a person to look at and take.
 */
class DataCheck
{
    private const MOST_ROWS_READ = 5000;

    private const MOST_LISTED = 20;

    /** How far from the middle half a figure must sit to be worth a look. */
    private const FAR = 3.0;

    private const SMALLEST_FOR_OUTLIERS = 8;

    public function __construct(private FormulaFromWords $words) {}

    /**
     * @return list<array{kind: string, title: string, plain: string, rows: list<int>, column: string|null, changes: list<array{row: int, col: int, from: string, to: string}>}>
     */
    public function of(int $sheetTabId): array
    {
        $table = $this->words->tableOn($sheetTabId);
        if ($table === null) {
            return [];
        }

        $columns = array_column($table['columns'], 'col');
        $grid = [];
        foreach (Cell::where('sheet_tab_id', $sheetTabId)->whereBetween('row_index', [$table['top'] + 1, min($table['last'], $table['top'] + self::MOST_ROWS_READ)])
            ->whereIn('col_index', $columns)->whereNull('formula')->get(['row_index', 'col_index', 'raw_value']) as $cell) {
            $grid[$cell->row_index][$cell->col_index] = (string) $cell->raw_value;
        }
        $grid = array_filter($grid, fn (array $row) => implode('', $row) !== '');
        ksort($grid);
        if ($grid === []) {
            return [];
        }

        $found = $this->repeatedRows($grid, $columns);
        foreach ($table['columns'] as ['col' => $col, 'heading' => $heading]) {
            $values = array_map(fn (array $row) => $row[$col] ?? '', $grid);
            array_push($found, ...array_filter([
                $this->gaps($values, $heading),
                $this->spaces($values, $col, $heading),
                $this->spellings($values, $col, $heading),
                $this->numbersAsText($values, $col, $heading),
                $this->datesAsText($values, $col, $heading),
                $this->farFromTheRest($values, $heading),
            ]));
        }

        return $found;
    }

    /**
     * @param  array<int, array<int, string>>  $grid
     * @param  list<int>  $columns
     * @return list<array<string, mixed>>
     */
    private function repeatedRows(array $grid, array $columns): array
    {
        $seen = [];
        foreach ($grid as $row => $cells) {
            $key = implode("\0", array_map(fn (int $col) => mb_strtolower(trim($cells[$col] ?? '')), $columns));
            $seen[$key][] = $row;
        }
        $repeats = array_merge(...array_values(array_map(fn (array $rows) => array_slice($rows, 1), array_filter($seen, fn (array $rows) => count($rows) > 1))) ?: [[]]);
        if ($repeats === []) {
            return [];
        }

        return [$this->finding('repeats', count($repeats) === 1 ? 'A row appears twice' : count($repeats).' rows repeat an earlier row', 'Every cell matches a row above it. Nothing is removed here: look at them first.', $repeats, null)];
    }

    /**
     * @param  array<int, string>  $values
     * @return array<string, mixed>|null
     */
    private function gaps(array $values, string $heading): ?array
    {
        $empty = array_keys(array_filter($values, fn (string $value) => trim($value) === ''));
        // A column that is mostly empty is optional, and its gaps mean nothing.
        if ($empty === [] || count($empty) * 2 >= count($values)) {
            return null;
        }

        return $this->finding('gaps', count($empty) === 1 ? "A gap in {$heading}" : count($empty)." gaps in {$heading}", 'Most of this column is filled in, and these rows are not.', $empty, $heading);
    }

    /**
     * @param  array<int, string>  $values
     * @return array<string, mixed>|null
     */
    private function spaces(array $values, int $col, string $heading): ?array
    {
        $changes = [];
        foreach ($values as $row => $value) {
            $clean = trim((string) preg_replace('/\s+/', ' ', $value));
            if ($clean !== $value && $clean !== '') {
                $changes[] = ['row' => $row, 'col' => $col, 'from' => $value, 'to' => $clean];
            }
        }

        return $changes === [] ? null : $this->fix('spaces', "Spare spaces in {$heading}", 'Spaces at the ends of a value, or two in a row, make it count as a different value.', $changes, $heading);
    }

    /**
     * @param  array<int, string>  $values
     * @return array<string, mixed>|null
     */
    private function spellings(array $values, int $col, string $heading): ?array
    {
        $words = array_filter($values, fn (string $value) => trim($value) !== '' && ! is_numeric(trim($value)));
        $groups = [];
        foreach ($words as $row => $value) {
            $groups[mb_strtolower(trim((string) preg_replace('/\s+/', ' ', $value)))][$row] = trim((string) preg_replace('/\s+/', ' ', $value));
        }

        $changes = [];
        foreach ($groups as $forms) {
            $counts = array_count_values($forms);
            if (count($counts) < 2) {
                continue;
            }
            arsort($counts);
            $usual = (string) array_key_first($counts);
            foreach ($forms as $row => $form) {
                if ($form !== $usual) {
                    $changes[] = ['row' => $row, 'col' => $col, 'from' => $form, 'to' => $usual];
                }
            }
        }

        return $changes === [] ? null : $this->fix('spellings', "The same word written differently in {$heading}", 'Capitals differ, so these count as different values. Each becomes the way it is most often written.', $changes, $heading);
    }

    /**
     * @param  array<int, string>  $values
     * @return array<string, mixed>|null
     */
    private function numbersAsText(array $values, int $col, string $heading): ?array
    {
        $filled = array_filter($values, fn (string $value) => trim($value) !== '');
        $changes = [];
        $numbers = 0;
        foreach ($filled as $row => $value) {
            if (is_numeric(trim($value))) {
                $numbers++;
            } elseif (preg_match('/^\s*(?:R|\$|€|£)?\s*-?\d{1,3}(?:[ ,\x{00A0}]\d{3})*(?:\.\d+)?\s*$/u', $value) === 1) {
                $changes[] = ['row' => $row, 'col' => $col, 'from' => $value, 'to' => (string) preg_replace('/[^\d.\-]/', '', $value)];
            }
        }

        // Most of the column must already be figures, or these are labels that happen to hold digits.
        if ($changes === [] || ($numbers + count($changes)) * 2 <= count($filled)) {
            return null;
        }

        return $this->fix('numbers', "Numbers held as text in {$heading}", 'A currency sign or spaces stop these being added up. Each becomes a plain number.', $changes, $heading);
    }

    /**
     * @param  array<int, string>  $values
     * @return array<string, mixed>|null
     */
    private function datesAsText(array $values, int $col, string $heading): ?array
    {
        $filled = array_filter($values, fn (string $value) => trim($value) !== '');
        $changes = [];
        foreach ($filled as $row => $value) {
            $serial = $this->serial(trim($value));
            if ($serial !== null) {
                $changes[] = ['row' => $row, 'col' => $col, 'from' => $value, 'to' => (string) $serial];
            }
        }

        if ($changes === [] || count($changes) * 2 <= count($filled)) {
            return null;
        }

        return $this->fix('dates', "Dates held as text in {$heading}", 'These look like dates but cannot be sorted or subtracted as dates. Day comes first in a date written with slashes. Show the column as a date afterwards.', $changes, $heading);
    }

    private function serial(string $value): ?int
    {
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $value, $m) === 1) {
            [$year, $month, $day] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $value, $m) === 1) {
            [$day, $month, $year] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } else {
            return null;
        }

        return checkdate($month, $day, $year) ? (int) (gmmktime(0, 0, 0, $month, $day, $year) / 86400) + 25569 : null;
    }

    /**
     * @param  array<int, string>  $values
     * @return array<string, mixed>|null
     */
    private function farFromTheRest(array $values, string $heading): ?array
    {
        $numbers = array_map('floatval', array_filter($values, fn (string $value) => is_numeric(trim($value))));
        if (count($numbers) < self::SMALLEST_FOR_OUTLIERS) {
            return null;
        }

        $sorted = array_values($numbers);
        sort($sorted);
        $low = $sorted[(int) floor(count($sorted) / 4)];
        $high = $sorted[(int) floor(count($sorted) * 3 / 4)];
        $spread = $high - $low;
        if ($spread <= 0.0) {
            return null;
        }

        $far = array_keys(array_filter($numbers, fn (float $number) => $number < $low - self::FAR * $spread || $number > $high + self::FAR * $spread));

        return $far === [] ? null : $this->finding('outliers', count($far) === 1 ? "A figure far from the rest in {$heading}" : count($far)." figures far from the rest in {$heading}", 'They may be right. They sit well outside where the middle half of this column falls.', $far, $heading);
    }

    /**
     * @param  list<array{row: int, col: int, from: string, to: string}>  $changes
     * @return array<string, mixed>
     */
    private function fix(string $kind, string $title, string $plain, array $changes, string $heading): array
    {
        return $this->finding($kind, $title, $plain, array_column($changes, 'row'), $heading, $changes);
    }

    /**
     * @param  list<int>  $rows
     * @param  list<array{row: int, col: int, from: string, to: string}>  $changes
     * @return array<string, mixed>
     */
    private function finding(string $kind, string $title, string $plain, array $rows, ?string $column, array $changes = []): array
    {
        return ['kind' => $kind, 'title' => $title, 'plain' => $plain, 'rows' => array_slice($rows, 0, self::MOST_LISTED), 'count' => count($rows), 'column' => $column, 'changes' => $changes];
    }
}
