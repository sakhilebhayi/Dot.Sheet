<?php

namespace Tests\Feature;

use App\Models\Cell;
use App\Models\SheetTab;
use App\Models\Spreadsheet;
use App\Models\User;
use App\Services\CellWriter;
use App\Services\Formula\Recalculator;
use App\Services\SheetStructureService;
use App\Services\StructureVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GridSortTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Spreadsheet $sheet;

    private SheetTab $tab;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->withPersonalTeam()->create();
        $this->sheet = Spreadsheet::create(['uuid' => (string) str()->uuid(), 'owner_id' => $this->owner->id, 'team_id' => $this->owner->currentTeam->id, 'name' => 'Sorted']);
        $this->tab = $this->sheet->sheetTabs()->firstOrFail();
        $this->actingAs($this->owner);
    }

    /**
     * @param  list<list<string>>  $rows  from A1
     */
    private function fill(array $rows, int $fromRow = 0): void
    {
        $edits = [];
        foreach ($rows as $r => $row) {
            foreach ($row as $c => $value) {
                if ($value !== '') {
                    $edits[] = ['row' => $fromRow + $r, 'col' => $c, 'value' => $value];
                }
            }
        }
        app(CellWriter::class)->write($this->sheet->id, $this->tab->id, $edits, $this->owner->id);
        app(Recalculator::class)->ensureBuilt($this->sheet->id, $this->tab->id);
    }

    private function sort(int $r1, int $c1, int $r2, int $c2, int $key, bool $descending = false, ?int $version = null): TestResponse
    {
        return $this->postJson("/spreadsheets/{$this->sheet->id}/tabs/{$this->tab->id}/sort", [
            'version' => $version ?? StructureVersion::current($this->sheet->id), 'r1' => $r1, 'r2' => $r2, 'c1' => $c1, 'c2' => $c2, 'key' => $key, 'descending' => $descending,
        ]);
    }

    /**
     * @return list<string> what a column holds, as written, row by row
     */
    private function column(int $col, int $from, int $to): array
    {
        $found = [];
        for ($row = $from; $row <= $to; $row++) {
            $cell = Cell::where('sheet_tab_id', $this->tab->id)->where('row_index', $row)->where('col_index', $col)->first();
            $found[] = $cell === null ? '' : (string) ($cell->formula ?? $cell->raw_value);
        }

        return $found;
    }

    private function answer(int $row, int $col): string
    {
        app(Recalculator::class)->ensureBuilt($this->sheet->id, $this->tab->id);

        return (string) Cell::where('sheet_tab_id', $this->tab->id)->where('row_index', $row)->where('col_index', $col)->value('computed_value');
    }

    /**
     * @param  list<array<string, mixed>>  $groups
     * @return list<array<string, mixed>>
     */
    private function replay(array $groups): array
    {
        return $this->postJson("/spreadsheets/{$this->sheet->id}/tabs/{$this->tab->id}/cells", ['version' => StructureVersion::current($this->sheet->id), 'edits' => $groups[0]['edits']])->assertOk()->json('undo');
    }

    public function test_numbers_sort_by_size_each_way(): void
    {
        $this->fill([['10'], ['9'], ['100'], ['-1']]);

        $this->sort(0, 0, 3, 0, 0)->assertOk();
        $this->assertSame(['-1', '9', '10', '100'], $this->column(0, 0, 3));

        $this->sort(0, 0, 3, 0, 0, true)->assertOk();
        $this->assertSame(['100', '10', '9', '-1'], $this->column(0, 0, 3));
    }

    public function test_text_sorts_as_people_read_it_and_numbers_come_first(): void
    {
        $this->fill([['Unit 10'], ['unit 2'], ['7'], ['Apple']]);

        $this->sort(0, 0, 3, 0, 0)->assertOk();

        $this->assertSame(['7', 'Apple', 'unit 2', 'Unit 10'], $this->column(0, 0, 3));
    }

    public function test_blanks_go_to_the_foot_both_ways(): void
    {
        $this->fill([['b', '1'], ['', '2'], ['a', '3'], ['', '4']]);

        $this->sort(0, 0, 3, 1, 0)->assertOk();
        $this->assertSame(['a', 'b', '', ''], $this->column(0, 0, 3));
        $this->assertSame(['3', '1', '2', '4'], $this->column(1, 0, 3));

        $this->sort(0, 0, 3, 1, 0, true)->assertOk();
        $this->assertSame(['b', 'a', '', ''], $this->column(0, 0, 3));
    }

    public function test_rows_that_tie_keep_their_order(): void
    {
        $this->fill([['x', '1'], ['a', '2'], ['x', '3'], ['a', '4']]);

        $this->sort(0, 0, 3, 1, 0)->assertOk();

        $this->assertSame(['2', '4', '1', '3'], $this->column(1, 0, 3));
    }

    public function test_a_row_keeps_together_and_its_formulas_follow_it(): void
    {
        $this->fill([['Pears', '30', '=B1*2'], ['Apples', '10', '=B2*2'], ['Figs', '20', '=B3*2']]);

        $this->sort(0, 0, 2, 2, 0)->assertOk();

        $this->assertSame(['Apples', 'Figs', 'Pears'], $this->column(0, 0, 2));
        $this->assertSame(['=B1*2', '=B2*2', '=B3*2'], $this->column(2, 0, 2));
        $this->assertSame('20', $this->answer(0, 2));
        $this->assertSame('40', $this->answer(1, 2));
        $this->assertSame('60', $this->answer(2, 2));
    }

    public function test_a_sort_by_a_formula_uses_its_answer(): void
    {
        $this->fill([['3', '=A1*10'], ['1', '=A2*10'], ['2', '=A3*10']]);

        $this->sort(0, 0, 2, 1, 1)->assertOk();

        $this->assertSame(['1', '2', '3'], $this->column(0, 0, 2));
    }

    public function test_formatting_moves_with_its_row(): void
    {
        $this->fill([['b'], ['a']]);
        $this->postJson("/spreadsheets/{$this->sheet->id}/tabs/{$this->tab->id}/format", ['version' => StructureVersion::current($this->sheet->id), 'r1' => 0, 'r2' => 0, 'c1' => 0, 'c2' => 0, 'bold' => true])->assertOk();

        $this->sort(0, 0, 1, 0, 0)->assertOk();

        $this->assertTrue(Cell::where('sheet_tab_id', $this->tab->id)->where('row_index', 1)->firstOrFail()->formatting['bold']);
        $this->assertNull(Cell::where('sheet_tab_id', $this->tab->id)->where('row_index', 0)->firstOrFail()->formatting);
    }

    public function test_undo_puts_every_row_back_and_redo_sorts_again(): void
    {
        $this->fill([['Pears', '=LEN(A1)'], ['Fig', '=LEN(A2)']]);
        $before = [$this->column(0, 0, 1), $this->column(1, 0, 1)];

        $undo = $this->sort(0, 0, 1, 1, 0)->assertOk()->json('undo');
        $sorted = [$this->column(0, 0, 1), $this->column(1, 0, 1)];
        $this->assertNotSame($before, $sorted);

        $redo = $this->replay($undo);
        $this->assertSame($before, [$this->column(0, 0, 1), $this->column(1, 0, 1)]);

        $this->replay($redo);
        $this->assertSame($sorted, [$this->column(0, 0, 1), $this->column(1, 0, 1)]);
    }

    public function test_a_selection_sorts_exactly_that_and_leaves_the_rest(): void
    {
        $this->fill([['z'], ['c'], ['a'], ['b'], ['0']]);

        $this->sort(1, 0, 3, 0, 0)->assertOk()->assertJsonPath('header', null);

        $this->assertSame(['z', 'a', 'b', 'c', '0'], $this->column(0, 0, 4));
    }

    public function test_only_the_selected_columns_move(): void
    {
        $this->fill([['2', 'x'], ['1', 'y']]);

        $this->sort(0, 0, 1, 0, 0)->assertOk();

        $this->assertSame(['x', 'y'], $this->column(1, 0, 1));
    }

    public function test_one_cell_sorts_its_block_and_stops_at_empty_rows_and_columns(): void
    {
        $this->fill([['3', 'c', '', 'off to the side'], ['1', 'a'], ['2', 'b'], ['', ''], ['0', 'below']]);

        $this->sort(1, 0, 1, 0, 0)->assertOk()->assertJsonPath('area', ['r1' => 0, 'r2' => 2, 'c1' => 0, 'c2' => 1]);

        $this->assertSame(['1', '2', '3', '', '0'], $this->column(0, 0, 4));
        $this->assertSame(['off to the side'], [$this->column(3, 0, 0)[0]]);
    }

    public function test_frozen_rows_stay_as_the_heading(): void
    {
        $this->tab->forceFill(['layout' => ['frozen_rows' => 1]])->save();
        $this->fill([['Name'], ['c'], ['a'], ['b']]);

        $this->sort(2, 0, 2, 0, 0)->assertOk()->assertJsonPath('header', 0);

        $this->assertSame(['Name', 'a', 'b', 'c'], $this->column(0, 0, 3));
    }

    public function test_a_first_row_of_words_over_figures_stays_as_the_heading(): void
    {
        $this->fill([['Item', 'Price'], ['Pear', '3'], ['Apple', '9'], ['Fig', '1']]);

        $this->sort(2, 1, 2, 1, 1)->assertOk()->assertJsonPath('header', 0)->assertJsonPath('area.r1', 1);

        $this->assertSame(['Price', '1', '3', '9'], $this->column(1, 0, 3));
    }

    public function test_rows_of_words_alone_have_no_heading_and_the_answer_says_so(): void
    {
        $this->fill([['c'], ['a'], ['b']]);

        $this->sort(0, 0, 0, 0, 0)->assertOk()->assertJsonPath('header', null);

        $this->assertSame(['a', 'b', 'c'], $this->column(0, 0, 2));
    }

    public function test_a_whole_column_selected_sorts_its_block(): void
    {
        $this->tab->forceFill(['layout' => ['frozen_rows' => 1]])->save();
        $this->fill([['Name'], ['b'], ['a']]);

        $this->sort(0, 0, SheetStructureService::GRID_ROWS - 1, 0, 0)->assertOk();

        $this->assertSame(['Name', 'a', 'b'], $this->column(0, 0, 2));
    }

    public function test_a_formula_outside_keeps_reading_the_same_place(): void
    {
        $this->fill([['3'], ['1'], ['2'], [''], ['=A1']]);

        $this->sort(0, 0, 2, 0, 0)->assertOk();

        $this->assertSame('=A1', $this->column(0, 4, 4)[0]);
        $this->assertSame('1', $this->answer(4, 0));
    }

    public function test_sorted_rows_set_off_no_workflow_alerts(): void
    {
        Notification::fake();
        $this->fill([['1'], ['2']]);
        $this->sheet->update(['settings' => ['ai_workflows' => [['id' => 'r', 'name' => 'Low', 'column' => 'A', 'operator' => '<', 'value' => '5', 'action' => 'email_owner', 'enabled' => true]]]]);

        $this->sort(0, 0, 1, 0, 0, true)->assertOk();

        Notification::assertNothingSent();
    }

    /**
     * @return list<array{0: array<string, mixed>, 1: string}>
     */
    public static function refusals(): array
    {
        return [
            'merged' => [['merges' => [['r1' => 1, 'r2' => 1, 'c1' => 0, 'c2' => 1]]], 'merged cells'],
            'filtered' => [['filter_rules' => [['id' => 'a', 'col' => 0, 'type' => 'contains', 'value' => 'x']]], 'Clear the filter'],
            'hidden' => [['hidden_rows' => [1]], 'hidden'],
            'ruled' => [['validation_rules' => [['id' => 'a', 'row' => 0, 'col' => 1, 'type' => 'number']]], 'A rule on these cells'],
        ];
    }

    /**
     * @param  array<string, mixed>  $layout
     */
    #[DataProvider('refusals')]
    public function test_a_sort_that_cannot_be_clean_changes_nothing(array $layout, string $says): void
    {
        $this->fill([['2', '8'], ['1', 'text']]);
        $this->tab->forceFill(['layout' => $layout])->save();

        $this->sort(0, 0, 1, 1, 0)->assertStatus(422)->assertJsonPath('message', fn (string $message) => str_contains($message, $says));

        $this->assertSame(['2', '1'], $this->column(0, 0, 1));
        $this->assertSame(['8', 'text'], $this->column(1, 0, 1));
    }

    public function test_a_spilled_answer_stops_a_sort(): void
    {
        $this->fill([['=SEQUENCE(2)']]);

        $this->sort(0, 0, 1, 0, 0)->assertStatus(422);

        $this->assertSame('=SEQUENCE(2)', $this->column(0, 0, 0)[0]);
    }

    public function test_nothing_to_sort_says_so(): void
    {
        $this->fill([['a']]);

        $this->sort(5, 5, 5, 5, 5)->assertStatus(422)->assertJsonPath('message', 'There is nothing to sort here.');
    }

    public function test_the_key_must_be_inside_the_selection(): void
    {
        $this->fill([['2'], ['1']]);

        $this->sort(0, 0, 1, 0, 3)->assertStatus(422);
    }

    public function test_too_large_a_sort_is_refused(): void
    {
        $this->fill([['2'], ['1']]);

        $this->sort(0, 0, 5001, 0, 0)->assertStatus(422);
    }

    public function test_a_stale_browser_moves_nothing(): void
    {
        $this->fill([['2'], ['1']]);

        $this->sort(0, 0, 1, 0, 0, false, 999)->assertStatus(409);

        $this->assertSame(['2', '1'], $this->column(0, 0, 1));
    }

    public function test_someone_who_can_only_view_cannot_sort(): void
    {
        $this->fill([['2'], ['1']]);
        $viewer = User::factory()->withPersonalTeam()->create();
        $this->sheet->sharedUsers()->attach($viewer->id, ['permission' => 'view']);
        $this->actingAs($viewer);

        $this->sort(0, 0, 1, 0, 0)->assertForbidden();
    }
}
