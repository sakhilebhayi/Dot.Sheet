<?php

namespace App\Services;

use App\Models\Cell;
use App\Models\Comment;
use App\Models\SheetTab;
use App\Models\Spreadsheet;
use App\Services\Formula\FormulaText;
use App\Services\Formula\Recalculator;
use Illuminate\Support\Facades\DB;

/**
 * Adding, renaming and removing the sheets of a workbook. A formula names a sheet by its text, so a
 * rename rewrites the formulas that name it, and a name has to be one a formula can tell apart.
 */
class WorkbookSheets
{
    /** The longest sheet name Excel takes, so a name survives the trip to a file. */
    public const MAX_NAME = 31;

    /** Most formulas one rename may rewrite: it holds the write lock while it does. */
    public const MAX_REWRITTEN = 20000;

    public const LAST_SHEET_MESSAGE = 'A workbook keeps at least one sheet.';

    public function __construct(private Recalculator $recalculator) {}

    /**
     * Why a name cannot be used, or null when it can.
     */
    public function refusal(int $spreadsheetId, string $name, ?int $exceptTabId = null): ?string
    {
        if ($name === '') {
            return 'A sheet needs a name.';
        }
        if (mb_strlen($name) > self::MAX_NAME) {
            return 'Sheet names run to '.self::MAX_NAME.' characters at most.';
        }
        if (preg_match('/[\[\]:*?\/\\\\]/', $name) === 1 || str_starts_with($name, "'") || str_ends_with($name, "'")) {
            return 'Sheet names cannot hold [ ] : * ? / \\ or start or end with an apostrophe.';
        }

        $taken = SheetTab::where('spreadsheet_id', $spreadsheetId)
            ->when($exceptTabId !== null, fn ($tabs) => $tabs->whereKeyNot($exceptTabId))
            ->pluck('name')
            ->contains(fn (string $other) => mb_strtolower($other) === mb_strtolower($name));

        return $taken ? 'Another sheet already has that name.' : null;
    }

    public function add(Spreadsheet $spreadsheet, ?string $name): SheetTab
    {
        $tabs = $spreadsheet->sheetTabs();
        $tab = SheetTab::create([
            'spreadsheet_id' => $spreadsheet->id,
            'name' => $name ?? $this->freeName($spreadsheet->id),
            'sort_order' => (int) $tabs->max('sort_order') + 1,
        ]);

        // A formula that named this sheet before it existed answered #REF!: it can be worked out now.
        $this->recalculator->markWorkbookStale($spreadsheet->id);

        return $tab;
    }

    /**
     * Call inside the write transaction.
     *
     * @throws StructureChangeRefused
     */
    public function rename(SheetTab $tab, string $name): void
    {
        $spreadsheetId = (int) $tab->spreadsheet_id;
        $from = (string) $tab->name;

        // Every formula that reaches another sheet has a ! in it: only those can name this one.
        $reaching = Cell::where('spreadsheet_id', $spreadsheetId)->whereNotNull('formula')->where('formula', 'like', '%!%');
        if ($reaching->count() > self::MAX_REWRITTEN) {
            throw new StructureChangeRefused('This workbook has too many formulas between its sheets to rename one here.');
        }

        foreach ($reaching->select(['id', 'formula'])->lazyById(1000) as $cell) {
            $written = FormulaText::withSheetRenamed((string) $cell->formula, $from, $name);
            if ($written !== $cell->formula) {
                DB::table('cells')->where('id', $cell->id)->update(['formula' => $written]);
            }
        }

        SheetTab::whereKey($tab->id)->update(['name' => $name]);
        StructureVersion::bump($spreadsheetId);
        $this->recalculator->markWorkbookStale($spreadsheetId);
    }

    /**
     * What removing a sheet would cost: the filled cells and comments that go, and the formulas on other
     * sheets that read it and will answer #REF!.
     *
     * @return array{cells: int, readers: int, comments: int}
     */
    public function cost(SheetTab $tab): array
    {
        $reading = DB::table('cell_precedents')
            ->where('precedent_tab_id', $tab->id)->where('sheet_tab_id', '!=', $tab->id)
            ->select('sheet_tab_id', 'row_index', 'col_index')->distinct();
        $readers = DB::query()->fromSub($reading, 'readers')->count();

        return [
            'cells' => Cell::where('sheet_tab_id', $tab->id)->whereNull('spill_row')->count(),
            'readers' => $readers,
            'comments' => Comment::where('sheet_tab_id', $tab->id)->whereNull('parent_id')->count(),
        ];
    }

    /**
     * Call inside the write transaction. The sheet's copies go with it, so this cannot be taken back.
     *
     * @throws StructureChangeRefused
     */
    public function remove(SheetTab $tab): void
    {
        $spreadsheetId = (int) $tab->spreadsheet_id;
        if (SheetTab::where('spreadsheet_id', $spreadsheetId)->count() <= 1) {
            throw new StructureChangeRefused(self::LAST_SHEET_MESSAGE);
        }

        $tab->delete();
        StructureVersion::bump($spreadsheetId);
        $this->recalculator->markWorkbookStale($spreadsheetId);
    }

    private function freeName(int $spreadsheetId): string
    {
        $count = SheetTab::where('spreadsheet_id', $spreadsheetId)->count();
        do {
            $name = 'Sheet'.++$count;
        } while ($this->refusal($spreadsheetId, $name) !== null);

        return $name;
    }
}
