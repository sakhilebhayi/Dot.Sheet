<?php

namespace App\Services;

use App\Models\Spreadsheet;
use Illuminate\Support\Facades\DB;

/**
 * A counter (column spreadsheets.structure_version) that goes up every time rows or columns of any
 * tab of a spreadsheet move: insert, delete, sort, version restore, tab delete.
 *
 * A browser that opened the sheet before the change still shows cells at their old coordinates.
 * Every component that writes cells remembers the version it loaded and compares it with the stored
 * one first, inside the transaction that writes; when they differ the write is refused and the
 * grid is reloaded, so a stale screen can never overwrite data that has moved.
 *
 * It only ever changes through bump(), a single UPDATE ... SET structure_version = structure_version + 1,
 * and is not part of the settings JSON, so no settings writer can reset it.
 */
class StructureVersion
{
    public const STALE_MESSAGE = 'Rows or columns were inserted or deleted elsewhere. The sheet was reloaded; please repeat your change.';

    public const TAB_GONE_MESSAGE = 'This tab no longer exists; the sheet was reloaded.';

    /**
     * @param  bool  $lock  take the row lock where the engine has one (SQLite already holds the whole-database write lock inside an IMMEDIATE transaction)
     */
    public static function current(int $spreadsheetId, bool $lock = false): int
    {
        $query = Spreadsheet::query()->whereKey($spreadsheetId);
        if ($lock) {
            $query->lockForUpdate();
        }

        return (int) $query->value('structure_version');
    }

    /**
     * Run $write in one transaction that first compares the version the caller loaded with the stored
     * one under the write lock. False (nothing written) when they differ.
     */
    public static function writeIfCurrent(int $spreadsheetId, int $loadedVersion, callable $write): bool
    {
        try {
            DB::transaction(function () use ($spreadsheetId, $loadedVersion, $write) {
                if (self::current($spreadsheetId, lock: true) !== $loadedVersion) {
                    throw new StaleStructure(self::STALE_MESSAGE);
                }

                $write();
            });
        } catch (StaleStructure) {
            return false;
        }

        return true;
    }

    /**
     * Add one to the counter. Call inside the transaction that moves the cells.
     */
    public static function bump(int $spreadsheetId): int
    {
        Spreadsheet::query()->whereKey($spreadsheetId)->update(['structure_version' => DB::raw('structure_version + 1')]);

        return self::current($spreadsheetId);
    }
}
