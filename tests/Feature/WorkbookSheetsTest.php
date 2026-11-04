<?php

namespace Tests\Feature;

use App\Livewire\ShowSpreadsheet;
use App\Models\Cell;
use App\Models\SheetTab;
use App\Models\Spreadsheet;
use App\Models\User;
use App\Services\CellWriter;
use App\Services\Formula\FormulaText;
use App\Services\Formula\Recalculator;
use App\Services\StructureVersion;
use App\Services\WorkbookSheets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\TestCase;

class WorkbookSheetsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Spreadsheet $sheet;

    private SheetTab $first;

    private SheetTab $stock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->withPersonalTeam()->create();
        $this->sheet = Spreadsheet::create(['uuid' => (string) str()->uuid(), 'owner_id' => $this->owner->id, 'team_id' => $this->owner->currentTeam->id, 'name' => 'Sheets']);
        $this->first = $this->sheet->sheetTabs()->firstOrFail();
        $this->stock = SheetTab::create(['spreadsheet_id' => $this->sheet->id, 'name' => 'Stock', 'sort_order' => 2]);
        $this->actingAs($this->owner);

        app(CellWriter::class)->write($this->sheet->id, $this->stock->id, [['row' => 0, 'col' => 0, 'value' => '40']], $this->owner->id);
        app(CellWriter::class)->write($this->sheet->id, $this->first->id, [
            ['row' => 0, 'col' => 0, 'value' => '=Stock!A1+2'],
            ['row' => 1, 'col' => 0, 'value' => '="Stock!A1"'],
        ], $this->owner->id);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function ask(string $what, SheetTab $tab, array $body = []): TestResponse
    {
        return $this->postJson("/spreadsheets/{$this->sheet->id}/tabs/{$tab->id}/{$what}", ['version' => StructureVersion::current($this->sheet->id), ...$body]);
    }

    private function formula(int $row): ?string
    {
        return Cell::where('sheet_tab_id', $this->first->id)->where('row_index', $row)->value('formula');
    }

    private function answer(int $row): string
    {
        app(Recalculator::class)->ensureBuilt($this->sheet->id, $this->first->id);

        return (string) Cell::where('sheet_tab_id', $this->first->id)->where('row_index', $row)->value('computed_value');
    }

    public function test_adding_a_sheet_finds_a_free_name_after_the_last_one(): void
    {
        SheetTab::create(['spreadsheet_id' => $this->sheet->id, 'name' => 'Sheet3', 'sort_order' => 9]);

        $body = $this->postJson("/spreadsheets/{$this->sheet->id}/tabs")->assertOk()->json();

        $this->assertSame('Sheet4', SheetTab::findOrFail($body['tab'])->name);
        $this->assertSame('Sheet4', collect($body['tabs'])->last()['name']);
    }

    public function test_a_sheet_can_be_added_with_a_name_and_formulas_that_wanted_it_answer(): void
    {
        app(CellWriter::class)->write($this->sheet->id, $this->first->id, [['row' => 4, 'col' => 0, 'value' => '=Later!A1']], $this->owner->id);

        $this->postJson("/spreadsheets/{$this->sheet->id}/tabs", ['name' => 'Later'])->assertOk();

        $this->assertNotSame('#REF!', $this->answer(4));
    }

    public function test_renaming_rewrites_formulas_that_name_the_sheet_and_nothing_else(): void
    {
        $this->assertSame('42', $this->answer(0));

        $this->ask('rename', $this->stock, ['name' => 'Stock 2026'])->assertOk()->assertJsonPath('undo.name', 'Stock');

        $this->assertSame("='Stock 2026'!A1+2", $this->formula(0));
        $this->assertSame('="Stock!A1"', $this->formula(1), 'Text in quotes is not a reference.');
        $this->assertSame('42', $this->answer(0));
    }

    public function test_the_undo_of_a_rename_puts_the_formulas_back(): void
    {
        $undo = $this->ask('rename', $this->stock, ['name' => 'Stock 2026'])->json('undo.name');

        $this->ask('rename', $this->stock->fresh(), ['name' => $undo])->assertOk();

        $this->assertSame('=Stock!A1+2', $this->formula(0));
        $this->assertSame('42', $this->answer(0));
    }

    public function test_a_name_that_reads_as_a_cell_is_quoted_in_formulas(): void
    {
        $this->ask('rename', $this->stock, ['name' => 'Q1'])->assertOk();

        $this->assertSame("='Q1'!A1+2", $this->formula(0));
        $this->assertSame('42', $this->answer(0));
    }

    public function test_a_change_of_case_is_allowed_and_rewrites(): void
    {
        $this->ask('rename', $this->stock, ['name' => 'STOCK'])->assertOk();

        $this->assertSame('=STOCK!A1+2', $this->formula(0));
    }

    public function test_formulas_in_another_workbook_are_left_alone(): void
    {
        $other = Spreadsheet::create(['uuid' => (string) str()->uuid(), 'owner_id' => $this->owner->id, 'team_id' => $this->owner->currentTeam->id, 'name' => 'Other']);
        $theirs = $other->sheetTabs()->firstOrFail();
        app(CellWriter::class)->write($other->id, $theirs->id, [['row' => 0, 'col' => 0, 'value' => '=Stock!A1']], $this->owner->id);

        $this->ask('rename', $this->stock, ['name' => 'Goods'])->assertOk();

        $this->assertSame('=Stock!A1', Cell::where('sheet_tab_id', $theirs->id)->value('formula'));
    }

    public function test_the_classic_editor_rewrites_on_rename_too(): void
    {
        Livewire::test(ShowSpreadsheet::class, ['spreadsheet_id' => $this->sheet->id])->call('renameTab', $this->stock->id, 'Goods');

        $this->assertSame('Goods', $this->stock->fresh()->name);
        $this->assertSame('=Goods!A1+2', $this->formula(0));
    }

    public function test_names_a_formula_could_not_tell_apart_are_refused(): void
    {
        foreach (['', '   ', 'A/B', 'A:B', "'Quoted", str_repeat('x', 32)] as $name) {
            $this->ask('rename', $this->first, ['name' => $name])->assertStatus(422);
        }

        $this->ask('rename', $this->first, ['name' => 'STOCK'])->assertStatus(422)->assertJsonPath('message', 'Another sheet already has that name.');
        $this->postJson("/spreadsheets/{$this->sheet->id}/tabs", ['name' => 'stock'])->assertStatus(422);

        $this->assertSame('Sheet1', $this->first->fresh()->name);
    }

    public function test_a_stale_browser_renames_nothing(): void
    {
        $this->ask('rename', $this->stock, ['name' => 'Goods', 'version' => 999])->assertStatus(409);

        $this->assertSame('Stock', $this->stock->fresh()->name);
        $this->assertSame('=Stock!A1+2', $this->formula(0));
    }

    public function test_the_cost_of_deleting_counts_cells_and_the_formulas_that_read_it(): void
    {
        app(Recalculator::class)->ensureBuilt($this->sheet->id, $this->first->id);

        $this->getJson("/spreadsheets/{$this->sheet->id}/tabs/{$this->stock->id}/removal")->assertOk()->assertExactJson(['cells' => 1, 'readers' => 1, 'comments' => 0]);
    }

    public function test_deleting_a_sheet_takes_its_cells_and_breaks_what_read_it(): void
    {
        $before = StructureVersion::current($this->sheet->id);

        $this->ask('remove', $this->stock)->assertOk()->assertJsonCount(1, 'tabs');

        $this->assertNull(SheetTab::find($this->stock->id));
        $this->assertSame(0, Cell::where('sheet_tab_id', $this->stock->id)->count());
        $this->assertGreaterThan($before, StructureVersion::current($this->sheet->id));
        $this->assertSame('#REF!', $this->answer(0));
    }

    public function test_the_last_sheet_stays(): void
    {
        $this->ask('remove', $this->stock)->assertOk();

        $this->ask('remove', $this->first)->assertStatus(422)->assertJsonPath('message', WorkbookSheets::LAST_SHEET_MESSAGE);

        $this->assertNotNull(SheetTab::find($this->first->id));
    }

    public function test_a_stale_browser_deletes_nothing(): void
    {
        $this->ask('remove', $this->stock, ['version' => 999])->assertStatus(409);

        $this->assertNotNull(SheetTab::find($this->stock->id));
    }

    public function test_only_people_who_can_edit_may_change_sheets(): void
    {
        $viewer = User::factory()->withPersonalTeam()->create();
        $this->sheet->sharedUsers()->attach($viewer->id, ['permission' => 'view']);
        $this->actingAs($viewer);

        $this->postJson("/spreadsheets/{$this->sheet->id}/tabs")->assertForbidden();
        $this->ask('rename', $this->stock, ['name' => 'Mine'])->assertForbidden();
        $this->ask('remove', $this->stock)->assertForbidden();
        $this->getJson("/spreadsheets/{$this->sheet->id}/tabs/{$this->stock->id}/removal")->assertForbidden();
    }

    public function test_a_sheet_of_another_workbook_is_out_of_reach(): void
    {
        $other = Spreadsheet::create(['uuid' => (string) str()->uuid(), 'owner_id' => $this->owner->id, 'team_id' => $this->owner->currentTeam->id, 'name' => 'Other']);

        $this->ask('remove', $other->sheetTabs()->firstOrFail())->assertNotFound();
        $this->ask('rename', $other->sheetTabs()->firstOrFail(), ['name' => 'Taken'])->assertNotFound();
    }

    public function test_the_rename_text_handles_quotes_and_leaves_other_sheets(): void
    {
        $this->assertSame("='O''Brien'!A1+Rent!B2", FormulaText::withSheetRenamed('=Stock!A1+Rent!B2', 'Stock', "O'Brien"));
        $this->assertSame('=Goods!A1', FormulaText::withSheetRenamed("='O''Brien'!A1", "O'Brien", 'Goods'));
    }
}
