<?php

namespace App\Services;

use App\Models\SheetTab;
use App\Models\Spreadsheet;
use App\Models\User;
use App\Services\Formula\Recalculator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Duplicating a sheet or a workbook. A duplicate holds the work: cells, how they look, the sheet's
 * charts and pivots. It leaves the conversation and the record behind: comments and history stay
 * with the original, and a workbook's duplicate starts private, with no people and no public link.
 */
class WorkbookDuplicates
{
    /** The workbook keys that hold code, which run as whoever presses Run. See R35_PARITY.md. */
    private const CODE_SETTINGS = ['scripts'];

    private const MAX_NAME_TRIES = 200;

    public function __construct(private WorkbookSheets $sheets, private Recalculator $recalculator) {}

    /**
     * Call inside the write transaction. The duplicate sits straight after the sheet it came from.
     *
     * @throws DuplicateRefused
     */
    public function sheet(SheetTab $tab): SheetTab
    {
        $spreadsheet = Spreadsheet::findOrFail($tab->spreadsheet_id);
        $this->guard(DB::table('cells')->where('sheet_tab_id', $tab->id)->count(), 'This sheet');

        $copy = $this->copyTab($tab, $spreadsheet->id, $this->freeName($tab), hidden: false);
        foreach (['chart_configs', 'pivot_tables'] as $table) {
            $this->copyRows($table, $tab->id, $spreadsheet->id, $copy->id);
        }

        $order = array_column($this->sheets->listOf($spreadsheet), 'id');
        $order = array_values(array_diff($order, [$copy->id]));
        array_splice($order, (int) array_search($tab->id, $order, true) + 1, 0, [$copy->id]);
        $this->sheets->reorder($spreadsheet, $order);

        // A formula that named this sheet before it existed answered #REF!: it can be worked out now.
        $this->recalculator->markWorkbookStale($spreadsheet->id);

        return $copy;
    }

    /**
     * Call inside the write transaction. Its formulas are worked out when each sheet is first read.
     *
     * @throws DuplicateRefused
     */
    public function workbook(Spreadsheet $source, User $user): Spreadsheet
    {
        $this->guard(DB::table('cells')->where('spreadsheet_id', $source->id)->count(), 'This workbook');

        $settings = is_array($source->settings) ? $source->settings : [];
        if (! $user->can('update', $source)) {
            $settings = array_diff_key($settings, array_flip(self::CODE_SETTINGS));
        }

        $copy = new Spreadsheet([
            'uuid' => (string) Str::uuid(),
            'owner_id' => $user->id,
            'team_id' => $source->team && $user->can('createSpreadsheet', $source->team) ? $source->team_id : null,
            'name' => Str::limit((string) $source->name, 250, '').' copy',
            'settings' => $settings,
        ]);
        // Quietly: a new workbook starts with an empty sheet, and this one has its own.
        $copy->saveQuietly();

        foreach (SheetTab::where('spreadsheet_id', $source->id)->orderBy('sort_order')->orderBy('id')->get() as $tab) {
            $to = $this->copyTab($tab, $copy->id, (string) $tab->name, hidden: (bool) $tab->hidden);
            foreach (['chart_configs', 'pivot_tables', 'named_ranges'] as $table) {
                $this->copyRows($table, $tab->id, $copy->id, $to->id);
            }
        }

        return $copy;
    }

    private function guard(int $cells, string $what): void
    {
        $limit = (int) config('sheet.duplicate_max_cells');
        if ($limit > 0 && $cells > $limit) {
            throw new DuplicateRefused(sprintf('%s has %s cells. A duplicate can hold %s at most.', $what, number_format($cells), number_format($limit)));
        }
    }

    private function copyTab(SheetTab $tab, int $spreadsheetId, string $name, bool $hidden): SheetTab
    {
        $copy = $tab->replicate(['formulas_built_at']);
        $copy->spreadsheet_id = $spreadsheetId;
        $copy->name = $name;
        $copy->hidden = $hidden;
        $copy->save();

        $this->copyRows('cells', $tab->id, $spreadsheetId, $copy->id);

        return $copy;
    }

    /** Every column but the keys, so a column added later comes across without anyone remembering it. */
    private function copyRows(string $table, int $fromTab, int $spreadsheetId, int $toTab): void
    {
        $columns = array_values(array_diff(Schema::getColumnListing($table), ['id', 'spreadsheet_id', 'sheet_tab_id']));

        DB::table($table)->insertUsing(
            [...$columns, 'spreadsheet_id', 'sheet_tab_id'],
            DB::table($table)->where('sheet_tab_id', $fromTab)->select($columns)
                ->selectRaw('? as spreadsheet_id, ? as sheet_tab_id', [$spreadsheetId, $toTab])
        );
    }

    /**
     * The sheet's name with what a name may not hold taken out, as a sheet from before those rules may
     * have it. The number of tries has an end because this runs holding the write lock.
     *
     * @throws DuplicateRefused
     */
    private function freeName(SheetTab $tab): string
    {
        $spreadsheetId = (int) $tab->spreadsheet_id;
        $stem = preg_replace('/[\[\]:*?\/\\\\]/', '', (string) $tab->name) ?? '';

        for ($count = 1; $count <= self::MAX_NAME_TRIES; $count++) {
            $ending = $count === 1 ? ' copy' : " copy {$count}";
            $name = trim(rtrim(mb_substr($stem, 0, WorkbookSheets::MAX_NAME - mb_strlen($ending)), " '").$ending, " '");
            if ($this->sheets->refusal($spreadsheetId, $name) === null) {
                return $name;
            }
        }

        throw new DuplicateRefused('This sheet has too many duplicates already. Rename some and try again.');
    }
}
