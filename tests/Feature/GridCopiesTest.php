<?php

namespace Tests\Feature;

use App\Models\Cell;
use App\Models\SheetTab;
use App\Models\Spreadsheet;
use App\Models\SpreadsheetVersion;
use App\Models\User;
use App\Services\CellWriter;
use App\Services\SheetVersions;
use App\Services\StructureVersion;
use App\Services\WorkbookSheets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class GridCopiesTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Spreadsheet $sheet;

    private SheetTab $tab;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->withPersonalTeam()->create(['name' => 'Olwethu']);
        $this->sheet = Spreadsheet::create(['uuid' => (string) str()->uuid(), 'owner_id' => $this->owner->id, 'team_id' => $this->owner->currentTeam->id, 'name' => 'Kept']);
        $this->tab = $this->sheet->sheetTabs()->firstOrFail();
        $this->actingAs($this->owner);
    }

    private function url(string $path = 'copies', ?SheetTab $tab = null): string
    {
        return "/spreadsheets/{$this->sheet->id}/tabs/".($tab ?? $this->tab)->id."/{$path}";
    }

    private function copy(string $kind = SheetVersions::MANUAL, string $label = 'Month end', ?SheetTab $tab = null): SpreadsheetVersion
    {
        return app(SheetVersions::class)->snapshot($this->sheet->id, ($tab ?? $this->tab)->id, $this->owner->id, $kind, $label);
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

    private function value(int $row, int $col): ?string
    {
        return Cell::where('sheet_tab_id', $this->tab->id)->where('row_index', $row)->where('col_index', $col)->value('raw_value');
    }

    private function restore(int $copy): TestResponse
    {
        return $this->postJson($this->url('restore'), ['version' => StructureVersion::current($this->sheet->id), 'copy' => $copy]);
    }

    public function test_copies_are_listed_newest_first_in_plain_words(): void
    {
        $this->copy(SheetVersions::AUTO, 'Auto Snapshot');
        $this->copy(SheetVersions::MANUAL, 'Manual Snapshot');
        $this->copy(SheetVersions::MANUAL, 'Month end');
        $this->copy(SheetVersions::SAFETY, 'Before deleting rows');

        $answer = $this->getJson($this->url())->assertOk()->json();

        $this->assertSame(['Before deleting rows', 'Month end', 'Saved copy', 'Automatic copy'], array_column($answer['copies'], 'name'));
        $this->assertSame('Olwethu', $answer['copies'][0]['who']);
        $this->assertSame('safety', $answer['copies'][0]['kind']);
        $this->assertSame(0, $answer['more']);
        $this->assertArrayNotHasKey('cells_snapshot', $answer['copies'][0]);
    }

    public function test_the_list_stops_at_fifty_and_says_how_many_more(): void
    {
        foreach (range(1, 52) as $n) {
            $this->copy(SheetVersions::MANUAL, "Copy {$n}");
        }

        $answer = $this->getJson($this->url())->assertOk()->json();

        $this->assertCount(50, $answer['copies']);
        $this->assertSame(2, $answer['more']);
        $this->assertSame('Copy 52', $answer['copies'][0]['name']);
    }

    public function test_each_sheet_lists_its_own_copies(): void
    {
        $second = app(WorkbookSheets::class)->add($this->sheet, 'Second');
        $this->copy(SheetVersions::MANUAL, 'On the first');
        $this->copy(SheetVersions::MANUAL, 'On the second', $second);

        $this->assertSame(['On the second'], array_column($this->getJson($this->url('copies', $second))->json('copies'), 'name'));
    }

    public function test_a_copy_is_saved_with_its_name_or_without_one(): void
    {
        $this->write(['0:0' => 'Kept']);

        $this->postJson($this->url(), ['label' => '  Before the audit  '])->assertOk()->assertJsonPath('copies.0.name', 'Before the audit')->assertJsonPath('copies.0.cells', 1);
        $this->postJson($this->url(), ['label' => ''])->assertOk()->assertJsonPath('copies.0.name', 'Saved copy');
        $this->postJson($this->url(), ['label' => str_repeat('a', 121)])->assertUnprocessable()->assertJsonPath('message', 'Use a shorter name: 120 characters at most.');

        $this->assertSame(SheetVersions::MANUAL, SpreadsheetVersion::latest('id')->firstOrFail()->kind);
    }

    public function test_it_says_how_many_cells_a_copy_would_change(): void
    {
        $this->write(['0:0' => 'same', '1:0' => 'old', '2:0' => 'goes', '3:0' => '=1+1']);
        $copy = $this->copy();
        $this->write(['1:0' => 'new', '2:0' => '', '5:5' => 'added']);

        $this->getJson($this->url("copies/{$copy->id}/changes"))->assertOk()->assertJsonPath('changes', 3);
    }

    public function test_a_change_of_format_counts_and_a_worked_out_answer_does_not(): void
    {
        $this->write(['0:0' => 'plain', '1:0' => '=1+1']);
        $copy = $this->copy();

        Cell::where('sheet_tab_id', $this->tab->id)->where('row_index', 1)->update(['computed_value' => '99']);
        $this->getJson($this->url("copies/{$copy->id}/changes"))->assertJsonPath('changes', 0);

        app(CellWriter::class)->write($this->sheet->id, $this->tab->id, [['row' => 0, 'col' => 0, 'value' => 'plain', 'x' => ['bold' => true]]], $this->owner->id);
        $this->getJson($this->url("copies/{$copy->id}/changes"))->assertJsonPath('changes', 1);
    }

    public function test_a_cell_only_a_formula_landed_on_does_not_count(): void
    {
        $this->write(['0:0' => '=SEQUENCE(3)']);
        $copy = $this->copy();

        $this->assertNotSame(0, Cell::where('sheet_tab_id', $this->tab->id)->whereNotNull('spill_row')->count());
        $this->getJson($this->url("copies/{$copy->id}/changes"))->assertJsonPath('changes', 0);
    }

    public function test_any_copy_of_the_sheet_can_be_put_back_and_the_sheet_as_it_was_is_kept(): void
    {
        $this->write(['0:0' => 'then']);
        $copy = $this->copy(SheetVersions::MANUAL, 'Month end');
        $this->write(['0:0' => 'now']);

        $before = StructureVersion::current($this->sheet->id);
        $answer = $this->restore($copy->id)->assertOk()->json();

        $this->assertSame('then', $this->value(0, 0));
        $this->assertGreaterThan($before, $answer['version']);
        $safety = SpreadsheetVersion::where('kind', SheetVersions::SAFETY)->latest('id')->firstOrFail();
        $this->assertStringContainsString('Month end', $safety->label);
        $this->assertSame('now', $safety->cells_snapshot[0]['raw_value']);

        $this->restore($safety->id)->assertOk();
        $this->assertSame('now', $this->value(0, 0));
    }

    public function test_a_stale_page_puts_nothing_back(): void
    {
        $this->write(['0:0' => 'then']);
        $copy = $this->copy();
        $this->write(['0:0' => 'now']);

        $this->postJson($this->url('restore'), ['version' => StructureVersion::current($this->sheet->id) - 1, 'copy' => $copy->id])->assertConflict();

        $this->assertSame('now', $this->value(0, 0));
    }

    public function test_a_copy_is_reached_only_through_its_own_sheet(): void
    {
        $second = app(WorkbookSheets::class)->add($this->sheet, 'Second');
        $elsewhere = $this->copy(SheetVersions::MANUAL, 'Elsewhere', $second);

        $this->getJson($this->url("copies/{$elsewhere->id}/changes"))->assertNotFound();
        $this->restore($elsewhere->id)->assertNotFound();
    }

    public function test_someone_who_can_read_sees_the_list_and_changes_nothing(): void
    {
        $this->write(['0:0' => 'then']);
        $copy = $this->copy();
        $this->write(['0:0' => 'now']);
        $reader = User::factory()->withPersonalTeam()->create();
        $this->sheet->sharedUsers()->attach($reader->id, ['permission' => 'view']);

        $this->actingAs($reader);
        $this->getJson($this->url())->assertOk()->assertJsonPath('copies.0.name', 'Month end');
        $this->postJson($this->url(), ['label' => 'Mine'])->assertForbidden();
        $this->getJson($this->url("copies/{$copy->id}/changes"))->assertForbidden();
        $this->restore($copy->id)->assertForbidden();

        $this->assertSame('now', $this->value(0, 0));
        $this->assertSame(1, SpreadsheetVersion::where('kind', SheetVersions::MANUAL)->count());
    }

    public function test_a_stranger_sees_nothing(): void
    {
        $this->copy();

        $this->actingAs(User::factory()->withPersonalTeam()->create());
        $this->getJson($this->url())->assertForbidden();
    }
}
