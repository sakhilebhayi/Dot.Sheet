<?php

namespace Tests\Feature;

use App\Livewire\NamedRangesPanel;
use App\Models\Cell;
use App\Models\NamedRange;
use App\Models\SheetTab;
use App\Models\Spreadsheet;
use App\Models\User;
use App\Services\CellWriter;
use App\Services\Formula\FunctionCatalogue;
use App\Services\Formula\Recalculator;
use App\Services\SheetNames;
use App\Services\WorkbookSheets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GridNamesTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Spreadsheet $sheet;

    private SheetTab $tab;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->withPersonalTeam()->create();
        $this->sheet = Spreadsheet::create(['uuid' => (string) str()->uuid(), 'owner_id' => $this->owner->id, 'team_id' => $this->owner->currentTeam->id, 'name' => 'Named']);
        $this->tab = $this->sheet->sheetTabs()->firstOrFail();
        $this->actingAs($this->owner);
    }

    private function url(string $path = 'names', ?SheetTab $tab = null): string
    {
        return "/spreadsheets/{$this->sheet->id}/tabs/".($tab ?? $this->tab)->id."/{$path}";
    }

    private function aName(string $name = 'Revenue', string $range = 'A1:A2', ?SheetTab $tab = null): NamedRange
    {
        return app(SheetNames::class)->add($this->sheet->id, ($tab ?? $this->tab)->id, $name, $range);
    }

    /**
     * @param  array<string, string>  $cells  "row:col" => contents
     */
    private function write(array $cells): void
    {
        $edits = [];
        foreach ($cells as $at => $value) {
            [$row, $col] = array_map('intval', explode(':', $at));
            $edits[] = ['row' => $row, 'col' => $col, 'value' => $value];
        }
        app(CellWriter::class)->write($this->sheet->id, $this->tab->id, $edits, $this->owner->id);
    }

    private function answerAt(int $row, int $col): ?string
    {
        app(Recalculator::class)->ensureBuilt($this->sheet->id, $this->tab->id);

        return Cell::where('sheet_tab_id', $this->tab->id)->where('row_index', $row)->where('col_index', $col)->value('computed_value');
    }

    public function test_a_name_is_added_with_where_it_points_and_listed_by_name(): void
    {
        $this->postJson($this->url(), ['name' => 'Zebra', 'range' => 'b2'])->assertOk();

        $this->postJson($this->url(), ['name' => 'Revenue', 'range' => '$A$1:$B$10'])->assertOk()
            ->assertJsonPath('names.0.name', 'Revenue')
            ->assertJsonPath('names.0.reference', 'A1:B10')
            ->assertJsonPath('names.0.row', 0)
            ->assertJsonPath('names.0.col2', 1)
            ->assertJsonPath('names.0.row2', 9)
            ->assertJsonPath('names.1.reference', 'B2');
    }

    public function test_a_range_written_backwards_still_covers_the_same_cells(): void
    {
        $this->assertSame('A1:C5', $this->aName('Back', 'C5:A1')->toReferenceText());
    }

    public function test_a_formula_works_out_with_a_new_name_and_shows_name_error_when_it_goes(): void
    {
        $this->write(['0:0' => '2', '1:0' => '3', '2:0' => '=SUM(Revenue)']);
        $this->assertSame('#NAME?', $this->answerAt(2, 0));

        $answer = $this->postJson($this->url(), ['name' => 'Revenue', 'range' => 'A1:A2'])->assertOk()->json();
        $this->assertSame('5', $this->answerAt(2, 0));

        $this->deleteJson($this->url("names/{$answer['names'][0]['id']}"))->assertOk()->assertJsonPath('names', []);
        $this->assertSame('#NAME?', $this->answerAt(2, 0));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function refused(): array
    {
        return [
            'starts with a digit' => ['1st', 'Name must start'],
            'has a space' => ['Net sales', 'Name must start'],
            'reads as a cell' => ['ab12', 'looks like a cell reference'],
            'a function from before' => ['sum', 'looks like a cell reference or function'],
            'a function the old list missed' => ['VLOOKUP', 'looks like a cell reference or function'],
            'a word the engine reserves' => ['true', 'looks like a cell reference or function'],
        ];
    }

    #[DataProvider('refused')]
    public function test_a_name_that_would_confuse_a_formula_is_refused(string $name, string $says): void
    {
        $answer = $this->postJson($this->url(), ['name' => $name, 'range' => 'A1'])->assertUnprocessable()->json();

        $this->assertStringContainsString($says, $answer['message']);
        $this->assertSame(0, NamedRange::count());
    }

    public function test_every_function_in_the_catalogue_is_refused(): void
    {
        foreach (array_column(FunctionCatalogue::all(), 'name') as $function) {
            $this->postJson($this->url(), ['name' => $function, 'range' => 'A1'])->assertUnprocessable();
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function badRanges(): array
    {
        return [
            'row nought' => ['A0'],
            'row nought at the end' => ['A1:A0'],
            'words' => ['the first few'],
            'past the last column' => ['CW1'],
            'past the last row' => ['A1000001'],
            'half a range' => ['A1:'],
        ];
    }

    #[DataProvider('badRanges')]
    public function test_a_range_that_is_not_on_the_sheet_is_refused(string $range): void
    {
        $this->postJson($this->url(), ['name' => 'Fine', 'range' => $range])->assertUnprocessable()->assertJsonPath('message', 'Range must look like A1 or A1:B10.');
        $this->assertSame(0, NamedRange::count());
    }

    public function test_the_last_cell_of_the_sheet_can_be_named(): void
    {
        $this->assertSame('CV1000000', $this->aName('Far', 'CV1000000')->toReferenceText());
    }

    public function test_a_name_is_used_once_per_sheet_whatever_its_case(): void
    {
        $second = app(WorkbookSheets::class)->add($this->sheet, 'Second');
        $this->aName('Revenue');

        $this->postJson($this->url(), ['name' => 'REVENUE', 'range' => 'B1'])->assertUnprocessable();
        $this->postJson($this->url('names', $second), ['name' => 'Revenue', 'range' => 'B1'])->assertOk();
    }

    public function test_it_says_how_many_formulas_use_a_name_and_only_those(): void
    {
        $named = $this->aName();
        $this->write([
            '5:0' => '=SUM(Revenue)',
            '6:0' => '=revenue+1',
            '7:0' => '=SUM(RevenueTotal)',
            '8:0' => '=SUM(A1:A2)',
            '9:0' => 'Revenue',
        ]);

        $this->getJson($this->url("names/{$named->id}/uses"))->assertOk()->assertJsonPath('formulas', 2);
    }

    public function test_a_name_is_reached_only_through_its_own_sheet(): void
    {
        $second = app(WorkbookSheets::class)->add($this->sheet, 'Second');
        $elsewhere = $this->aName('Elsewhere', 'A1', $second);

        $this->getJson($this->url("names/{$elsewhere->id}/uses"))->assertNotFound();
        $this->deleteJson($this->url("names/{$elsewhere->id}"))->assertNotFound();
        $this->assertNotNull($elsewhere->fresh());
    }

    public function test_someone_who_can_read_sees_names_and_changes_nothing(): void
    {
        $named = $this->aName();
        $reader = User::factory()->withPersonalTeam()->create();
        $this->sheet->sharedUsers()->attach($reader->id, ['permission' => 'view']);
        $this->actingAs($reader);

        $this->getJson($this->url())->assertOk()->assertJsonPath('names.0.name', 'Revenue');
        $this->postJson($this->url(), ['name' => 'Mine', 'range' => 'A1'])->assertForbidden();
        $this->getJson($this->url("names/{$named->id}/uses"))->assertForbidden();
        $this->deleteJson($this->url("names/{$named->id}"))->assertForbidden();
        $this->assertNotNull($named->fresh());
    }

    public function test_a_stranger_sees_nothing(): void
    {
        $this->actingAs(User::factory()->withPersonalTeam()->create());

        $this->getJson($this->url())->assertForbidden();
    }

    public function test_the_classic_panel_keeps_the_same_rules(): void
    {
        $panel = Livewire::test(NamedRangesPanel::class, ['spreadsheet_id' => $this->sheet->id, 'sheet_tab_id' => $this->tab->id]);

        $panel->set('name', 'VLOOKUP')->set('rangeInput', 'A1')->call('createRange')->assertSet('error', fn ($error) => str_contains($error, 'function name'));
        $panel->set('name', 'Fine')->set('rangeInput', 'A0')->call('createRange')->assertSet('error', 'Range must look like A1 or A1:B10.');
        $panel->set('name', 'Fine')->set('rangeInput', 'A1')->call('createRange')->assertSet('error', '')->assertSet('name', '');
        $this->assertSame(1, NamedRange::count());

        $panel->call('deleteRange', NamedRange::firstOrFail()->id)->assertSet('ranges', []);
        $this->assertSame(0, NamedRange::count());
    }
}
