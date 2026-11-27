<?php

namespace App\Services;

use App\Models\Spreadsheet;
use Illuminate\Support\Str;

/**
 * A workbook's workflow rules (watch a column; when a cell meets a condition, tell the owner or mark the cell),
 * kept in the workbook's settings and run by SheetWorkflows whichever editor wrote the cell. Moved here from the
 * classic editor's panel when that editor was retired (EA-6). Call add() and remove() under the write lock.
 */
final class WorkflowRules
{
    public const MAX_RULES = 50;

    public const OPERATORS = ['<', '<=', '>', '>=', '==', '!='];

    public const ACTIONS = ['email_owner', 'highlight_cell'];

    /** @return list<array<string, mixed>> */
    public function of(Spreadsheet $spreadsheet): array
    {
        $rules = $spreadsheet->settings['ai_workflows'] ?? [];

        return array_values(array_filter(is_array($rules) ? $rules : [], 'is_array'));
    }

    /**
     * @param  array{name: string, column: string, operator: string, value: string, action: string, email: bool, inApp: bool}  $rule
     * @return string|null why it was refused, or null when added
     */
    public function add(int $spreadsheetId, array $rule): ?string
    {
        $refused = null;
        Spreadsheet::updateSettings($spreadsheetId, function (array $settings) use ($rule, &$refused) {
            $rules = array_values(array_filter(is_array($settings['ai_workflows'] ?? null) ? $settings['ai_workflows'] : [], 'is_array'));
            if (count($rules) >= self::MAX_RULES) {
                $refused = 'A workbook can hold '.self::MAX_RULES.' rules at most. Delete one first.';

                return $settings;
            }

            $channels = array_values(array_filter([$rule['email'] ? 'email' : null, $rule['inApp'] ? 'database' : null]));
            array_unshift($rules, [
                'id' => (string) Str::uuid(),
                'name' => $rule['name'],
                'column' => strtoupper($rule['column']),
                'operator' => $rule['operator'],
                'value' => $rule['value'],
                'action' => $rule['action'],
                'notify_channels' => $channels === [] ? ['database'] : $channels,
                'enabled' => true,
                'created_at' => now()->toISOString(),
            ]);
            $settings['ai_workflows'] = $rules;

            return $settings;
        });

        return $refused;
    }

    public function remove(int $spreadsheetId, string $id): void
    {
        Spreadsheet::updateSettings($spreadsheetId, function (array $settings) use ($id) {
            $rules = is_array($settings['ai_workflows'] ?? null) ? $settings['ai_workflows'] : [];
            $settings['ai_workflows'] = array_values(array_filter($rules, fn ($rule) => is_array($rule) && ($rule['id'] ?? null) !== $id));

            return $settings;
        });
    }
}
