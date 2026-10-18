<?php

namespace App\Services;

use App\Models\Spreadsheet;

/**
 * A counter that goes up every time a row or column is inserted or deleted in any tab of a
 * spreadsheet. It lives in the spreadsheet's settings JSON (no column, no migration).
 *
 * A browser that opened the sheet before the change still shows cells at their old
 * coordinates. Every component that writes cells remembers the version it loaded and
 * compares it with the stored one first; when they differ the write is refused and the grid
 * is reloaded, so a stale screen can never overwrite data that has moved.
 */
class StructureVersion
{
    public const STALE_MESSAGE = 'Rows or columns were inserted or deleted elsewhere. The sheet was reloaded; please repeat your change.';

    public static function current(int $spreadsheetId): int
    {
        $settings = Spreadsheet::query()->whereKey($spreadsheetId)->first(['id', 'settings'])?->settings;

        return is_array($settings) ? (int) ($settings['structure_version'] ?? 0) : 0;
    }

    /**
     * Add one to the counter. Call inside the transaction that moves the cells.
     */
    public static function bump(int $spreadsheetId): int
    {
        $spreadsheet = Spreadsheet::query()->lockForUpdate()->findOrFail($spreadsheetId);

        $settings = is_array($spreadsheet->settings) ? $spreadsheet->settings : [];
        $settings['structure_version'] = (int) ($settings['structure_version'] ?? 0) + 1;

        $spreadsheet->settings = $settings;
        $spreadsheet->save();

        return $settings['structure_version'];
    }
}
