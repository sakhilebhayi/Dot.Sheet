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

    /**
     * Put back what an answer says undoes it, the way the editor does.
     *
     * @param  array<string, mixed>  $answer
     * @return array<string, mixed> the answer to that, which holds the way forward again
     */
    private function undo(array $answer): array
    {
        $last = [];
        foreach ($answer['undo'] as $group) {
            $last = $this->postJson("/spreadsheets/{$this->sheet->id}/tabs/{$group['tab']}/cells", [
                'version' => StructureVersion::current($this->sheet->id),
                'edits' => $group['edits'],
            ])->assertOk()->json();
        }

        return $last;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function paste(array $overrides): TestResponse
    {
        return $this->postJson($this->url('paste'), [
            'version' => StructureVersion::current($this->sheet->id),
            'from_tab' => $this->tab->id,
            'r1' => 0, 'r2' => 0, 'c1' => 0, 'c2' => 0,
            'row' => 5, 'col' => 0, 'rows' => 1, 'cols' => 1,
            ...$overrides,
        ]);
    }

    private function contentsAt(int $row, int $col, ?int $tab = null): ?string
    {
        $cell = Cell::where('sheet_tab_id', $tab ?? $this->tab->id)->where('row_index', $row)->where('col_index', $col)->first();

        return $cell === null ? null : ($cell->formula ?? $cell->raw_value);
    }

    public function test_undo_puts_back_a_value_a_formula_and_an_empty_cell(): void
    {
        $this->actingAs($this->owner);
        $this->edit([[0, 0, '5'], [1, 0, '=A1*2']]);

        $answer = $this->edit([[0, 0, '9'], [1, 0, '7'], [2, 0, 'new']])->assertOk()->json();
        $redo = $this->undo($answer);

        $this->assertSame('5', $this->contentsAt(0, 0));
        $this->assertSame('=A1*2', $this->contentsAt(1, 0));
        $this->assertNull($this->contentsAt(2, 0));
        $this->assertSame('10', Cell::where('row_index', 1)->value('computed_value'));

        $this->undo($redo);

        $this->assertSame('9', $this->contentsAt(0, 0));
        $this->assertSame('7', $this->contentsAt(1, 0));
        $this->assertSame('new', $this->contentsAt(2, 0));
    }

    public function test_undo_puts_back_formatting(): void
    {
        $this->actingAs($this->owner);
        $this->edit([[0, 0, '5']]);
        $version = StructureVersion::current($this->sheet->id);

        $answer = $this->postJson($this->url('format'), ['version' => $version, 'r1' => 0, 'r2' => 1, 'c1' => 0, 'c2' => 0, 'bold' => true])->assertOk()->json();
        $this->assertTrue(Cell::where('row_index', 0)->first()->formatting['bold']);

        $this->undo($answer);

        $this->assertNull(Cell::where('row_index', 0)->first()->formatting);
        $this->assertNull(Cell::where('row_index', 1)->first());
    }

    public function test_undo_brings_a_spilled_answer_back(): void
    {
        $this->actingAs($this->owner);
        $this->edit([[0, 0, '=SEQUENCE(3)']]);

        $answer = $this->edit([[1, 0, 'in the way']])->assertOk()->json();
        $this->assertSame('#SPILL!', Cell::where('row_index', 0)->value('computed_value'));

        $this->undo($answer);

        $this->assertSame('1', Cell::where('row_index', 0)->value('computed_value'));
        $this->assertSame('2', Cell::where('row_index', 1)->value('computed_value'));
        $this->assertSame('3', Cell::where('row_index', 2)->value('computed_value'));
    }

    public function test_formatting_from_a_browser_keeps_only_what_it_should(): void
    {
        $this->actingAs($this->owner);

        $this->postJson($this->url('cells'), [
            'version' => StructureVersion::current($this->sheet->id),
            'edits' => [['row' => 0, 'col' => 0, 'value' => '5', 'x' => [
                'bold' => true,
                'bg_color' => '#FEE2E2',
                'font_color' => 'red;background:url(https://example.test/x)',
                'font_size' => 4000,
                'anything' => 'else',
            ]]],
        ])->assertOk();

        $this->assertSame(['bold' => true, 'bg_color' => '#FEE2E2'], Cell::first()->formatting);
    }

    public function test_a_copied_formula_reads_from_its_new_place(): void
    {
        $this->actingAs($this->owner);
        $this->edit([[0, 0, '1'], [1, 0, '2'], [0, 1, '=A1*10']]);
        $version = StructureVersion::current($this->sheet->id);
        $this->postJson($this->url('format'), ['version' => $version, 'r1' => 0, 'r2' => 0, 'c1' => 1, 'c2' => 1, 'bold' => true]);

        $answer = $this->paste(['c1' => 1, 'c2' => 1, 'row' => 1, 'col' => 1])->assertOk()->json();

        $this->assertSame('=A2*10', $this->contentsAt(1, 1));
        $this->assertSame('20', Cell::where('row_index', 1)->where('col_index', 1)->value('computed_value'));
        $this->assertSame(['bold' => true], Cell::where('row_index', 1)->where('col_index', 1)->value('formatting'));
        $this->assertSame(['r1' => 1, 'r2' => 1, 'c1' => 1, 'c2' => 1], $answer['area']);

        $this->undo($answer);

        $this->assertNull($this->contentsAt(1, 1));
    }

    public function test_a_copy_repeats_to_fill_a_selection_it_divides(): void
    {
        $this->actingAs($this->owner);
        $this->edit([[0, 0, 'a'], [1, 0, 'b']]);

        $this->paste(['r2' => 1, 'row' => 5, 'rows' => 4])->assertOk()->assertJsonPath('area.r2', 8);
        $this->assertSame(['a', 'b', 'a', 'b'], [$this->contentsAt(5, 0), $this->contentsAt(6, 0), $this->contentsAt(7, 0), $this->contentsAt(8, 0)]);

        $this->paste(['r2' => 1, 'row' => 20, 'rows' => 3])->assertOk()->assertJsonPath('area.r2', 21);
        $this->assertNull($this->contentsAt(22, 0));
    }

    public function test_copying_empty_cells_clears_where_they_land(): void
    {
        $this->actingAs($this->owner);
        $this->edit([[5, 0, 'old']]);

        $this->paste(['r1' => 9, 'r2' => 9])->assertOk();

        $this->assertNull($this->contentsAt(5, 0));
    }

    public function test_a_spilled_answer_copies_as_its_value(): void
    {
        $this->actingAs($this->owner);
        $this->edit([[0, 0, '=SEQUENCE(3)']]);

        $this->paste(['r1' => 1, 'r2' => 1, 'row' => 0, 'col' => 3])->assertOk();

        $this->assertSame('2', $this->contentsAt(0, 3));
    }

    public function test_moving_cells_keeps_the_formula_and_clears_where_they_were(): void
    {
        $this->actingAs($this->owner);
        $this->edit([[0, 0, '1'], [1, 0, '=A1+1']]);

        $answer = $this->paste(['r1' => 1, 'r2' => 1, 'row' => 4, 'col' => 2, 'cut' => true])->assertOk()->json();

        $this->assertNull($this->contentsAt(1, 0));
        $this->assertSame('=A1+1', $this->contentsAt(4, 2));

        $this->undo($answer);

        $this->assertSame('=A1+1', $this->contentsAt(1, 0));
        $this->assertNull($this->contentsAt(4, 2));
    }

    public function test_moving_cells_over_themselves_loses_nothing(): void
    {
        $this->actingAs($this->owner);
        $this->edit([[0, 0, 'a'], [1, 0, 'b'], [2, 0, 'c']]);

        $answer = $this->paste(['r2' => 2, 'row' => 1, 'cut' => true])->assertOk()->json();

        $this->assertSame([null, 'a', 'b', 'c'], [$this->contentsAt(0, 0), $this->contentsAt(1, 0), $this->contentsAt(2, 0), $this->contentsAt(3, 0)]);

        $this->undo($answer);

        $this->assertSame(['a', 'b', 'c', null], [$this->contentsAt(0, 0), $this->contentsAt(1, 0), $this->contentsAt(2, 0), $this->contentsAt(3, 0)]);
    }

    public function test_cells_move_between_sheets_and_back(): void
    {
        $this->actingAs($this->owner);
        $other = SheetTab::create(['spreadsheet_id' => $this->sheet->id, 'name' => 'Other', 'sort_order' => 1]);
        $this->edit([[0, 0, 'here']]);

        $answer = $this->postJson("/spreadsheets/{$this->sheet->id}/tabs/{$other->id}/paste", [
            'version' => StructureVersion::current($this->sheet->id),
            'from_tab' => $this->tab->id,
            'r1' => 0, 'r2' => 0, 'c1' => 0, 'c2' => 0,
            'row' => 3, 'col' => 3, 'rows' => 1, 'cols' => 1, 'cut' => true,
        ])->assertOk()->json();

        $this->assertNull($this->contentsAt(0, 0));
        $this->assertSame('here', $this->contentsAt(3, 3, $other->id));

        $this->undo($answer);

        $this->assertSame('here', $this->contentsAt(0, 0));
        $this->assertNull($this->contentsAt(3, 3, $other->id));
    }

    public function test_pasting_is_authorized_on_both_ends(): void
    {
        $other = $this->sheetFor(User::factory()->withPersonalTeam()->create());
        $theirs = $other->sheetTabs()->firstOrFail();
        Cell::create(['spreadsheet_id' => $other->id, 'sheet_tab_id' => $theirs->id, 'row_index' => 0, 'col_index' => 0, 'raw_value' => 'secret', 'updated_at' => now()]);

        $this->actingAs($this->owner);
        $this->paste(['from_tab' => $theirs->id])->assertNotFound();
        $this->assertNull($this->contentsAt(5, 0));

        $this->actingAs($this->viewer());
        $this->paste([])->assertForbidden();
    }

    public function test_a_paste_has_limits(): void
    {
        $this->actingAs($this->owner);

        $this->paste(['r2' => 5000, 'row' => 6000, 'rows' => 5001])->assertUnprocessable();
        $this->paste(['r2' => 1, 'row' => 1048575, 'rows' => 2])->assertUnprocessable();

        $this->assertSame(0, Cell::count());
    }

    public function test_a_large_paste_is_worked_out_and_can_be_undone(): void
    {
        $this->actingAs($this->owner);
        $edits = [];
        for ($row = 0; $row < 300; $row++) {
            $edits[] = [$row, 0, (string) $row];
            $edits[] = [$row, 1, '=A'.($row + 1).'*2'];
        }
        $this->edit($edits)->assertOk()->assertJsonPath('reload', true);
        $this->assertSame('598', Cell::where('row_index', 299)->where('col_index', 1)->value('computed_value'));

        $answer = $this->paste(['r2' => 299, 'c2' => 1, 'row' => 0, 'col' => 3, 'rows' => 300, 'cols' => 2])->assertOk()->assertJsonPath('reload', true)->json();

        $this->assertSame([], $answer['cells']);
        $this->assertSame('=D300*2', $this->contentsAt(299, 4));
        $this->assertSame('598', Cell::where('row_index', 299)->where('col_index', 4)->value('computed_value'));

        $this->undo($answer);

        $this->assertNull($this->contentsAt(299, 4));
    }
}
