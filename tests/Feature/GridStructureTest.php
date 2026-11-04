<?php

namespace Tests\Feature;

use App\Models\Cell;
use App\Models\NamedRange;
use App\Models\SheetTab;
use App\Models\Spreadsheet;
use App\Models\SpreadsheetVersion;
use App\Models\User;
use App\Services\CellWriter;
use App\Services\SheetMerges;
use App\Services\SheetStructureService;
use App\Services\SheetVersions;
use App\Services\StructureChangeRefused;
use App\Services\StructureVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class GridStructureTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Spreadsheet $sheet;

    private SheetTab $tab;

    protected function setUp(): void
    {
        parent::setUp();

        config(['sheet.structure_ops_enabled' => true]);
        $this->owner = User::factory()->withPersonalTeam()->create();
        $this->sheet = Spreadsheet::create(['uuid' => (string) str()->uuid(), 'owner_id' => $this->owner->id, 'team_id' => $this->owner->currentTeam->id, 'name' => 'Lines']);
        $this->tab = $this->sheet->sheetTabs()->firstOrFail();
        $this->actingAs($this->owner);

        app(CellWriter::class)->write($this->sheet->id, $this->tab->id, [
            ['row' => 0, 'col' => 0, 'value' => 'Sales'], ['row' => 0, 'col' => 1, 'value' => '10'],
            ['row' => 1, 'col' => 0, 'value' => 'Rent'], ['row' => 1, 'col' => 1, 'value' => '20'],
            ['row' => 2, 'col' => 0, 'value' => 'Net'], ['row' => 2, 'col' => 1, 'value' => '=B1+B2'],
        ], $this->owner->id);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function change(string $action, string $axis, int $at, int $count = 1, array $extra = []): TestResponse
    {
        return $this->postJson("/spreadsheets/{$this->sheet->id}/tabs/{$this->tab->id}/structure", [
            'version' => StructureVersion::current($this->sheet->id), 'action' => $action, 'axis' => $axis, 'at' => $at, 'count' => $count, ...$extra,
        ]);
    }

    private function restore(int $copy, ?SheetTab $tab = null): TestResponse
    {
        $tab ??= $this->tab;

        return $this->postJson("/spreadsheets/{$tab->spreadsheet_id}/tabs/{$tab->id}/restore", ['version' => StructureVersion::current($tab->spreadsheet_id), 'copy' => $copy]);
    }

    private function value(int $row, int $col): ?string
    {
        $cell = Cell::where('sheet_tab_id', $this->tab->id)->where('row_index', $row)->where('col_index', $col)->first();

        return $cell === null ? null : (string) ($cell->formula ?? $cell->raw_value);
    }

    public function test_nothing_moves_while_the_switch_is_off(): void
    {
        config(['sheet.structure_ops_enabled' => false]);

        $this->change('insert', 'row', 0)->assertStatus(422)->assertJsonPath('message', SheetStructureService::DISABLED_MESSAGE);
        $this->change('delete', 'row', 0)->assertStatus(422);

        $this->assertSame('Sales', $this->value(0, 0));
        $this->assertSame(0, SpreadsheetVersion::where('kind', SheetVersions::SAFETY)->count());
    }

    public function test_the_page_tells_the_browser_whether_the_switch_is_on(): void
    {
        $this->get("/spreadsheets/{$this->sheet->id}")->assertOk()->assertSee('"structure":true', false);

        config(['sheet.structure_ops_enabled' => false]);
        $this->get("/spreadsheets/{$this->sheet->id}")->assertOk()->assertSee('"structure":false', false);
    }

    public function test_inserting_rows_moves_cells_and_formulas_and_deleting_undoes_it(): void
    {
        $before = StructureVersion::current($this->sheet->id);

        $undo = $this->change('insert', 'row', 1, 2)->assertOk()->json('undo');

        $this->assertSame(['structure' => ['action' => 'delete', 'axis' => 'row', 'at' => 1, 'count' => 2]], $undo);
        $this->assertSame('Rent', $this->value(3, 0));
        $this->assertSame('=B1+B4', $this->value(4, 1));
        $this->assertGreaterThan($before, StructureVersion::current($this->sheet->id));

        $this->change('delete', 'row', 1, 2)->assertOk();
        $this->assertSame('Rent', $this->value(1, 0));
        $this->assertSame('=B1+B2', $this->value(2, 1));
    }

    public function test_columns_go_in_and_come_out(): void
    {
        $this->change('insert', 'col', 1)->assertOk();
        $this->assertSame('10', $this->value(0, 2));

        $this->change('delete', 'col', 1)->assertOk();
        $this->assertSame('10', $this->value(0, 1));
    }

    public function test_a_delete_takes_a_copy_and_restoring_it_brings_everything_back(): void
    {
        $this->tab->forceFill(['layout' => ['merges' => [['r1' => 5, 'r2' => 5, 'c1' => 0, 'c2' => 1]], 'hidden_rows' => [7]]])->save();

        $copy = $this->change('delete', 'row', 1)->assertOk()->json('undo.restore');

        $this->assertSame('=B1+#REF!', $this->value(1, 1));
        $this->assertSame([['r1' => 4, 'r2' => 4, 'c1' => 0, 'c2' => 1]], SheetMerges::of($this->tab->fresh()->layout));

        $this->restore($copy)->assertOk();

        $this->assertSame('Rent', $this->value(1, 0));
        $this->assertSame('=B1+B2', $this->value(2, 1));
        $this->assertSame([['r1' => 5, 'r2' => 5, 'c1' => 0, 'c2' => 1]], SheetMerges::of($this->tab->fresh()->layout));
        $this->assertSame([7], $this->tab->fresh()->layout['hidden_rows']);
    }

    public function test_after_a_restore_the_formulas_are_worked_out_again(): void
    {
        $copy = $this->change('delete', 'row', 1)->json('undo.restore');
        $this->restore($copy)->assertOk();

        $this->getJson("/spreadsheets/{$this->sheet->id}/tabs/{$this->tab->id}/sheet")->assertOk();

        $this->assertSame('30', (string) Cell::where('sheet_tab_id', $this->tab->id)->where('row_index', 2)->where('col_index', 1)->firstOrFail()->computed_value);
    }

    public function test_several_lines_go_all_or_not_at_all(): void
    {
        $service = $this->mock(SheetStructureService::class);
        $service->shouldReceive('withBudget')->andReturnSelf();
        $calls = 0;
        $service->shouldReceive('deleteRow')->andReturnUsing(function () use (&$calls) {
            if (++$calls === 2) {
                throw new StructureChangeRefused('Too large.');
            }
            DB::table('cells')->where('sheet_tab_id', $this->tab->id)->where('row_index', 0)->delete();
        });

        $this->change('delete', 'row', 0, 3)->assertStatus(422)->assertJsonPath('message', 'Too large.');

        $this->assertSame('Sales', $this->value(0, 0));
    }

    public function test_rows_pushed_off_the_sheet_refuse_the_whole_insert(): void
    {
        app(CellWriter::class)->write($this->sheet->id, $this->tab->id, [['row' => SheetStructureService::GRID_ROWS - 1, 'col' => 0, 'value' => 'Last']], $this->owner->id);

        $this->change('insert', 'row', 0, 2)->assertStatus(422);

        $this->assertSame('Sales', $this->value(0, 0));
        $this->assertSame('Last', $this->value(SheetStructureService::GRID_ROWS - 1, 0));
    }

    public function test_a_sheet_with_named_ranges_has_no_undo_for_a_delete(): void
    {
        NamedRange::create(['spreadsheet_id' => $this->sheet->id, 'sheet_tab_id' => $this->tab->id, 'name' => 'Figures', 'start_row' => 0, 'start_col' => 1, 'end_row' => 1, 'end_col' => 1]);

        $this->change('delete', 'row', 1)->assertOk()->assertJsonPath('undo', null);

        $this->assertSame(0, SpreadsheetVersion::where('kind', SheetVersions::SAFETY)->count());
    }

    public function test_a_very_large_sheet_has_no_undo_for_a_delete(): void
    {
        $now = now();
        foreach (array_chunk(range(10, 10 + SheetVersions::AUTO_MAX_CELLS), 500) as $rows) {
            DB::table('cells')->insert(array_map(fn (int $row) => ['spreadsheet_id' => $this->sheet->id, 'sheet_tab_id' => $this->tab->id, 'row_index' => $row, 'col_index' => 0, 'raw_value' => 'x', 'updated_at' => $now], $rows));
        }

        $this->change('delete', 'col', 5)->assertOk()->assertJsonPath('undo', null);
    }

    public function test_a_stale_browser_moves_nothing_and_takes_no_copy(): void
    {
        $this->change('delete', 'row', 0, 1, ['version' => 999])->assertStatus(409)->assertJsonPath('stale', true);

        $this->assertSame('Sales', $this->value(0, 0));
        $this->assertSame(0, SpreadsheetVersion::where('kind', SheetVersions::SAFETY)->count());
    }

    public function test_a_copy_restores_only_on_its_own_sheet_and_only_if_it_is_a_safety_copy(): void
    {
        $other = Spreadsheet::create(['uuid' => (string) str()->uuid(), 'owner_id' => $this->owner->id, 'team_id' => $this->owner->currentTeam->id, 'name' => 'Other']);
        $copy = $this->change('delete', 'row', 1)->json('undo.restore');

        $this->restore($copy, $other->sheetTabs()->firstOrFail())->assertNotFound();
        $this->restore(999999)->assertNotFound();

        $manual = app(SheetVersions::class)->snapshot($this->sheet->id, $this->tab->id, $this->owner->id, SheetVersions::MANUAL, 'Mine');
        $this->restore($manual->id)->assertNotFound();
    }

    public function test_only_people_who_can_edit_may_change_lines_or_restore(): void
    {
        $copy = $this->change('delete', 'row', 1)->json('undo.restore');

        $this->actingAs(User::factory()->withPersonalTeam()->create());
        $this->change('insert', 'row', 0)->assertForbidden();
        $this->restore($copy)->assertForbidden();
    }

    public function test_limits_on_what_one_change_can_ask_for(): void
    {
        $this->change('insert', 'row', 0, 21)->assertStatus(422);
        $this->change('delete', 'row', 0, 0)->assertStatus(422);
        $this->change('delete', 'col', SheetStructureService::GRID_COLS - 1, 2)->assertStatus(422);
        $this->change('move', 'row', 0)->assertStatus(422);
    }
}
