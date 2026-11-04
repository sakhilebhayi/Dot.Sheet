<?php

namespace App\Services;

use App\Models\SheetProtection;
use App\Models\Spreadsheet;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Protected sheets and cells. A keeper, the workbook's owner or someone with admin access, sets them and
 * is not stopped by them. Everyone else who can edit keeps what they can do elsewhere and is refused
 * here, on the server, whichever way the change arrives. Protection guards against changes: anyone who
 * can open the workbook can still read what is protected.
 */
class SheetProtections
{
    public const SHEET_MESSAGE = 'This sheet is protected. Ask the workbook\'s owner to change it.';

    public const CELLS_MESSAGE = 'Those cells are protected. Ask the workbook\'s owner to change them.';

    public const SOME_CELLS_MESSAGE = 'Part of this sheet is protected, so this cannot run on it. Ask the workbook\'s owner.';

    public const ALREADY_MESSAGE = 'This sheet is already protected.';

    public const MAX_ON_A_SHEET = 50;

    public function isKeeper(?User $user, Spreadsheet $spreadsheet): bool
    {
        return $user !== null && $user->can('manageSharing', $spreadsheet);
    }

    /**
     * @return list<array{id: int, whole: bool, reference: string, label: string|null, r1: int|null, c1: int|null, r2: int|null, c2: int|null}>
     */
    public function of(int $sheetTabId): array
    {
        return SheetProtection::where('sheet_tab_id', $sheetTabId)->orderBy('id')->get()
            ->map(fn (SheetProtection $p) => [
                'id' => (int) $p->id,
                'whole' => $p->isWholeSheet(),
                'reference' => $p->isWholeSheet() ? 'The whole sheet' : RangeText::of($this->area($p)),
                'label' => $p->label,
                'r1' => $p->start_row,
                'c1' => $p->start_col,
                'r2' => $p->end_row,
                'c2' => $p->end_col,
            ])->values()->all();
    }

    /**
     * @return array{locked: bool, ranges: list<array{r1: int, c1: int, r2: int, c2: int}>}
     */
    public function state(int $sheetTabId): array
    {
        $all = SheetProtection::where('sheet_tab_id', $sheetTabId)->get();

        return [
            'locked' => $all->contains(fn (SheetProtection $p) => $p->isWholeSheet()),
            'ranges' => $all->reject(fn (SheetProtection $p) => $p->isWholeSheet())->map(fn (SheetProtection $p) => $this->area($p))->values()->all(),
        ];
    }

    /**
     * @return list<int> the sheets of this workbook protected whole
     */
    public function lockedTabs(int $spreadsheetId): array
    {
        return SheetProtection::where('spreadsheet_id', $spreadsheetId)->whereNull('start_row')
            ->pluck('sheet_tab_id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * @throws ProtectionRefused
     */
    public function protectSheet(Spreadsheet $spreadsheet, int $sheetTabId, int $userId): void
    {
        if (SheetProtection::where('sheet_tab_id', $sheetTabId)->whereNull('start_row')->exists()) {
            throw new ProtectionRefused(self::ALREADY_MESSAGE);
        }

        SheetProtection::create(['spreadsheet_id' => $spreadsheet->id, 'sheet_tab_id' => $sheetTabId, 'created_by' => $userId]);
    }

    /**
     * @throws ProtectionRefused
     */
    public function protectRange(Spreadsheet $spreadsheet, int $sheetTabId, string $range, ?string $label, int $userId): void
    {
        $area = RangeText::parse($range);
        if ($area === null) {
            throw new ProtectionRefused('Range must look like A1 or A1:B10.');
        }
        if (SheetProtection::where('sheet_tab_id', $sheetTabId)->count() >= self::MAX_ON_A_SHEET) {
            throw new ProtectionRefused('A sheet can hold '.self::MAX_ON_A_SHEET.' protected ranges. Remove one first.');
        }

        SheetProtection::create([
            'spreadsheet_id' => $spreadsheet->id,
            'sheet_tab_id' => $sheetTabId,
            'created_by' => $userId,
            'label' => $label === null || trim($label) === '' ? null : trim($label),
            'start_row' => $area['r1'],
            'start_col' => $area['c1'],
            'end_row' => $area['r2'],
            'end_col' => $area['c2'],
        ]);
    }

    /**
     * The sheet as a whole may not change.
     *
     * @throws ProtectionRefused
     */
    public function refuseSheet(Spreadsheet $spreadsheet, int $sheetTabId, ?User $user): void
    {
        if (! $this->isKeeper($user, $spreadsheet) && SheetProtection::where('sheet_tab_id', $sheetTabId)->whereNull('start_row')->exists()) {
            throw new ProtectionRefused(self::SHEET_MESSAGE);
        }
    }

    /**
     * For what rewrites a sheet with no list of its cells, such as a restore or an import.
     *
     * @throws ProtectionRefused
     */
    public function refuseAny(Spreadsheet $spreadsheet, int $sheetTabId, ?User $user): void
    {
        $this->refuseSheet($spreadsheet, $sheetTabId, $user);
        if (! $this->isKeeper($user, $spreadsheet) && SheetProtection::where('sheet_tab_id', $sheetTabId)->exists()) {
            throw new ProtectionRefused(self::SOME_CELLS_MESSAGE);
        }
    }

    /**
     * Every cell a change would write, on each sheet it writes to, and every area it would merge.
     *
     * @param  array<int, list<array{row: int, col: int}>>  $plans  sheet id => edits
     * @param  array<int, array{add?: list<array{r1: int, r2: int, c1: int, c2: int}>, remove?: list<array{r1: int, r2: int, c1: int, c2: int}>}>  $merges
     *
     * @throws ProtectionRefused
     */
    public function refuseChange(Spreadsheet $spreadsheet, array $plans, array $merges, ?User $user): void
    {
        $tabs = array_unique([...array_keys($plans), ...array_keys($merges)]);
        $all = SheetProtection::whereIn('sheet_tab_id', $tabs)->get()->groupBy('sheet_tab_id');
        if ($all->isEmpty() || $this->isKeeper($user, $spreadsheet)) {
            return;
        }

        foreach ($all as $tab => $protections) {
            if ($protections->contains(fn (SheetProtection $p) => $p->isWholeSheet())) {
                throw new ProtectionRefused(self::SHEET_MESSAGE);
            }
            $areas = $protections->map(fn (SheetProtection $p) => $this->area($p))->all();
            foreach ($plans[$tab] ?? [] as $edit) {
                foreach ($areas as $area) {
                    if ($edit['row'] >= $area['r1'] && $edit['row'] <= $area['r2'] && $edit['col'] >= $area['c1'] && $edit['col'] <= $area['c2']) {
                        throw new ProtectionRefused(self::CELLS_MESSAGE);
                    }
                }
            }
            foreach ([...($merges[$tab]['add'] ?? []), ...($merges[$tab]['remove'] ?? [])] as $merged) {
                foreach ($areas as $area) {
                    if ($this->overlap($merged, $area)) {
                        throw new ProtectionRefused(self::CELLS_MESSAGE);
                    }
                }
            }
        }
    }

    /**
     * Deleting rows or columns would take protected cells with it.
     *
     * @throws ProtectionRefused
     */
    public function refuseDelete(Spreadsheet $spreadsheet, int $sheetTabId, string $axis, int $at, int $count, ?User $user): void
    {
        if ($this->isKeeper($user, $spreadsheet)) {
            return;
        }

        $last = $at + $count - 1;
        [$from, $to] = $axis === 'row' ? ['start_row', 'end_row'] : ['start_col', 'end_col'];
        if (SheetProtection::where('sheet_tab_id', $sheetTabId)->whereNotNull('start_row')->where($from, '<=', $last)->where($to, '>=', $at)->exists()) {
            throw new ProtectionRefused(self::CELLS_MESSAGE);
        }
    }

    /**
     * @return list<array{0: int, 1: int, 2: int, 3: int, 4: int}> id and where it stands
     */
    public function placesOf(int $sheetTabId): array
    {
        return SheetProtection::where('sheet_tab_id', $sheetTabId)->whereNotNull('start_row')->orderBy('id')->get()
            ->map(fn (SheetProtection $p) => [(int) $p->id, (int) $p->start_row, (int) $p->start_col, (int) $p->end_row, (int) $p->end_col])->all();
    }

    /**
     * @param  list<array{0: int, 1: int, 2: int, 3: int, 4: int}>  $places
     */
    public function restorePlaces(int $sheetTabId, array $places): void
    {
        foreach ($places as [$id, $r1, $c1, $r2, $c2]) {
            DB::table('sheet_protections')->where('id', $id)->where('sheet_tab_id', $sheetTabId)
                ->update(['start_row' => $r1, 'start_col' => $c1, 'end_row' => $r2, 'end_col' => $c2]);
        }
    }

    /**
     * @return array{r1: int, c1: int, r2: int, c2: int}
     */
    private function area(SheetProtection $protection): array
    {
        return ['r1' => (int) $protection->start_row, 'c1' => (int) $protection->start_col, 'r2' => (int) $protection->end_row, 'c2' => (int) $protection->end_col];
    }

    /**
     * @param  array{r1: int, r2: int, c1: int, c2: int}  $a
     * @param  array{r1: int, r2: int, c1: int, c2: int}  $b
     */
    private function overlap(array $a, array $b): bool
    {
        return $a['r1'] <= $b['r2'] && $a['r2'] >= $b['r1'] && $a['c1'] <= $b['c2'] && $a['c2'] >= $b['c1'];
    }
}
