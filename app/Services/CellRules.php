<?php

namespace App\Services;

/**
 * A sheet's validation rules, checked against a value someone is about to put in a cell. A rule covers
 * the cell at row and col, or the rectangle out to row2 and col2 when it has them.
 */
class CellRules
{
    /**
     * @param  array<int, mixed>  $rules  the sheet's validation_rules
     * @return string|null what is wrong, or null when the value may be saved
     */
    public static function refuse(array $rules, int $row, int $col, string $value): ?string
    {
        foreach ($rules as $rule) {
            if (! is_array($rule) || ! self::covers($rule, $row, $col)) {
                continue;
            }

            $type = (string) ($rule['type'] ?? '');
            if ($type === 'number') {
                if (! is_numeric($value)) {
                    return 'Only numbers go here.';
                }

                $min = $rule['min'] ?? null;
                $max = $rule['max'] ?? null;
                $numeric = (float) $value;
                if ($min !== null && $numeric < (float) $min) {
                    return "That is too low: the least allowed here is {$min}.";
                }
                if ($max !== null && $numeric > (float) $max) {
                    return "That is too high: the most allowed here is {$max}.";
                }
            }

            if ($type === 'text_length') {
                $len = mb_strlen($value);
                $min = (int) ($rule['min'] ?? 0);
                $max = (int) ($rule['max'] ?? 999999);
                if ($len < $min) {
                    return "That is too short: text here needs at least {$min} characters.";
                }
                if ($len > $max) {
                    return "That is too long: text here runs to {$max} characters at most.";
                }
            }

            if ($type === 'list') {
                $items = self::items($rule);
                if ($items !== [] && ! in_array($value, $items, true)) {
                    return 'Choose from the list: '.implode(', ', array_slice($items, 0, 8)).(count($items) > 8 ? ', and more.' : '.');
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $rule
     */
    private static function covers(array $rule, int $row, int $col): bool
    {
        if (! isset($rule['row'], $rule['col'])) {
            return false;
        }

        return $row >= (int) $rule['row'] && $row <= (int) ($rule['row2'] ?? $rule['row'])
            && $col >= (int) $rule['col'] && $col <= (int) ($rule['col2'] ?? $rule['col']);
    }

    /**
     * What a list rule allows. resources/js/grid/format.js reads lists the same way.
     *
     * @param  array<string, mixed>  $rule
     * @return list<string>
     */
    public static function items(array $rule): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) ($rule['items'] ?? ''))), fn (string $item) => $item !== ''));
    }
}
