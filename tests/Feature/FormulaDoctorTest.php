<?php

namespace Tests\Feature;

use App\Models\Cell;
use App\Models\NamedRange;
use App\Models\SheetTab;
use App\Models\Spreadsheet;
use App\Models\User;
use App\Services\CellWriter;
use App\Services\StructureVersion;
use App\Services\WorkbookSheets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class FormulaDoctorTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Spreadsheet $sheet;

    private SheetTab $tab;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->withPersonalTeam()->create();
        $this->sheet = Spreadsheet::create(['uuid' => (string) Str::uuid(), 'owner_id' => $this->owner->id, 'name' => 'Doctor']);
        $this->tab = $this->sheet->sheetTabs()->firstOrFail();
        $this->actingAs($this->owner);
        app(WorkbookSheets::class)->add($this->sheet, 'Cash flow');
    }

    private function url(string $what): string
    {
        return "/spreadsheets/{$this->sheet->id}/tabs/{$this->tab->id}/{$what}";
    }

    private function formula(string $formula): TestResponse
    {
        $this->postJson($this->url('cells'), ['version' => StructureVersion::current($this->sheet->id), 'edits' => [['row' => 0, 'col' => 0, 'value' => $formula]]])->assertOk();

        return $this->getJson($this->url('explain/0/0'))->assertOk();
    }

    /** @param array<string, string> $cells */
    private function fill(array $cells): void
    {
        $edits = [];
        foreach ($cells as $at => $value) {
            [$row, $col] = explode(':', $at);
            $edits[] = ['row' => (int) $row, 'col' => (int) $col, 'value' => $value];
        }
        app(CellWriter::class)->write($this->sheet->id, $this->tab->id, $edits, $this->owner->id);
    }

    public function test_an_empty_cell_and_a_plain_value_hold_no_formula(): void
    {
        $this->getJson($this->url('explain/5/5'))->assertOk()->assertJsonPath('cell', 'F6')->assertJsonPath('formula', null)->assertJsonPath('error', null);

        $this->fill(['1:1' => 'text']);
        $this->getJson($this->url('explain/1/1'))->assertJsonPath('formula', null)->assertJsonPath('plain', null);
    }

    public function test_a_good_formula_is_explained_with_no_error(): void
    {
        $this->formula('=SUM(B1:B2)')->assertJsonPath('plain', 'The total of cells B1 to B2.')->assertJsonPath('error', null)->assertJsonPath('formula', '=SUM(B1:B2)');
    }

    public function test_dividing_by_an_empty_cell_names_it_and_offers_to_wait(): void
    {
        $this->fill(['0:1' => '10']);

        $this->formula('=B1/C1')->assertJsonPath('error.title', 'Divided by zero')
            ->assertJsonPath('error.plain', 'It divides by zero or by an empty cell, and C1 is empty or zero.')
            ->assertJsonPath('error.repair.formula', '=IFERROR(B1/C1,"")');
    }

    public function test_a_misspelt_function_is_corrected(): void
    {
        $this->formula('=SUMM(B1:B2)')->assertJsonPath('error.title', 'A misspelt function')
            ->assertJsonPath('error.plain', 'There is no function called SUMM. Did you mean SUM?')
            ->assertJsonPath('error.repair.formula', '=SUM(B1:B2)')
            ->assertJsonPath('error.repair.why', 'Change SUMM to SUM.');
    }

    public function test_only_the_function_is_corrected_and_not_text_that_spells_it(): void
    {
        $this->fill(['0:1' => 'SUMM']);

        $this->formula('=IF(B1="SUMM",SUMM(C1),0)')->assertJsonPath('error.repair.formula', '=IF(B1="SUMM",SUM(C1),0)');
    }

    public function test_a_word_in_quotes_is_not_taken_for_a_function(): void
    {
        $this->fill(['0:1' => 'ZORK(']);

        $this->formula('=IF(B1="ZORK(",SUMM(C1),0)')->assertJsonPath('error.title', 'A misspelt function');
    }

    public function test_a_function_nothing_like_any_other_gets_no_guess(): void
    {
        $this->formula('=WIBBLEWOBBLE(1)')->assertJsonPath('error.title', 'A function this product does not know')->assertJsonPath('error.repair', null);
    }

    public function test_a_misspelt_name_is_corrected(): void
    {
        NamedRange::create(['spreadsheet_id' => $this->sheet->id, 'sheet_tab_id' => $this->tab->id, 'name' => 'Takings', 'start_row' => 1, 'start_col' => 1, 'end_row' => 2, 'end_col' => 1]);

        $this->formula('=SUM(Takngs)')->assertJsonPath('error.title', 'A misspelt name')->assertJsonPath('error.repair.formula', '=SUM(Takings)');
    }

    public function test_a_word_that_is_no_name_gets_no_guess(): void
    {
        $this->formula('=SUM(Revenue)')->assertJsonPath('error.title', 'A name that is not defined')->assertJsonPath('error.repair', null);
    }

    public function test_a_misspelt_sheet_is_corrected_and_quoted(): void
    {
        $this->formula("='Cash flw'!A1")->assertJsonPath('error.title', 'A sheet that is not here')
            ->assertJsonPath('error.repair.formula', "='Cash flow'!A1");
    }

    public function test_a_sheet_nothing_like_any_other_gets_no_guess(): void
    {
        $this->formula('=Elsewhere!A1')->assertJsonPath('error.repair', null)->assertJsonPath('error.plain', 'This reads from a sheet called Elsewhere, and there is no sheet by that name.');
    }

    public function test_text_where_a_number_should_be_is_named(): void
    {
        $this->fill(['0:1' => 'words', '0:2' => '2']);

        $this->formula('=B1*C1')->assertJsonPath('error.title', 'Text where a number should be')->assertJsonPath('error.plain', 'It needed a number, and B1 holds text.');
    }

    public function test_a_lookup_that_finds_nothing_offers_to_wait(): void
    {
        $this->fill(['0:1' => 'a', '0:2' => '1']);

        $this->formula('=VLOOKUP("z",B1:C1,2,FALSE)')->assertJsonPath('error.title', 'Nothing found')
            ->assertJsonPath('error.repair.formula', '=IFERROR(VLOOKUP("z",B1:C1,2,FALSE),"")');
    }

    public function test_a_formula_already_guarded_is_not_wrapped_twice(): void
    {
        $this->fill(['0:1' => '5']);

        $response = $this->formula('=IFERROR(B1/0,1/0)');

        $response->assertJsonPath('error.repair', null);
    }

    public function test_many_cells_are_summed_up_rather_than_listed(): void
    {
        $this->fill(['0:1' => 'a', '1:1' => 'b', '2:1' => 'c', '3:1' => 'd', '4:1' => 'e', '5:1' => 'f']);

        $this->formula('=B1*B2*B3*B4*B5*B6')->assertJsonPath('error.plain', 'It needed a number, and B1, B2, B3, B4 and 2 more hold text.');
    }

    public function test_a_stale_sheet_is_worked_out_before_it_is_explained(): void
    {
        $this->fill(['0:0' => '=1/0']);
        Cell::where('sheet_tab_id', $this->tab->id)->update(['computed_value' => null]);
        $this->tab->update(['formulas_built_at' => null]);

        $this->getJson($this->url('explain/0/0'))->assertJsonPath('error.title', 'Divided by zero');
    }

    public function test_anyone_who_can_read_can_ask_and_nobody_else(): void
    {
        $this->fill(['0:0' => '=1+1']);
        $reader = User::factory()->withPersonalTeam()->create();
        $this->sheet->sharedUsers()->attach($reader->id, ['permission' => 'view']);

        $this->actingAs($reader)->getJson($this->url('explain/0/0'))->assertOk();
        $this->actingAs(User::factory()->withPersonalTeam()->create())->getJson($this->url('explain/0/0'))->assertForbidden();
    }

    public function test_a_cell_is_reached_only_through_its_own_workbook(): void
    {
        $other = Spreadsheet::create(['uuid' => (string) Str::uuid(), 'owner_id' => $this->owner->id, 'name' => 'Other']);

        $this->getJson("/spreadsheets/{$other->id}/tabs/{$this->tab->id}/explain/0/0")->assertNotFound();
        $this->getJson($this->url('explain/999999999/0'))->assertNotFound();
    }
}
