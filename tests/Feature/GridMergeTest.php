<?php

namespace Tests\Feature;

use App\Models\Cell;
use App\Models\SheetTab;
use App\Models\Spreadsheet;
use App\Models\User;
use App\Services\CellWriter;
use App\Services\SheetMerges;
use App\Services\SheetStructureService;
use App\Services\SpreadsheetImportExportService;
use App\Services\StructureVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class GridMergeTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Spreadsheet $sheet;

    private SheetTab $tab;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->withPersonalTeam()->create();
        $this->sheet = Spreadsheet::create(['uuid' => (string) str()->uuid(), 'owner_id' => $this->owner->id, 'team_id' => $this->owner->currentTeam->id, 'name' => 'Merged']);
        $this->tab = $this->sheet->sheetTabs()->firstOrFail();
        $this->actingAs($this->owner);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function ask(string $what, array $body, ?SheetTab $tab = null): TestResponse
    {
        $tab ??= $this->tab;

        return $this->postJson("/spreadsheets/{$this->sheet->id}/tabs/{$tab->id}/{$what}", ['version' => StructureVersion::current($this->sheet->id), ...$body]);
    }

    /**
     * @return array{r1: int, r2: int, c1: int, c2: int}
     */
    private function area(int $r1, int $c1, int $r2, int $c2): array
    {
        return ['r1' => $r1, 'r2' => $r2, 'c1' => $c1, 'c2' => $c2];
    }

    /**
     * @param  array<string, string>  $cells  "row:col" => contents
     */
    private function write(array $cells, ?SheetTab $tab = null): void
    {
        $edits = [];
        foreach ($cells as $place => $value) {
            [$row, $col] = array_map('intval', explode(':', $place));
            $edits[] = ['row' => $row, 'col' => $col, 'value' => $value];
        }
        app(CellWriter::class)->write($this->sheet->id, ($tab ?? $this->tab)->id, $edits, $this->owner->id);
    }

    private function value(int $row, int $col, ?SheetTab $tab = null): ?string
    {
        $cell = Cell::where('sheet_tab_id', ($tab ?? $this->tab)->id)->where('row_index', $row)->where('col_index', $col)->first();

        return $cell === null ? null : (string) ($cell->formula ?? $cell->raw_value);
    }

    /**
     * @return list<array{r1: int, r2: int, c1: int, c2: int}>
     */
    private function merges(?SheetTab $tab = null): array
    {
        return SheetMerges::of(($tab ?? $this->tab)->fresh()->layout);
    }

    /**
     * Replay an answer's undo the way the browser does.
     *
     * @param  list<array<string, mixed>>  $groups
     * @return list<array<string, mixed>>
     */
    private function replay(array $groups): array
    {
        $back = [];
        foreach ($groups as $group) {
            $tab = SheetTab::findOrFail($group['tab']);
            $body = ['edits' => $group['edits'], ...(isset($group['merges']) ? ['merges' => $group['merges']] : [])];
            $back = [...$back, ...$this->ask('cells', $body, $tab)->assertOk()->json('undo')];
        }

        return $back;
    }

    public function test_merging_keeps_the_first_contents_and_says_what_was_cleared(): void
    {
        $this->write(['0:0' => 'Title', '0:1' => 'Lost', '1:0' => 'Gone too']);

        $this->ask('merge', $this->area(0, 0, 1, 2))->assertOk()
            ->assertJsonPath('cleared', 2)
            ->assertJsonPath('merges', [$this->area(0, 0, 1, 2)]);

        $this->assertSame('Title', $this->value(0, 0));
        $this->assertNull($this->value(0, 1));
        $this->assertNull($this->value(1, 0));
    }

    public function test_when_the_first_cell_is_empty_the_first_thing_in_the_area_moves_there(): void
    {
        $this->write(['0:1' => '=1+1', '1:1' => 'Later']);

        $this->ask('merge', $this->area(0, 0, 1, 1))->assertOk()->assertJsonPath('cleared', 1);

        $this->assertSame('=1+1', $this->value(0, 0));
        $this->assertNull($this->value(0, 1));
        $this->assertNull($this->value(1, 1));
    }

    public function test_undo_puts_merged_contents_back_and_redo_merges_them_again(): void
    {
        $this->write(['0:1' => 'First', '0:2' => 'Second']);

        $undo = $this->ask('merge', $this->area(0, 0, 0, 2))->assertOk()->json('undo');
        $this->assertSame('First', $this->value(0, 0));

        $redo = $this->replay($undo);
        $this->assertSame([], $this->merges());
        $this->assertNull($this->value(0, 0));
        $this->assertSame('First', $this->value(0, 1));
        $this->assertSame('Second', $this->value(0, 2));

        $this->replay($redo);
        $this->assertSame([$this->area(0, 0, 0, 2)], $this->merges());
        $this->assertSame('First', $this->value(0, 0));
        $this->assertNull($this->value(0, 1));
    }

    public function test_unmerging_keeps_the_contents_and_can_be_undone(): void
    {
        $this->write(['0:0' => 'Title']);
        $this->ask('merge', $this->area(0, 0, 0, 2));

        $undo = $this->ask('unmerge', $this->area(0, 0, 0, 2))->assertOk()->assertJsonPath('merges', [])->json('undo');
        $this->assertSame('Title', $this->value(0, 0));

        $this->replay($undo);
        $this->assertSame([$this->area(0, 0, 0, 2)], $this->merges());

        $this->ask('unmerge', $this->area(5, 5, 6, 6))->assertStatus(422)->assertJsonPath('message', 'Nothing here is merged.');
    }

    public function test_a_merge_needs_two_cells_and_a_size_the_sheet_can_hold(): void
    {
        $this->ask('merge', $this->area(0, 0, 0, 0))->assertStatus(422);
        $this->ask('merge', $this->area(0, 0, 10000, 0))->assertStatus(422);
        $this->ask('merge', $this->area(0, 0, SheetStructureService::GRID_ROWS, 0))->assertStatus(422);

        $this->assertSame([], $this->merges());
    }

    public function test_a_new_merge_takes_in_those_inside_it_but_never_cuts_through_one(): void
    {
        $this->ask('merge', $this->area(0, 0, 0, 1));

        $this->ask('merge', $this->area(0, 1, 0, 2))->assertStatus(422)->assertJsonPath('message', SheetMerges::SPLIT_MESSAGE);
        $this->assertSame([$this->area(0, 0, 0, 1)], $this->merges());

        $undo = $this->ask('merge', $this->area(0, 0, 1, 2))->assertOk()->json('undo');
        $this->assertSame([$this->area(0, 0, 1, 2)], $this->merges());

        $this->replay($undo);
        $this->assertSame([$this->area(0, 0, 0, 1)], $this->merges());
    }

    public function test_a_stale_browser_merges_nothing(): void
    {
        $this->write(['0:1' => 'Kept']);

        $this->ask('merge', [...$this->area(0, 0, 0, 1), 'version' => 999])->assertStatus(409);

        $this->assertSame([], $this->merges());
        $this->assertSame('Kept', $this->value(0, 1));
    }

    public function test_only_people_who_can_edit_may_merge(): void
    {
        $viewer = User::factory()->withPersonalTeam()->create();
        $this->actingAs($viewer);
        $this->ask('merge', $this->area(0, 0, 0, 1))->assertForbidden();
        $this->ask('unmerge', $this->area(0, 0, 0, 1))->assertForbidden();

        $this->actingAs($this->owner);
        $other = Spreadsheet::create(['uuid' => (string) str()->uuid(), 'owner_id' => $this->owner->id, 'team_id' => $this->owner->currentTeam->id, 'name' => 'Other']);
        $this->ask('merge', $this->area(0, 0, 0, 1), $other->sheetTabs()->firstOrFail())->assertNotFound();
    }

    public function test_cells_hidden_by_a_merge_take_nothing_but_the_first_cell_works(): void
    {
        $this->ask('merge', $this->area(0, 0, 1, 1));

        $body = $this->ask('cells', ['edits' => [
            ['row' => 0, 'col' => 0, 'value' => 'Here'],
            ['row' => 1, 'col' => 1, 'value' => 'Hidden'],
        ]])->assertOk();

        $body->assertJsonPath('refused.0.row', 1)->assertJsonPath('refused.0.col', 1);
        $this->assertSame('Here', $this->value(0, 0));
        $this->assertNull($this->value(1, 1));
    }

    public function test_clearing_a_selection_across_a_merge_is_not_a_refusal(): void
    {
        $this->ask('merge', $this->area(0, 0, 0, 1));

        $this->ask('cells', ['edits' => [['row' => 0, 'col' => 1, 'value' => '']]])->assertOk()->assertJsonPath('refused', []);
    }

    public function test_formatting_goes_on_the_first_cell_only(): void
    {
        $this->ask('merge', $this->area(0, 0, 0, 2));

        $this->ask('format', [...$this->area(0, 0, 0, 2), 'bold' => true])->assertOk();

        $this->assertTrue(Cell::where('sheet_tab_id', $this->tab->id)->where('col_index', 0)->firstOrFail()->formatting['bold']);
        $this->assertSame(1, Cell::where('sheet_tab_id', $this->tab->id)->count());
    }

    public function test_formulas_read_a_merged_cell_through_its_first_cell(): void
    {
        $this->write(['0:0' => '40', '0:1' => '2', '3:0' => '=A1+B1']);

        $this->ask('merge', $this->area(0, 0, 0, 1))->assertOk();

        $this->assertSame('40', (string) Cell::where('sheet_tab_id', $this->tab->id)->where('row_index', 3)->firstOrFail()->computed_value);
    }

    public function test_a_merge_cannot_be_laid_over_a_spilled_answer(): void
    {
        $this->write(['0:0' => '=SEQUENCE(3)']);

        $this->ask('merge', $this->area(1, 0, 1, 1))->assertStatus(422);
        $this->ask('merge', $this->area(0, 0, 0, 1))->assertStatus(422);

        $this->assertSame([], $this->merges());
    }

    public function test_an_answer_cannot_spill_onto_a_merged_cell(): void
    {
        $this->ask('merge', $this->area(1, 0, 1, 1));

        $this->write(['0:0' => '=SEQUENCE(3)']);

        $cell = Cell::where('sheet_tab_id', $this->tab->id)->where('row_index', 0)->firstOrFail();
        $this->assertSame('#SPILL!', $cell->computed_value);
        $this->assertSame(1, Cell::where('sheet_tab_id', $this->tab->id)->count());
    }

    public function test_copying_a_merged_cell_brings_the_merge_with_it(): void
    {
        $this->write(['0:0' => 'Title']);
        $this->ask('merge', $this->area(0, 0, 0, 1));

        $this->ask('paste', ['from_tab' => $this->tab->id, ...$this->area(0, 0, 0, 1), 'row' => 3, 'col' => 0, 'rows' => 1, 'cols' => 1])
            ->assertOk()->assertJsonPath('merges', [$this->area(0, 0, 0, 1), $this->area(3, 0, 3, 1)]);

        $this->assertSame('Title', $this->value(3, 0));
    }

    public function test_a_copy_repeated_over_a_selection_repeats_its_merges(): void
    {
        $this->ask('merge', $this->area(0, 0, 0, 1));

        $this->ask('paste', ['from_tab' => $this->tab->id, ...$this->area(0, 0, 0, 1), 'row' => 2, 'col' => 0, 'rows' => 2, 'cols' => 2])->assertOk();

        $this->assertSame([$this->area(0, 0, 0, 1), $this->area(2, 0, 2, 1), $this->area(3, 0, 3, 1)], $this->merges());
    }

    public function test_moving_a_merged_cell_moves_it_and_undo_moves_it_back(): void
    {
        $this->write(['0:0' => 'Title']);
        $this->ask('merge', $this->area(0, 0, 0, 1));

        $undo = $this->ask('paste', ['from_tab' => $this->tab->id, ...$this->area(0, 0, 0, 1), 'row' => 0, 'col' => 1, 'rows' => 1, 'cols' => 1, 'cut' => true])
            ->assertOk()->json('undo');

        $this->assertSame([$this->area(0, 1, 0, 2)], $this->merges());
        $this->assertSame('Title', $this->value(0, 1));
        $this->assertNull($this->value(0, 0));

        $this->replay($undo);
        $this->assertSame([$this->area(0, 0, 0, 1)], $this->merges());
        $this->assertSame('Title', $this->value(0, 0));
        $this->assertNull($this->value(0, 1));
    }

    public function test_moving_a_merged_cell_to_another_sheet_takes_it_off_this_one(): void
    {
        $other = $this->sheet->sheetTabs()->create(['name' => 'Other', 'sort_order' => 2]);
        $this->write(['0:0' => 'Title']);
        $this->ask('merge', $this->area(0, 0, 0, 1));

        $undo = $this->ask('paste', ['from_tab' => $this->tab->id, ...$this->area(0, 0, 0, 1), 'row' => 2, 'col' => 2, 'rows' => 1, 'cols' => 1, 'cut' => true], $other)
            ->assertOk()->json('undo');

        $this->assertSame([], $this->merges());
        $this->assertSame([$this->area(2, 2, 2, 3)], $this->merges($other));

        $this->replay($undo);
        $this->assertSame([$this->area(0, 0, 0, 1)], $this->merges());
        $this->assertSame([], $this->merges($other));
    }

    public function test_a_paste_may_replace_a_whole_merge_but_not_part_of_one(): void
    {
        $this->write(['5:0' => 'One', '5:1' => 'Two']);
        $this->ask('merge', $this->area(0, 0, 0, 1));

        $this->ask('paste', ['from_tab' => $this->tab->id, ...$this->area(5, 0, 5, 0), 'row' => 0, 'col' => 1, 'rows' => 1, 'cols' => 1])
            ->assertStatus(422)->assertJsonPath('message', SheetMerges::SPLIT_MESSAGE);
        $this->assertSame([$this->area(0, 0, 0, 1)], $this->merges());

        $undo = $this->ask('paste', ['from_tab' => $this->tab->id, ...$this->area(5, 0, 5, 1), 'row' => 0, 'col' => 0, 'rows' => 1, 'cols' => 2])->assertOk()->json('undo');
        $this->assertSame([], $this->merges());
        $this->assertSame('Two', $this->value(0, 1));

        $this->replay($undo);
        $this->assertSame([$this->area(0, 0, 0, 1)], $this->merges());
        $this->assertNull($this->value(0, 1));
    }

    public function test_a_paste_of_part_of_a_merge_is_refused(): void
    {
        $this->ask('merge', $this->area(0, 0, 0, 1));

        $this->ask('paste', ['from_tab' => $this->tab->id, ...$this->area(0, 0, 0, 0), 'row' => 4, 'col' => 0, 'rows' => 1, 'cols' => 1])
            ->assertStatus(422)->assertJsonPath('message', SheetMerges::PART_MESSAGE);
    }

    public function test_one_value_pasted_on_a_merged_cell_lands_in_its_first_cell(): void
    {
        $this->write(['5:0' => 'New']);
        $this->ask('merge', $this->area(0, 0, 1, 1));

        // The browser sends a selected merged cell as one cell.
        $this->ask('paste', ['from_tab' => $this->tab->id, ...$this->area(5, 0, 5, 0), 'row' => 0, 'col' => 0, 'rows' => 1, 'cols' => 1])->assertOk();

        $this->assertSame('New', $this->value(0, 0));
        $this->assertSame([$this->area(0, 0, 1, 1)], $this->merges());
    }

    public function test_fill_will_not_cut_a_merge_and_replaces_one_it_covers_whole(): void
    {
        $fill = fn (array $from, array $to) => $this->ask('fill', [...$from, 'to_r1' => $to['r1'], 'to_r2' => $to['r2'], 'to_c1' => $to['c1'], 'to_c2' => $to['c2']]);

        $this->write(['0:0' => '1']);
        $this->ask('merge', $this->area(2, 0, 2, 1));

        $fill($this->area(0, 0, 0, 0), $this->area(0, 0, 3, 0))->assertStatus(422)->assertJsonPath('message', SheetMerges::SPLIT_MESSAGE);

        $this->ask('cells', ['edits' => [['row' => 0, 'col' => 1, 'value' => '2']]]);
        $undo = $fill($this->area(0, 0, 0, 1), $this->area(0, 0, 3, 1))->assertOk()->json('undo');
        $this->assertSame([], $this->merges());
        $this->assertSame('2', $this->value(2, 1));

        $this->replay($undo);
        $this->assertSame([$this->area(2, 0, 2, 1)], $this->merges());

        $fill($this->area(2, 0, 2, 0), $this->area(2, 0, 4, 0))->assertStatus(422)->assertJsonPath('message', SheetMerges::PART_MESSAGE);
    }

    public function test_rows_and_columns_going_in_and_out_move_merged_cells(): void
    {
        config(['sheet.structure_ops_enabled' => true]);
        $structure = app(SheetStructureService::class);
        $this->ask('merge', $this->area(2, 1, 4, 3));

        $structure->insertRow($this->sheet->id, $this->tab->id, 0);
        $this->assertSame([$this->area(3, 1, 5, 3)], $this->merges());

        $structure->insertRow($this->sheet->id, $this->tab->id, 4);
        $this->assertSame([$this->area(3, 1, 6, 3)], $this->merges());

        $structure->insertRow($this->sheet->id, $this->tab->id, 20);
        $this->assertSame([$this->area(3, 1, 6, 3)], $this->merges());

        $structure->insertColumn($this->sheet->id, $this->tab->id, 2);
        $this->assertSame([$this->area(3, 1, 6, 4)], $this->merges());

        $structure->deleteColumn($this->sheet->id, $this->tab->id, 2);
        $structure->deleteRow($this->sheet->id, $this->tab->id, 3);
        $this->assertSame([$this->area(3, 1, 5, 3)], $this->merges());
    }

    public function test_a_merge_left_with_one_cell_or_none_is_gone(): void
    {
        config(['sheet.structure_ops_enabled' => true]);
        $structure = app(SheetStructureService::class);
        $this->ask('merge', $this->area(0, 0, 1, 0));
        $this->ask('merge', $this->area(4, 0, 4, 1));

        $structure->deleteRow($this->sheet->id, $this->tab->id, 1);
        $this->assertSame([$this->area(3, 0, 3, 1)], $this->merges());

        $structure->deleteRow($this->sheet->id, $this->tab->id, 3);
        $this->assertSame([], $this->merges());
    }

    public function test_excel_files_carry_merged_cells(): void
    {
        $this->write(['0:0' => 'Title']);
        $this->ask('merge', $this->area(0, 0, 1, 2));

        $path = app(SpreadsheetImportExportService::class)->exportExcel($this->sheet, $this->tab->fresh());

        $book = IOFactory::load($path);
        $this->assertContains('A1:C2', array_values($book->getActiveSheet()->getMergeCells()));
    }

    public function test_the_sheet_sends_its_merged_cells_to_the_browser(): void
    {
        $this->ask('merge', $this->area(1, 1, 1, 2));

        $this->getJson("/spreadsheets/{$this->sheet->id}/tabs/{$this->tab->id}/sheet")->assertOk()->assertJsonPath('layout.merges', [$this->area(1, 1, 1, 2)]);
    }

    public function test_a_bad_list_in_the_layout_is_read_as_nothing(): void
    {
        $this->assertSame([], SheetMerges::of(['merges' => 'nonsense']));
        $this->assertSame([], SheetMerges::of(['merges' => [['r1' => 0], 'x', ['r1' => 3, 'r2' => 1, 'c1' => 0, 'c2' => 0], ['r1' => 1, 'r2' => 1, 'c1' => 1, 'c2' => 1]]]));
        $this->assertSame([], SheetMerges::of(null));
    }
}
