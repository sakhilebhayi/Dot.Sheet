<?php

namespace Tests\Feature;

use App\Models\Cell;
use App\Models\CellHistory;
use App\Models\SheetTab;
use App\Models\Spreadsheet;
use App\Models\User;
use App\Services\StructureVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The JSON API behind the new editor: every request is authorized on the sheet it names, and every
 * answer comes from the engine.
 */
class GridApiTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Spreadsheet $sheet;

    private SheetTab $tab;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->withPersonalTeam()->create();
        $this->sheet = $this->sheetFor($this->owner);
        $this->tab = $this->sheet->sheetTabs()->firstOrFail();
    }

    private function sheetFor(User $user): Spreadsheet
    {
        return Spreadsheet::create(['uuid' => (string) str()->uuid(), 'owner_id' => $user->id, 'team_id' => $user->currentTeam->id, 'name' => 'Cash']);
    }

    private function url(string $path): string
    {
        return "/spreadsheets/{$this->sheet->id}/tabs/{$this->tab->id}/{$path}";
    }

    /**
     * @param  list<array{0: int, 1: int, 2: string}>  $edits
     */
    private function edit(array $edits, ?int $version = null): TestResponse
    {
        return $this->postJson($this->url('cells'), [
            'version' => $version ?? StructureVersion::current($this->sheet->id),
            'edits' => array_map(fn (array $e) => ['row' => $e[0], 'col' => $e[1], 'value' => $e[2]], $edits),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function cellIn(TestResponse $response, int $r, int $c): array
    {
        return collect($response->json('cells'))->first(fn (array $cell) => $cell['r'] === $r && $cell['c'] === $c) ?? [];
    }

    private function viewer(): User
    {
        $viewer = User::factory()->withPersonalTeam()->create();
        $this->sheet->sharedUsers()->attach($viewer->id, ['permission' => 'view']);

        return $viewer;
    }

    public function test_the_page_opens_for_people_who_can_see_the_sheet_and_nobody_else(): void
    {
        $this->actingAs($this->owner)->get("/spreadsheets/{$this->sheet->id}/grid")->assertOk()->assertSee('id="boot"', false);

        $this->actingAs(User::factory()->withPersonalTeam()->create())->get("/spreadsheets/{$this->sheet->id}/grid")->assertForbidden();
    }

    public function test_a_stranger_reaches_none_of_it(): void
    {
        $this->actingAs(User::factory()->withPersonalTeam()->create());

        $this->getJson($this->url('sheet'))->assertForbidden();
        $this->getJson($this->url('cells').'?r1=0&r2=10')->assertForbidden();
        $this->getJson($this->url('trace/0/0'))->assertForbidden();
        $this->getJson($this->url('stats').'?r1=0&r2=1&c1=0&c2=1')->assertForbidden();
        $this->edit([[0, 0, 'mine']], 0)->assertForbidden();
        $this->postJson($this->url('format'), ['version' => 0, 'r1' => 0, 'r2' => 0, 'c1' => 0, 'c2' => 0, 'bold' => true])->assertForbidden();

        $this->assertSame(0, Cell::count());
    }

    public function test_a_viewer_reads_but_cannot_write(): void
    {
        $this->actingAs($this->owner);
        $this->edit([[0, 0, '5']])->assertOk();

        $this->actingAs($this->viewer());
        $this->getJson($this->url('cells').'?r1=0&r2=10')->assertOk()->assertJsonPath('cells.0.v', '5');
        $this->edit([[0, 0, '6']])->assertForbidden();
        $this->postJson($this->url('format'), ['version' => 0, 'r1' => 0, 'r2' => 0, 'c1' => 0, 'c2' => 0, 'bold' => true])->assertForbidden();

        $this->assertSame('5', Cell::first()->raw_value);
        $this->assertNull(Cell::first()->formatting);
    }

    public function test_a_tab_of_another_sheet_is_not_reached_through_this_one(): void
    {
        $other = $this->sheetFor(User::factory()->withPersonalTeam()->create());
        $theirs = $other->sheetTabs()->firstOrFail();
        Cell::create(['spreadsheet_id' => $other->id, 'sheet_tab_id' => $theirs->id, 'row_index' => 0, 'col_index' => 0, 'raw_value' => 'secret', 'updated_at' => now()]);

        $this->actingAs($this->owner);
        $base = "/spreadsheets/{$this->sheet->id}/tabs/{$theirs->id}";

        $this->getJson("{$base}/cells?r1=0&r2=10")->assertNotFound();
        $this->getJson("{$base}/sheet")->assertNotFound();
        $this->postJson("{$base}/cells", ['version' => 0, 'edits' => [['row' => 0, 'col' => 0, 'value' => 'x']]])->assertNotFound();

        $this->assertSame('secret', Cell::where('sheet_tab_id', $theirs->id)->value('raw_value'));
    }

    public function test_an_edit_answers_with_everything_it_changed(): void
    {
        $this->actingAs($this->owner);
        $this->edit([[0, 0, '10'], [1, 0, '20'], [2, 0, '=SUM(A1:A2)']])->assertOk();

        $response = $this->edit([[0, 0, '100']])->assertOk();

        $this->assertSame('100', $this->cellIn($response, 0, 0)['v']);
        $this->assertSame('120', $this->cellIn($response, 2, 0)['v']);
        $this->assertSame('=SUM(A1:A2)', $this->cellIn($response, 2, 0)['f']);
        $this->assertSame(2, CellHistory::where('cell_id', Cell::where('row_index', 0)->value('id'))->count());
    }

    public function test_clearing_a_cell_says_it_is_gone(): void
    {
        $this->actingAs($this->owner);
        $this->edit([[0, 0, '10']]);

        $response = $this->edit([[0, 0, '']])->assertOk();

        $this->assertTrue($this->cellIn($response, 0, 0)['gone']);
        $this->assertSame(0, Cell::count());
    }

    public function test_a_broken_formula_comes_back_with_its_explanation(): void
    {
        $this->actingAs($this->owner);

        $cell = $this->cellIn($this->edit([[0, 0, '=A1+1']])->assertOk(), 0, 0);

        $this->assertSame('#REF!', $cell['v']);
        $this->assertNotEmpty($cell['e']);
    }

    public function test_an_edit_against_an_old_structure_changes_nothing(): void
    {
        $this->actingAs($this->owner);
        $version = StructureVersion::current($this->sheet->id);
        StructureVersion::bump($this->sheet->id);

        $this->edit([[0, 0, 'late']], $version)->assertStatus(409)->assertJsonPath('stale', true);
        $this->postJson($this->url('format'), ['version' => $version, 'r1' => 0, 'r2' => 0, 'c1' => 0, 'c2' => 0, 'bold' => true])->assertStatus(409);

        $this->assertSame(0, Cell::count());
    }

    public function test_a_validation_rule_refuses_the_cell_and_keeps_the_rest(): void
    {
        $this->tab->forceFill(['layout' => ['validation_rules' => [['row' => 0, 'col' => 0, 'type' => 'number']]]])->save();
        $this->actingAs($this->owner);

        $response = $this->edit([[0, 0, 'words'], [0, 1, 'fine']])->assertOk();

        $this->assertSame(0, $response->json('refused.0.row'));
        $this->assertNotEmpty($response->json('refused.0.message'));
        $this->assertNull(Cell::where('col_index', 0)->first());
        $this->assertSame('fine', Cell::where('col_index', 1)->value('raw_value'));
    }

    public function test_a_spilled_answer_cannot_be_cleared(): void
    {
        $this->actingAs($this->owner);
        $this->edit([[0, 0, '=SEQUENCE(3)']])->assertOk();

        $response = $this->edit([[2, 0, '']])->assertOk();

        $this->assertTrue($this->cellIn($response, 2, 0)['s']);
        $this->assertSame('3', $this->cellIn($response, 2, 0)['v']);
        $this->assertTrue($response->json('reload'));
    }

    public function test_formatting_a_selection(): void
    {
        $this->actingAs($this->owner);
        $this->edit([[0, 0, '5']]);
        $version = StructureVersion::current($this->sheet->id);

        $this->postJson($this->url('format'), ['version' => $version, 'r1' => 0, 'r2' => 1, 'c1' => 0, 'c2' => 0, 'number_format' => 'percent'])->assertOk();
        $this->postJson($this->url('format'), ['version' => $version, 'r1' => 0, 'r2' => 0, 'c1' => 0, 'c2' => 0, 'bold' => true])->assertOk();

        $cells = $this->getJson($this->url('cells').'?r1=0&r2=1')->assertOk()->json('cells');
        $this->assertSame(['number_format' => 'percent', 'bold' => true], $cells[0]['x']);
        $this->assertSame('5', $cells[0]['v']);
        $this->assertSame(['number_format' => 'percent'], $cells[1]['x']);
    }

    public function test_formatting_refuses_a_format_it_does_not_know_and_too_large_a_selection(): void
    {
        $this->actingAs($this->owner);
        $version = StructureVersion::current($this->sheet->id);

        $this->postJson($this->url('format'), ['version' => $version, 'r1' => 0, 'r2' => 0, 'c1' => 0, 'c2' => 0, 'number_format' => 'url(x)'])->assertUnprocessable();
        $this->postJson($this->url('format'), ['version' => $version, 'r1' => 0, 'r2' => 1000, 'c1' => 0, 'c2' => 100, 'bold' => true])->assertUnprocessable();

        $this->assertSame(0, Cell::count());
    }

    public function test_a_window_of_cells_has_a_limit(): void
    {
        $this->actingAs($this->owner);

        $this->getJson($this->url('cells').'?r1=0&r2=999')->assertOk();
        $this->getJson($this->url('cells').'?r1=0&r2=1000')->assertUnprocessable();
        $this->getJson($this->url('cells').'?r1=5&r2=1')->assertUnprocessable();
    }

    public function test_the_sheet_says_how_far_its_data_reaches(): void
    {
        $this->actingAs($this->owner);

        $this->getJson($this->url('sheet'))->assertOk()->assertJsonPath('lastRow', null);

        $this->edit([[7, 3, 'far']]);

        $this->getJson($this->url('sheet'))->assertOk()->assertJsonPath('lastRow', 7)->assertJsonPath('lastCol', 3);
    }

    public function test_trace_gives_what_a_formula_reads_and_what_reads_a_cell(): void
    {
        $this->actingAs($this->owner);
        $this->edit([[0, 0, '1'], [1, 0, '2'], [2, 0, '=SUM(A1:A2)']]);

        $this->getJson($this->url('trace/2/0'))->assertOk()
            ->assertJsonPath('reads.0', ['tab' => $this->tab->id, 'r1' => 0, 'r2' => 1, 'c1' => 0, 'c2' => 0]);

        $this->getJson($this->url('trace/1/0'))->assertOk()
            ->assertJsonPath('readers.0', ['tab' => $this->tab->id, 'row' => 2, 'col' => 0]);
    }

    public function test_stats_add_up_a_selection(): void
    {
        $this->actingAs($this->owner);
        $this->edit([[0, 0, '10'], [1, 0, '30'], [2, 0, 'words'], [3, 0, '=A1+A2'], [4, 1, '1000']]);

        $this->getJson($this->url('stats').'?r1=0&r2=1048575&c1=0&c2=0')->assertOk()
            ->assertJsonPath('sum', 80)->assertJsonPath('average', 80 / 3)->assertJsonPath('count', 4)->assertJsonPath('numbers', 3);
    }
}
