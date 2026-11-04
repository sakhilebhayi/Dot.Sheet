<?php

namespace Tests\Feature;

use App\Models\ChartConfig;
use App\Models\Comment;
use App\Models\NamedRange;
use App\Models\PivotTable;
use App\Models\SheetTab;
use App\Models\Spreadsheet;
use App\Models\User;
use App\Services\SheetVersions;
use App\Services\WorkbookSheets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class GridDuplicatesTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Spreadsheet $sheet;

    private SheetTab $sales;

    private SheetTab $stock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->withPersonalTeam()->create();
        $this->sheet = Spreadsheet::create(['uuid' => (string) Str::uuid(), 'owner_id' => $this->owner->id, 'team_id' => $this->owner->currentTeam->id, 'name' => 'Budget', 'settings' => ['macros' => [['name' => 'Tidy']], 'scripts' => [['name' => 'Send']]]]);
        $this->sales = $this->sheet->sheetTabs()->firstOrFail();
        $this->sales->update(['name' => 'Sales', 'color' => 'teal']);
        SheetTab::updateLayout($this->sales->id, fn () => ['col_widths' => ['0' => 200], 'frozen_rows' => 1]);
        $this->stock = app(WorkbookSheets::class)->add($this->sheet, 'Stock');
        $this->actingAs($this->owner);

        $this->write($this->sales, 0, 0, 'Region');
        $this->write($this->sales, 1, 0, '5');
        $this->write($this->sales, 2, 0, '=A2*2');
        $this->write($this->stock, 0, 0, '=Sales!A2+1');
        DB::table('cells')->where('sheet_tab_id', $this->sales->id)->where('row_index', 0)
            ->update(['formatting' => json_encode(['bold' => true]), 'note' => 'Check this']);

        ChartConfig::create(['spreadsheet_id' => $this->sheet->id, 'sheet_tab_id' => $this->sales->id, 'title' => 'Sales chart', 'type' => 'bar', 'data_range' => 'A1:A3']);
        PivotTable::create(['spreadsheet_id' => $this->sheet->id, 'sheet_tab_id' => $this->sales->id, 'title' => 'Sales pivot', 'source_range' => 'A1:A3', 'row_field' => 'Region', 'value_field' => 'Region']);
        NamedRange::create(['spreadsheet_id' => $this->sheet->id, 'sheet_tab_id' => $this->sales->id, 'name' => 'Takings', 'start_row' => 1, 'start_col' => 0, 'end_row' => 2, 'end_col' => 0]);
        Comment::create(['spreadsheet_id' => $this->sheet->id, 'sheet_tab_id' => $this->sales->id, 'user_id' => $this->owner->id, 'row_index' => 0, 'col_index' => 0, 'content' => 'A remark']);
        app(SheetVersions::class)->snapshot($this->sheet->id, $this->sales->id, $this->owner->id, SheetVersions::MANUAL, 'Kept');
    }

    private function base(SheetTab $tab): string
    {
        return "/spreadsheets/{$tab->spreadsheet_id}/tabs/{$tab->id}";
    }

    private function write(SheetTab $tab, int $row, int $col, string $value): void
    {
        $version = $this->getJson($this->base($tab).'/sheet')->assertOk()->json('version');

        $this->postJson($this->base($tab).'/cells', ['version' => $version, 'edits' => [['row' => $row, 'col' => $col, 'value' => $value]]])->assertOk();
    }

    /** What the sheet holds once someone has opened it. */
    private function value(SheetTab $tab, int $row, int $col): ?string
    {
        $this->getJson($this->base($tab).'/sheet')->assertOk();

        return DB::table('cells')->where('sheet_tab_id', $tab->id)->where('row_index', $row)->where('col_index', $col)->value('computed_value');
    }

    private function duplicateSheet(SheetTab $tab): SheetTab
    {
        $id = $this->postJson("/spreadsheets/{$tab->spreadsheet_id}/tabs/{$tab->id}/duplicate")->assertOk()->json('tab');

        return SheetTab::findOrFail($id);
    }

    private function duplicateWorkbook(): Spreadsheet
    {
        $url = $this->postJson("/spreadsheets/{$this->sheet->id}/duplicate")->assertOk()->json('url');

        return Spreadsheet::findOrFail((int) basename($url));
    }

    /** @return list<string> */
    private function names(Spreadsheet $spreadsheet): array
    {
        return array_column(app(WorkbookSheets::class)->listOf($spreadsheet), 'name');
    }

    public function test_a_sheet_duplicate_sits_beside_the_original_and_holds_the_work(): void
    {
        $copy = $this->duplicateSheet($this->sales);

        $this->assertSame(['Sales', 'Sales copy', 'Stock'], $this->names($this->sheet));
        $this->assertSame('teal', $copy->color);
        $this->assertSame(['col_widths' => ['0' => 200], 'frozen_rows' => 1], $copy->layout);

        $cell = DB::table('cells')->where('sheet_tab_id', $copy->id)->where('row_index', 0)->first();
        $this->assertSame('Region', $cell->raw_value);
        $this->assertSame('Check this', $cell->note);
        $this->assertSame(['bold' => true], json_decode($cell->formatting, true));
        $this->assertSame(3, DB::table('cells')->where('sheet_tab_id', $copy->id)->count());
        $this->assertSame(3, DB::table('cells')->where('sheet_tab_id', $this->sales->id)->count());

        $this->assertSame('A1:A3', ChartConfig::where('sheet_tab_id', $copy->id)->sole()->data_range);
        $this->assertSame('Sales pivot', PivotTable::where('sheet_tab_id', $copy->id)->sole()->title);
    }

    public function test_a_sheet_duplicate_leaves_comments_history_and_names_behind(): void
    {
        $copy = $this->duplicateSheet($this->sales);

        $this->assertSame(0, Comment::where('sheet_tab_id', $copy->id)->count());
        $this->assertSame(0, DB::table('spreadsheet_versions')->where('sheet_tab_id', $copy->id)->count());
        $this->assertSame(0, NamedRange::where('sheet_tab_id', $copy->id)->count());
    }

    public function test_a_sheet_duplicate_lives_its_own_life(): void
    {
        $copy = $this->duplicateSheet($this->sales);

        $this->assertSame('10', $this->value($copy, 2, 0));

        $this->write($copy, 1, 0, '7');

        $this->assertSame('14', $this->value($copy, 2, 0));
        $this->assertSame('10', $this->value($this->sales, 2, 0));
    }

    public function test_the_original_sheet_keeps_working_after_a_duplicate(): void
    {
        $this->duplicateSheet($this->sales);
        $this->write($this->sales, 1, 0, '8');

        $this->assertSame('16', $this->value($this->sales, 2, 0));
    }

    public function test_a_formula_waiting_for_the_new_name_finds_it(): void
    {
        $this->write($this->stock, 3, 0, "='Sales copy'!A2");
        $this->assertNotSame('5', $this->value($this->stock, 3, 0));

        $this->duplicateSheet($this->sales);

        $this->assertSame('5', $this->value($this->stock, 3, 0));
    }

    public function test_a_duplicate_of_a_hidden_sheet_shows(): void
    {
        $this->stock->update(['hidden' => true]);

        $this->assertFalse($this->duplicateSheet($this->stock)->hidden);
    }

    public function test_duplicates_get_free_names_that_fit(): void
    {
        $this->assertSame('Sales copy', $this->duplicateSheet($this->sales)->name);
        $this->assertSame('Sales copy 2', $this->duplicateSheet($this->sales)->name);
        $this->assertSame('Sales copy copy', $this->duplicateSheet(SheetTab::where('name', 'Sales copy')->firstOrFail())->name);

        $this->sales->update(['name' => str_repeat('a', WorkbookSheets::MAX_NAME)]);
        $long = $this->duplicateSheet($this->sales);
        $this->assertSame(str_repeat('a', WorkbookSheets::MAX_NAME - 5).' copy', $long->name);
        $this->assertSame(str_repeat('a', WorkbookSheets::MAX_NAME - 7).' copy 2', $this->duplicateSheet($this->sales)->name);
    }

    public function test_a_second_duplicate_sits_straight_after_the_original_too(): void
    {
        $this->duplicateSheet($this->sales);
        $this->duplicateSheet($this->sales);

        $this->assertSame(['Sales', 'Sales copy 2', 'Sales copy', 'Stock'], $this->names($this->sheet));
    }

    public function test_a_name_from_before_the_rules_still_duplicates(): void
    {
        DB::table('sheet_tabs')->where('id', $this->sales->id)->update(['name' => "'Q1/2025: [final]?'"]);

        $this->assertSame('Q12025 final copy', $this->duplicateSheet($this->sales->fresh())->name);
    }

    public function test_a_name_of_nothing_but_forbidden_marks_still_duplicates(): void
    {
        DB::table('sheet_tabs')->where('id', $this->sales->id)->update(['name' => '***']);

        $this->assertSame('copy', $this->duplicateSheet($this->sales->fresh())->name);
    }

    public function test_only_people_who_can_edit_duplicate_a_sheet(): void
    {
        foreach (['view', 'comment'] as $permission) {
            $user = User::factory()->withPersonalTeam()->create();
            $this->sheet->sharedUsers()->attach($user->id, ['permission' => $permission]);

            $this->actingAs($user)->postJson("/spreadsheets/{$this->sheet->id}/tabs/{$this->sales->id}/duplicate")->assertForbidden();
        }

        $this->assertSame(2, $this->sheet->sheetTabs()->count());
    }

    public function test_a_sheet_is_duplicated_only_through_its_own_workbook(): void
    {
        $other = Spreadsheet::create(['uuid' => (string) Str::uuid(), 'owner_id' => $this->owner->id, 'name' => 'Other']);

        $this->postJson("/spreadsheets/{$other->id}/tabs/{$this->sales->id}/duplicate")->assertNotFound();
    }

    public function test_a_duplicate_too_large_to_hold_is_refused_in_words(): void
    {
        config(['sheet.duplicate_max_cells' => 2]);

        $this->postJson("/spreadsheets/{$this->sheet->id}/tabs/{$this->sales->id}/duplicate")
            ->assertUnprocessable()->assertJsonPath('message', 'This sheet has 3 cells. A duplicate can hold 2 at most.');
        $this->postJson("/spreadsheets/{$this->sheet->id}/duplicate")
            ->assertUnprocessable()->assertJsonPath('message', 'This workbook has 4 cells. A duplicate can hold 2 at most.');

        $this->assertSame(2, $this->sheet->sheetTabs()->count());
        $this->assertSame(1, Spreadsheet::count());

        $this->post("/spreadsheets/{$this->sheet->id}/duplicate")->assertRedirect()->assertSessionHas('error');
    }

    public function test_a_workbook_duplicate_holds_every_sheet_and_the_work_on_it(): void
    {
        $this->stock->update(['hidden' => true, 'color' => 'plum']);

        $copy = $this->duplicateWorkbook();

        $this->assertSame('Budget copy', $copy->name);
        $this->assertSame(['Sales', 'Stock'], $this->names($copy), 'and no empty sheet beside them');
        [$sales, $stock] = [$copy->sheetTabs()->where('name', 'Sales')->sole(), $copy->sheetTabs()->where('name', 'Stock')->sole()];
        $this->assertTrue($stock->hidden);
        $this->assertSame('plum', $stock->color);
        $this->assertSame(['col_widths' => ['0' => 200], 'frozen_rows' => 1], $sales->layout);
        $this->assertSame(4, DB::table('cells')->where('spreadsheet_id', $copy->id)->count());
        $this->assertSame(3, DB::table('cells')->where('sheet_tab_id', $sales->id)->count());
        $this->assertSame(1, ChartConfig::where('sheet_tab_id', $sales->id)->where('spreadsheet_id', $copy->id)->count());
        $this->assertSame(1, PivotTable::where('sheet_tab_id', $sales->id)->where('spreadsheet_id', $copy->id)->count());
        $this->assertSame('Takings', NamedRange::where('sheet_tab_id', $sales->id)->where('spreadsheet_id', $copy->id)->sole()->name);
        $this->assertSame([['name' => 'Tidy']], $copy->settings['macros']);
    }

    public function test_a_workbook_duplicate_starts_private_and_with_no_record(): void
    {
        $friend = User::factory()->create();
        $this->sheet->sharedUsers()->attach($friend->id, ['permission' => 'edit']);
        $this->sheet->update(['public_enabled' => true, 'public_token' => 'tok']);

        $copy = $this->duplicateWorkbook();

        $this->assertSame($this->owner->id, $copy->owner_id);
        $this->assertSame(0, $copy->sharedUsers()->count());
        $this->assertFalse((bool) $copy->public_enabled);
        $this->assertNull($copy->public_token);
        $this->assertSame(0, Comment::where('spreadsheet_id', $copy->id)->count());
        $this->assertSame(0, DB::table('spreadsheet_versions')->where('spreadsheet_id', $copy->id)->count());
    }

    public function test_a_workbook_duplicate_works_out_its_own_formulas(): void
    {
        $copy = $this->duplicateWorkbook();
        [$sales, $stock] = [$copy->sheetTabs()->where('name', 'Sales')->sole(), $copy->sheetTabs()->where('name', 'Stock')->sole()];
        $this->assertSame('10', $this->value($sales, 2, 0));
        $this->assertSame('6', $this->value($stock, 0, 0));

        $this->write($sales, 1, 0, '20');

        $this->assertSame('40', $this->value($sales, 2, 0));
        $this->assertSame('21', $this->value($stock, 0, 0));
        $this->assertSame('10', $this->value($this->sales, 2, 0));
        $this->assertSame('6', $this->value($this->stock, 0, 0));
    }

    public function test_a_workbook_duplicate_leaves_the_original_as_it_was(): void
    {
        $this->duplicateWorkbook();

        $this->assertSame(['Sales', 'Stock'], $this->names($this->sheet));
        $this->assertSame(4, DB::table('cells')->where('spreadsheet_id', $this->sheet->id)->count());
        $this->assertSame(1, Comment::where('spreadsheet_id', $this->sheet->id)->count());
    }

    public function test_someone_who_can_only_read_gets_a_private_copy_without_the_code(): void
    {
        $reader = User::factory()->withPersonalTeam()->create();
        $this->sheet->sharedUsers()->attach($reader->id, ['permission' => 'view']);
        $this->actingAs($reader);

        $copy = $this->duplicateWorkbook();

        $this->assertSame($reader->id, $copy->owner_id);
        $this->assertNull($copy->team_id);
        $this->assertArrayNotHasKey('scripts', $copy->settings);
        $this->assertSame([['name' => 'Tidy']], $copy->settings['macros']);
        $this->assertTrue($reader->can('update', $copy));
    }

    public function test_the_duplicate_stays_in_the_team_when_the_person_may_add_to_it(): void
    {
        $this->assertSame($this->sheet->team_id, $this->duplicateWorkbook()->team_id);
    }

    public function test_someone_who_cannot_open_a_workbook_cannot_duplicate_it(): void
    {
        $stranger = User::factory()->withPersonalTeam()->create();

        $this->actingAs($stranger)->postJson("/spreadsheets/{$this->sheet->id}/duplicate")->assertForbidden();

        $this->assertSame(1, Spreadsheet::count());
    }

    public function test_the_dashboard_offers_a_duplicate_and_lands_on_it(): void
    {
        $this->get('/dashboard')->assertOk()->assertSee(route('spreadsheets.duplicate', $this->sheet));

        $response = $this->post(route('spreadsheets.duplicate', $this->sheet));

        $copy = Spreadsheet::where('name', 'Budget copy')->sole();
        $response->assertRedirect(route('spreadsheets.show', $copy));
    }
}
