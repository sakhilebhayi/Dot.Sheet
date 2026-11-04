<?php

namespace App\Services;

use App\Models\SheetTab;
use Illuminate\Support\Facades\DB;

/**
 * What a rule about a whole column needs to know that a window of cells cannot tell it: the column's
 * lowest and highest, what appears twice, and where the data ends.
 */
class RuleFacts
{
    public const KINDS = ['scale', 'bars', 'icons', 'duplicates', 'missing'];

    private const MOST_DUPLICATES = 500;

    /**
     * @return array<string, array{min: float|null, max: float|null, twice: list<string>, last: int}> by rule id
     */
    public function of(SheetTab $tab): array
    {
        $layout = is_array($tab->layout) ? $tab->layout : [];
        $facts = [];
        foreach ($layout['conditional_formatting_rules'] ?? [] as $rule) {
            if (is_array($rule) && in_array($rule['kind'] ?? null, self::KINDS, true) && isset($rule['id'])) {
                $facts[$rule['id']] = $this->column($tab->id, (int) $rule['col'], (int) ($rule['row'] ?? 0));
            }
        }

        return $facts;
    }

    /**
     * @return array{min: float|null, max: float|null, twice: list<string>, last: int}
     */
    private function column(int $tabId, int $col, int $from): array
    {
        $values = DB::table('cells')->where('sheet_tab_id', $tabId)->where('col_index', $col)->where('row_index', '>=', $from)
            ->selectRaw("COALESCE(NULLIF(computed_value, ''), raw_value) as value")->pluck('value')
            ->map(fn ($value) => trim((string) $value))->filter(fn (string $value) => $value !== '');

        $numbers = $values->filter(fn (string $value) => is_numeric($value))->map(fn (string $value) => (float) $value);
        $counts = array_count_values($values->all());

        return [
            'min' => $numbers->min(),
            'max' => $numbers->max(),
            'twice' => array_slice(array_map('strval', array_keys(array_filter($counts, fn (int $count) => $count > 1))), 0, self::MOST_DUPLICATES),
            'last' => (int) DB::table('cells')->where('sheet_tab_id', $tabId)->max('row_index'),
        ];
    }
}
