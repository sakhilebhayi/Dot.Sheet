<?php

namespace Tests\Feature;

use App\Models\Spreadsheet;
use App\Models\User;
use App\Services\CellWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    private function sheetFor(User $user, string $name): Spreadsheet
    {
        return Spreadsheet::create(['uuid' => (string) str()->uuid(), 'owner_id' => $user->id, 'team_id' => null, 'name' => $name]);
    }

    /**
     * @param  list<array{0: int, 1: int, 2: string}>  $cells
     */
    private function fill(Spreadsheet $sheet, array $cells): void
    {
        app(CellWriter::class)->write($sheet->id, $sheet->sheetTabs()->firstOrFail()->id, array_map(
            fn (array $c) => ['row' => $c[0], 'col' => $c[1], 'value' => $c[2]],
            $cells,
        ), $sheet->owner_id);
    }

    public function test_the_page_lists_own_shared_and_team_sheets_and_nothing_else(): void
    {
        $person = User::factory()->withPersonalTeam()->create();
        $other = User::factory()->withPersonalTeam()->create();

        $this->sheetFor($person, 'My own');
        $shared = $this->sheetFor($other, 'Shared to me');
        $shared->sharedUsers()->attach($person->id, ['permission' => 'view']);
        $team = $this->sheetFor($other, 'Team sheet');
        $team->update(['team_id' => $person->currentTeam->id]);
        $person->currentTeam->users()->attach($other->id, ['role' => 'editor']);
        $this->sheetFor($other, 'Not mine');

        foreach (['dashboard', 'spreadsheets.index'] as $route) {
            $this->actingAs($person)->get(route($route))->assertOk()
                ->assertSee('My own')->assertSee('Shared to me')->assertSee('Team sheet')->assertDontSee('Not mine');
        }
    }

    public function test_a_row_counts_cells_and_formulas(): void
    {
        $person = User::factory()->withPersonalTeam()->create();
        $sheet = $this->sheetFor($person, 'Counted');
        $this->fill($sheet, [[0, 0, '1'], [1, 0, '2'], [2, 0, '=SUM(A1:A2)']]);

        $spreadsheets = $this->actingAs($person)->get(route('dashboard'))->assertOk()->viewData('spreadsheets');

        $this->assertSame(3, $spreadsheets->first()->cells_count);
        $this->assertSame(1, $spreadsheets->first()->formulas_count);
    }

    public function test_only_someone_who_can_delete_is_offered_it(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $reader = User::factory()->withPersonalTeam()->create();
        $sheet = $this->sheetFor($owner, 'Careful');
        $sheet->sharedUsers()->attach($reader->id, ['permission' => 'view']);
        $offer = '&quot;delete&quot;:&quot;http';

        $this->actingAs($owner)->get(route('dashboard'))->assertOk()->assertSee($offer, false);
        $this->actingAs($reader)->get(route('dashboard'))->assertOk()->assertDontSee($offer, false);
    }

    public function test_someone_with_nothing_yet_gets_a_way_to_start(): void
    {
        $person = User::factory()->withPersonalTeam()->create();

        $this->actingAs($person)->get(route('dashboard'))->assertOk()
            ->assertSee('Your first spreadsheet starts here')->assertSee(route('spreadsheets.create'));
    }

    public function test_a_name_cannot_break_out_of_the_page(): void
    {
        $person = User::factory()->withPersonalTeam()->create();
        $this->sheetFor($person, '</script><script>alert(1)</script>');

        $this->actingAs($person)->get(route('dashboard'))->assertOk()
            ->assertDontSee('</script><script>alert(1)', false);
    }

    public function test_a_print_has_the_shape_of_the_sheet_and_none_of_its_values(): void
    {
        $person = User::factory()->withPersonalTeam()->create();
        $sheet = $this->sheetFor($person, 'Shape');
        $this->fill($sheet, [[0, 0, 'Secret label'], [1, 0, '125000'], [2, 0, '-40'], [3, 0, '=SUM(A2:A3)']]);

        $response = $this->actingAs($person)->getJson(route('spreadsheets.print', $sheet))->assertOk();

        $this->assertEqualsCanonicalizing([[0, 0, 't'], [1, 0, 'n'], [2, 0, 'l'], [3, 0, 'f']], $response->json('cells'));
        $this->assertSame([[3, 0, 1, 0]], $response->json('threads'));
        $response->assertDontSee('Secret label')->assertDontSee('125000');
    }

    public function test_a_print_comes_from_the_fullest_sheet(): void
    {
        $person = User::factory()->withPersonalTeam()->create();
        $sheet = $this->sheetFor($person, 'Two sheets');
        $this->fill($sheet, [[0, 0, 'one']]);
        $second = $sheet->sheetTabs()->create(['name' => 'Fuller', 'sort_order' => 1]);
        app(CellWriter::class)->write($sheet->id, $second->id, [['row' => 0, 'col' => 0, 'value' => '1'], ['row' => 1, 'col' => 0, 'value' => '2']], $person->id);

        $this->actingAs($person)->getJson(route('spreadsheets.print', $sheet))->assertOk()->assertJsonPath('sheet', 'Fuller');
    }

    public function test_a_print_of_an_empty_sheet_is_blank_paper(): void
    {
        $person = User::factory()->withPersonalTeam()->create();

        $this->actingAs($person)->getJson(route('spreadsheets.print', $this->sheetFor($person, 'Blank')))->assertOk()
            ->assertJsonPath('cells', [])->assertJsonPath('threads', []);
    }

    public function test_a_stranger_gets_no_print(): void
    {
        $sheet = $this->sheetFor(User::factory()->withPersonalTeam()->create(), 'Private');

        $this->actingAs(User::factory()->withPersonalTeam()->create())->getJson(route('spreadsheets.print', $sheet))->assertForbidden();
    }
}
