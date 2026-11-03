<?php

namespace App\Services;

/**
 * A sheet's validation rules, checked against a value someone is about to put in a cell.
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
            if (! is_array($rule)) {
                continue;
            }

            if (($rule['row'] ?? null) !== $row || ($rule['col'] ?? null) !== $col) {
                continue;
            }

            $type = (string) ($rule['type'] ?? '');
            if ($type === 'number') {
                if (! is_numeric($value)) {
                    return 'Validation failed: value must be numeric.';
                }

                $min = $rule['min'] ?? null;
                $max = $rule['max'] ?? null;
                $numeric = (float) $value;
                if ($min !== null && $numeric < (float) $min) {
                    return 'Validation failed: value is below minimum.';
                }
                if ($max !== null && $numeric > (float) $max) {
                    return 'Validation failed: value is above maximum.';
                }
            }

            if ($type === 'text_length') {
                $len = mb_strlen($value);
                $min = (int) ($rule['min'] ?? 0);
                $max = (int) ($rule['max'] ?? 999999);
                if ($len < $min || $len > $max) {
                    return 'Validation failed: text length out of range.';
                }
            }

            if ($type === 'list') {
                $items = array_values(array_filter(array_map('trim', explode(',', (string) ($rule['items'] ?? '')))));
                if (! empty($items) && ! in_array($value, $items, true)) {
                    return 'Validation failed: value not in allowed list.';
                }
            }
        }

        return null;
    }
}
