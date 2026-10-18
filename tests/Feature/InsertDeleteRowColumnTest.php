<?php

namespace Tests\Feature;

use App\Livewire\ShowSpreadsheet;
use App\Models\Cell;
use App\Models\NamedRange;
use App\Models\SheetTab;
use App\Models\Spreadsheet;
use App\Models\User;
use App\Services\SheetStructureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Context menu: insert/delete row and column. The cells table has a unique key on
 * (spreadsheet_id, sheet_tab_id, row_index, col_index) and SQLite (production's engine)
 * checks it row by row, so "UPDATE cells SET row_index = row_index + 1" collided with the
 * next row and returned a 500. These run on SQLite, the engine of the test suite.
 */
class InsertDeleteRowColumnTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Spreadsheet $spreadsheet;

    private SheetTab $tab;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->spreadsheet = Spreadsheet::create([
            'uuid' => (string) str()->uuid(),
            'owner_id' => $this->user->id,
            'name' => 'Grid',
        ]);
        $this->tab = $this->spreadsheet->defaultSheetTab();
    }

    private function cell(int $row, int $col, ?string $raw, ?string $formula = null, ?string $computed = null, ?SheetTab $tab = null): Cell
    {
        return Cell::create([
            'spreadsheet_id' => $this->spreadsheet->id,
            'sheet_tab_id' => ($tab ?? $this->tab)->id,
            'row_index' => $row,
            'col_index' => $col,
            'raw_value' => $raw,
            'computed_value' => $computed,
            'formula' => $formula,
            'updated_at' => now(),
        ]);
    }

    private function editor(): Testable
    {
        return Livewire::actingAs($this->user)->test(ShowSpreadsheet::class, ['spreadsheet_id' => $this->spreadsheet->id]);
    }

    /**
     * @return array<string, string|null> "row_col" => raw value
     */
    private function grid(?SheetTab $tab = null): array
    {
        return Cell::where('sheet_tab_id', ($tab ?? $this->tab)->id)
            ->orderBy('row_index')->orderBy('col_index')
            ->get()
            ->mapWithKeys(fn (Cell $c) => ["{$c->row_index}_{$c->col_index}" => $c->raw_value ?? $c->formula])
            ->all();
    }

    public function test_insert_row_shifts_the_rows_below_down_by_one(): void
    {
        foreach (range(0, 4) as $r) {
            $this->cell($r, 0, "r{$r}c0");
            $this->cell($r, 1, "r{$r}c1");
        }

        $this->editor()->set('selectedRow', 2)->call('insertRowSelected')->assertOk();

        $this->assertSame([
            '0_0' => 'r0c0', '0_1' => 'r0c1',
            '1_0' => 'r1c0', '1_1' => 'r1c1',
            '3_0' => 'r2c0', '3_1' => 'r2c1',
            '4_0' => 'r3c0', '4_1' => 'r3c1',
            '5_0' => 'r4c0', '5_1' => 'r4c1',
        ], $this->grid());
    }

    public function test_insert_row_works_when_rows_were_written_out_of_order(): void
    {
        // Physical (rowid) order differs from row order, which is what makes a bulk
        // "+1" collide on engines that check a unique key row by row.
        foreach ([5, 1, 4, 0, 3, 2] as $r) {
            $this->cell($r, 0, "v{$r}");
        }

        $this->editor()->set('selectedRow', 0)->call('insertRowSelected');

        $this->assertSame(['1_0' => 'v0', '2_0' => 'v1', '3_0' => 'v2', '4_0' => 'v3', '5_0' => 'v4', '6_0' => 'v5'], $this->grid());
    }

    public function test_insert_row_below_the_last_row_changes_nothing(): void
    {
        $this->cell(0, 0, 'a');
        $this->editor()->set('selectedRow', 9)->call('insertRowSelected');

        $this->assertSame(['0_0' => 'a'], $this->grid());
    }

    public function test_insert_row_on_an_empty_sheet_does_not_fail(): void
    {
        $this->editor()->set('selectedRow', 0)->call('insertRowSelected')->assertOk();

        $this->assertSame([], $this->grid());
    }

    public function test_insert_row_leaves_other_tabs_alone(): void
    {
        $other = SheetTab::create(['spreadsheet_id' => $this->spreadsheet->id, 'name' => 'Other', 'sort_order' => 1]);
        $this->cell(0, 0, 'a');
        $this->cell(1, 0, 'b');
        $this->cell(1, 0, 'other-b', null, null, $other);

        $this->editor()->set('selectedRow', 0)->call('insertRowSelected');

        $this->assertSame(['1_0' => 'a', '2_0' => 'b'], $this->grid());
        $this->assertSame(['1_0' => 'other-b'], $this->grid($other));
    }

    public function test_insert_column_shifts_the_columns_to_the_right(): void
    {
        foreach (range(0, 3) as $c) {
            $this->cell(0, $c, "c{$c}");
            $this->cell(1, $c, "d{$c}");
        }

        $this->editor()->set('selectedCol', 1)->call('insertColumnSelected')->assertOk();

        $this->assertSame([
            '0_0' => 'c0', '0_2' => 'c1', '0_3' => 'c2', '0_4' => 'c3',
            '1_0' => 'd0', '1_2' => 'd1', '1_3' => 'd2', '1_4' => 'd3',
        ], $this->grid());
    }

    public function test_delete_row_removes_it_and_pulls_the_rows_below_up(): void
    {
        foreach ([4, 2, 0, 3, 1] as $r) {
            $this->cell($r, 0, "v{$r}");
        }

        $this->editor()->set('selectedRow', 1)->call('deleteRowSelected')->assertOk();

        $this->assertSame(['0_0' => 'v0', '1_0' => 'v2', '2_0' => 'v3', '3_0' => 'v4'], $this->grid());
    }

    public function test_delete_column_removes_it_and_pulls_the_columns_right_of_it_left(): void
    {
        foreach ([3, 1, 0, 2] as $c) {
            $this->cell(0, $c, "c{$c}");
        }

        $this->editor()->set('selectedCol', 1)->call('deleteColumnSelected')->assertOk();

        $this->assertSame(['0_0' => 'c0', '0_1' => 'c2', '0_2' => 'c3'], $this->grid());
    }

    public function test_insert_then_delete_round_trips_the_grid(): void
    {
        foreach (range(0, 6) as $r) {
            $this->cell($r, 0, "v{$r}");
        }
        $before = $this->grid();

        $this->editor()->set('selectedRow', 3)->call('insertRowSelected')->call('deleteRowSelected');

        $this->assertSame($before, $this->grid());
    }

    public function test_cell_formatting_moves_with_the_cell(): void
    {
        $cell = $this->cell(2, 0, 'x');
        $cell->update(['formatting' => ['bold' => true]]);

        $this->editor()->set('selectedRow', 0)->call('insertRowSelected');

        $moved = Cell::where('sheet_tab_id', $this->tab->id)->sole();
        $this->assertSame(3, $moved->row_index);
        $this->assertSame(['bold' => true], $moved->formatting);
    }

    private function sumSheet(): Cell
    {
        $this->cell(0, 0, '7', null, null);
        $this->cell(1, 0, '3', null, null);

        return $this->cell(2, 0, '', '=SUM(A1:A2)', '10');
    }

    private function formulaCell(int $row, int $col): Cell
    {
        return Cell::where('sheet_tab_id', $this->tab->id)->where('row_index', $row)->where('col_index', $col)->sole();
    }

    public function test_inserting_above_a_formula_moves_its_references_and_keeps_its_value(): void
    {
        $this->sumSheet();

        $this->editor()->set('selectedRow', 0)->call('insertRowSelected');

        $formula = $this->formulaCell(3, 0);
        $this->assertSame('=SUM(A2:A3)', $formula->formula);
        $this->assertEquals(10, $formula->computed_value);
    }

    public function test_inserting_inside_a_range_grows_the_range(): void
    {
        $this->sumSheet();

        $this->editor()->set('selectedRow', 1)->call('insertRowSelected');

        $formula = $this->formulaCell(3, 0);
        $this->assertSame('=SUM(A1:A3)', $formula->formula);
        $this->assertEquals(10, $formula->computed_value);
    }

    public function test_inserting_below_the_references_leaves_the_formula_text_alone(): void
    {
        $this->sumSheet();

        $this->editor()->set('selectedRow', 2)->call('insertRowSelected');

        $this->assertSame('=SUM(A1:A2)', $this->formulaCell(3, 0)->formula);
    }

    public function test_deleting_a_row_inside_a_range_shrinks_it_and_recomputes(): void
    {
        $this->sumSheet();

        $this->editor()->set('selectedRow', 1)->call('deleteRowSelected');

        $formula = $this->formulaCell(1, 0);
        $this->assertSame('=SUM(A1:A1)', $formula->formula);
        $this->assertEquals(7, $formula->computed_value);
    }

    public function test_deleting_a_row_a_formula_points_at_gives_a_ref_error(): void
    {
        $this->cell(0, 0, '5');
        $this->cell(1, 0, '', '=A1', '5');

        $this->editor()->set('selectedRow', 0)->call('deleteRowSelected');

        $formula = $this->formulaCell(0, 0);
        $this->assertSame('=#REF!', $formula->formula);
        $this->assertSame('#REF!', $formula->computed_value);
    }

    public function test_column_insert_and_delete_move_references_too(): void
    {
        $this->cell(0, 0, '4');
        $this->cell(0, 1, '', '=A1*1', '4');
        $this->cell(0, 2, '', '=SUM(A1:B1)', '8');

        $editor = $this->editor();
        $editor->set('selectedCol', 0)->call('insertColumnSelected');

        $this->assertSame('=B1*1', $this->formulaCell(0, 2)->formula);
        $this->assertSame('=SUM(B1:C1)', $this->formulaCell(0, 3)->formula);

        $editor->set('selectedCol', 0)->call('deleteColumnSelected');

        $this->assertSame('=A1*1', $this->formulaCell(0, 1)->formula);
        $this->assertSame('=SUM(A1:B1)', $this->formulaCell(0, 2)->formula);
    }

    public function test_formulas_on_other_tabs_are_not_rewritten(): void
    {
        $other = SheetTab::create(['spreadsheet_id' => $this->spreadsheet->id, 'name' => 'Other', 'sort_order' => 1]);
        $this->cell(0, 0, '', '=A1', '0', $other);
        $this->cell(1, 0, 'x');

        $this->editor()->set('selectedRow', 0)->call('insertRowSelected');

        $this->assertSame('=A1', Cell::where('sheet_tab_id', $other->id)->sole()->formula);
    }

    public function test_named_ranges_follow_inserted_and_deleted_rows(): void
    {
        $range = NamedRange::create([
            'spreadsheet_id' => $this->spreadsheet->id, 'sheet_tab_id' => $this->tab->id, 'name' => 'Sales',
            'start_row' => 2, 'start_col' => 0, 'end_row' => 4, 'end_col' => 0,
        ]);

        $editor = $this->editor();
        $editor->set('selectedRow', 0)->call('insertRowSelected');
        $range->refresh();
        $this->assertSame([3, 5], [$range->start_row, $range->end_row]);

        $editor->set('selectedRow', 3)->call('deleteRowSelected');
        $range->refresh();
        $this->assertSame([3, 4], [$range->start_row, $range->end_row]);
    }

    public function test_reference_rewriting_ignores_function_names_strings_and_keeps_case(): void
    {
        $service = new SheetStructureService;

        $this->assertSame('=LOG10(B3)+B3', $service->rewriteReferences('=LOG10(B2)+b2', 'row_index', 0, 1));
        $this->assertSame('=CONCAT("A1",A3)', $service->rewriteReferences('=CONCAT("A1",A2)', 'row_index', 0, 1));
        $this->assertSame('=a1+B2', $service->rewriteReferences('=a1+B2', 'row_index', 5, 1));
        $this->assertSame('=AA1+AB1', $service->rewriteReferences('=Z1+AA1', 'col_index', 25, 1));
        $this->assertSame('=#REF!+A1', $service->rewriteReferences('=A1+B1', 'col_index', 0, -1));
    }
}
