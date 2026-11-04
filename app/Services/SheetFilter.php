<?php

namespace App\Services;

use App\Models\Cell;
use Illuminate\Support\Facades\DB;

/**
 * A sheet's filters: which rows they hide. The rows a filter hid are kept apart from rows someone hid by
 * hand, so taking a filter off leaves those hidden. Filters are applied when they change or when someone
 * asks, never as values change, so a row does not vanish while it is being typed in.
 */
class SheetFilter
{
    public const TYPES = ['contains', 'number_between', 'date_range', 'color', 'condition'];

    /**
     * The layout with these filters in force.
     *
     * @param  array<string, mixed>  $layout
     * @param  list<array<string, mixed>>  $rules
     * @return array<string, mixed>
     */
    public function applied(array $layout, int $tabId, array $rules): array
    {
        $byHand = array_diff(self::ints($layout['hidden_rows'] ?? []), self::ints($layout['filtered_rows'] ?? []));
        $filtered = $rules === [] ? [] : $this->hiddenBy($rules, $tabId, (int) ($layout['frozen_rows'] ?? 0));

        $layout['filter_rules'] = $rules;
        $layout['filtered_rows'] = $filtered;
        $layout['hidden_rows'] = array_values(array_unique([...$byHand, ...$filtered]));

        return $layout;
    }

    /**
     * Frozen rows stay, and when nothing is frozen so does the first row with data: it is the heading.
     *
     * @param  list<array<string, mixed>>  $rules
     * @return list<int>
     */
    private function hiddenBy(array $rules, int $tabId, int $frozenRows): array
    {
        $rows = DB::table('cells')->where('sheet_tab_id', $tabId)->distinct()->orderBy('row_index')->pluck('row_index')->map(fn ($r) => (int) $r)->all();
        $kept = $frozenRows > 0 ? $frozenRows : (($rows[0] ?? -1) + 1);

        $cells = Cell::where('sheet_tab_id', $tabId)->whereIn('col_index', array_map(fn (array $rule) => (int) ($rule['col'] ?? 0), $rules))->get()
            ->keyBy(fn (Cell $cell) => $cell->row_index.':'.$cell->col_index);

        $hidden = [];
        foreach ($rows as $row) {
            if ($row < $kept) {
                continue;
            }
            foreach ($rules as $rule) {
                if (! $this->keeps($cells[$row.':'.(int) ($rule['col'] ?? 0)] ?? null, $rule)) {
                    $hidden[] = $row;
                    break;
                }
            }
        }

        return $hidden;
    }

    /**
     * @param  array<string, mixed>  $rule
     */
    private function keeps(?Cell $cell, array $rule): bool
    {
        $value = $cell->computed_value ?? $cell->raw_value ?? '';

        return match ($rule['type'] ?? 'contains') {
            'contains' => str_contains(strtolower((string) $value), strtolower((string) ($rule['value'] ?? ''))),
            'number_between' => is_numeric($value)
                && (float) $value >= (float) ($rule['value'] ?? 0)
                && (float) $value <= (float) ($rule['value2'] ?? INF),
            'date_range' => $this->inDates((string) $value, $rule),
            'color' => strtolower((string) ($cell?->formatting['bg_color'] ?? '')) === strtolower((string) ($rule['color'] ?? '')),
            'condition' => self::matches($value, (string) ($rule['operator'] ?? '=='), $rule['value'] ?? null),
            default => true,
        };
    }

    /**
     * @param  array<string, mixed>  $rule
     */
    private function inDates(string $value, array $rule): bool
    {
        $from = strtotime((string) ($rule['value'] ?? ''));
        $to = strtotime((string) ($rule['value2'] ?? ''));
        $actual = strtotime($value);

        return $actual && $from && $to && $actual >= $from && $actual <= $to;
    }

    public static function matches(mixed $value, string $operator, mixed $expected): bool
    {
        if (in_array($operator, ['>', '>=', '<', '<='], true)) {
            if (! is_numeric($value) || ! is_numeric($expected)) {
                return false;
            }

            $left = (float) $value;
            $right = (float) $expected;

            return match ($operator) {
                '>' => $left > $right,
                '>=' => $left >= $right,
                '<' => $left < $right,
                default => $left <= $right,
            };
        }

        if ($operator === 'contains') {
            return str_contains(strtolower((string) $value), strtolower((string) $expected));
        }

        if ($operator === '!=') {
            return (string) $value !== (string) $expected;
        }

        return (string) $value === (string) $expected;
    }

    /**
     * @return list<int>
     */
    private static function ints(mixed $list): array
    {
        return is_array($list) ? array_map('intval', array_values($list)) : [];
    }
}
