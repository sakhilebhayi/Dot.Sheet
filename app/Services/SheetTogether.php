<?php

namespace App\Services;

use App\Models\Cell;
use App\Models\CellHistory;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * More than one person in a workbook. Everyone is asked about every so often, so this is not live: it says
 * who has the workbook open and whether anything changed, and it stops one person typing over another.
 */
class SheetTogether
{
    /** How long a page may go without asking and still count as here. */
    private const HERE_SECONDS = 60;

    /**
     * Part edits into those that still fit what is in the cell and those someone else got to first.
     *
     * @param  list<array<string, mixed>>  $edits  an edit with "was" says what the person last saw
     * @return array{0: list<array<string, mixed>>, 1: list<array{row: int, col: int, theirs: string, who: string|null}>}
     */
    public function sortOut(int $sheetTabId, array $edits): array
    {
        $fair = [];
        $conflicts = [];
        foreach ($edits as $edit) {
            $was = $edit['was'] ?? null;
            unset($edit['was']);
            if ($was === null) {
                $fair[] = $edit;

                continue;
            }

            $cell = Cell::where('sheet_tab_id', $sheetTabId)->where('row_index', $edit['row'])->where('col_index', $edit['col'])->first();
            $now = $cell === null ? '' : (string) ($cell->formula ?? $cell->raw_value);
            if ($now === $was || $now === $edit['value']) {
                $fair[] = $edit;

                continue;
            }

            $conflicts[] = ['row' => $edit['row'], 'col' => $edit['col'], 'theirs' => $now, 'who' => $this->lastEditor($cell)];
        }

        return [$fair, $conflicts];
    }

    private function lastEditor(?Cell $cell): ?string
    {
        if ($cell === null) {
            return null;
        }
        $userId = CellHistory::where('cell_id', $cell->id)->latest('id')->value('user_id');

        return $userId === null ? null : User::whereKey($userId)->value('name');
    }

    public function changedSince(int $spreadsheetId, ?string $since): bool
    {
        return $since !== null && DB::table('cells')->where('spreadsheet_id', $spreadsheetId)->where('updated_at', '>=', $since)->exists();
    }

    /**
     * Mark this person as here, and answer with who else is.
     *
     * @return list<string>
     */
    public function here(int $spreadsheetId, User $user): array
    {
        $key = "sheet-here:{$spreadsheetId}";
        $now = now()->timestamp;

        $people = array_filter((array) Cache::get($key, []), fn (array $person) => $now - $person['at'] < self::HERE_SECONDS);
        $people[$user->id] = ['name' => $user->name, 'at' => $now];
        Cache::put($key, $people, self::HERE_SECONDS * 2);

        unset($people[$user->id]);

        return array_column($people, 'name');
    }
}
