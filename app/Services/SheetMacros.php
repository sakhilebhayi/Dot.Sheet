<?php

namespace App\Services;

use App\Models\Spreadsheet;

/**
 * Macros of a spreadsheet, in both editors: recorded cell edits, played back by whoever presses Play and
 * subject to what that person may do. A macro is data and never code, so what is stored is checked here,
 * whichever way it arrived. The list is changed inside the write lock, so two people saving at once
 * cannot lose each other's work.
 */
class SheetMacros
{
    public const MAX_ACTIONS = 1000;

    public const MAX_MACROS = 100;

    private const MAX_VALUE = 32767;

    /**
     * @return list<array<string, mixed>> as stored, which for an old macro may be anything
     */
    public function of(Spreadsheet $spreadsheet): array
    {
        $settings = is_array($spreadsheet->settings) ? $spreadsheet->settings : [];

        return array_values(array_filter(is_array($settings['macros'] ?? null) ? $settings['macros'] : [], 'is_array'));
    }

    /**
     * @param  array<int|string, mixed>  $actions
     * @return string|null why it was refused, or null when it was saved
     */
    public function add(int $spreadsheetId, string $name, array $actions): ?string
    {
        $clean = $this->clean($actions);
        if ($clean === []) {
            return 'Nothing was recorded.';
        }

        $name = trim($name) !== '' ? mb_substr(trim($name), 0, 80) : 'Macro '.now()->format('H:i:s');
        $refused = null;

        Spreadsheet::updateSettings($spreadsheetId, function (array $settings) use ($name, $clean, &$refused) {
            $macros = is_array($settings['macros'] ?? null) ? $settings['macros'] : [];
            if (count($macros) >= self::MAX_MACROS) {
                $refused = 'This spreadsheet has '.self::MAX_MACROS.' macros, the most it can keep. Delete one first.';

                return $settings;
            }

            array_unshift($macros, ['id' => (string) str()->uuid(), 'name' => $name, 'actions' => $clean, 'created_at' => now()->toISOString()]);
            $settings['macros'] = $macros;

            return $settings;
        });

        return $refused;
    }

    public function remove(int $spreadsheetId, string $id): void
    {
        Spreadsheet::updateSettings($spreadsheetId, function (array $settings) use ($id) {
            $macros = is_array($settings['macros'] ?? null) ? $settings['macros'] : [];
            $settings['macros'] = array_values(array_filter($macros, fn ($macro) => ! is_array($macro) || ($macro['id'] ?? null) !== $id));

            return $settings;
        });
    }

    /**
     * Only what a macro can do: set a cell, or move to one, on the sheet. Anything else is left out.
     *
     * @param  array<int|string, mixed>  $actions
     * @return list<array<string, mixed>>
     */
    public function clean(array $actions): array
    {
        $clean = [];
        foreach ($actions as $action) {
            if (count($clean) >= self::MAX_ACTIONS) {
                break;
            }
            if (! is_array($action) || ! in_array($action['type'] ?? null, ['set', 'select'], true)) {
                continue;
            }

            $row = $action['row'] ?? null;
            $col = $action['col'] ?? null;
            if (! is_int($row) || ! is_int($col) || $row < 0 || $col < 0 || $row >= SheetStructureService::GRID_ROWS || $col >= SheetStructureService::GRID_COLS) {
                continue;
            }

            if ($action['type'] === 'select') {
                $clean[] = ['type' => 'select', 'row' => $row, 'col' => $col];

                continue;
            }

            $value = $action['value'] ?? '';
            if (is_scalar($value) && strlen((string) $value) <= self::MAX_VALUE) {
                $clean[] = ['type' => 'set', 'row' => $row, 'col' => $col, 'value' => (string) $value];
            }
        }

        return $clean;
    }
}
