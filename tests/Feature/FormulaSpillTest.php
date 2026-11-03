<?php

namespace Tests\Feature;

use App\Livewire\ShowSpreadsheet;
use App\Models\Cell;
use App\Models\Spreadsheet;
use App\Models\User;
use App\Services\Formula\FormulaEngine;
use App\Services\Formula\Recalculator;
use App\Services\SheetStructureService;
use App\Services\SheetVersions;
use App\Services\SpreadsheetImportExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A formula with an answer of many cells: =SORT(A1:A3) in C1 fills C1:C3.
 */
class FormulaSpillTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Spreadsheet $spreadsheet;

    private $tab;

    private $editor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->withPersonalTeam()->create();
        $this->spreadsheet = Spreadsheet::create(['uuid' => (string) str()->uuid(), 'owner_id' => $this->user->id, 'team_id' => $this->user->currentTeam->id, 'name' => 'Budget']);
        $this->tab = $this->spreadsheet->defaultSheetTab();
        $this->editor = Livewire::actingAs($this->user)->test(ShowSpreadsheet::class, ['spreadsheet_id' => $this->spreadsheet->id]);

        foreach (['A1' => '3', 'A2' => '1', 'A3' => '2'] as $reference => $value) {
            $this->type($reference, $value);
        }
    }

    private function type(string $reference, string $value): void
    {
        preg_match('/^([A-Z])(\d+)$/', $reference, $m);
        $this->editor->set('editingCell', ((int) $m[2] - 1).'_'.(ord($m[1]) - 65))->call('saveCell', $value);
    }

    private function cell(string $reference): ?Cell
    {
        preg_match('/^([A-Z])(\d+)$/', $reference, $m);

        return Cell::where('sheet_tab_id', $this->tab->id)->where('row_index', (int) $m[2] - 1)->where('col_index', ord($m[1]) - 65)->first();
    }

    private function shown(string $reference): ?string
    {
        return $this->cell($reference)?->computed_value;
    }

    public function test_an_answer_of_many_cells_lands_below_and_to_the_right(): void
    {
        $this->type('C1', '=SORT(A1:A3)');

        $this->assertSame(['1', '2', '3'], [$this->shown('C1'), $this->shown('C2'), $this->shown('C3')]);
        $this->assertNull($this->cell('C2')->formula);
        $this->assertSame([3, 1], [(int) $this->cell('C1')->spill_rows, (int) $this->cell('C1')->spill_cols]);

        $this->type('E1', '=SEQUENCE(2,2)');
        $this->assertSame(['1', '2', '3', '4'], [$this->shown('E1'), $this->shown('F1'), $this->shown('E2'), $this->shown('F2')]);

        // On screen too, without a reload.
        $this->assertSame('3', $this->editor->get('cellData')[2][2]['computed_value']);
    }

    public function test_it_follows_its_inputs_and_changes_size(): void
    {
        $this->type('C1', '=FILTER(A1:A3,A1:A3>1)');
        $this->assertSame(['3', '2'], [$this->shown('C1'), $this->shown('C2')]);

        $this->type('A2', '9');
        $this->assertSame(['3', '9', '2'], [$this->shown('C1'), $this->shown('C2'), $this->shown('C3')]);

        $this->type('A2', '0');
        $this->type('A3', '0');
        $this->assertSame('3', $this->shown('C1'));
        $this->assertNull($this->cell('C2'));
        $this->assertNull($this->cell('C3'));
    }

    public function test_what_reads_a_spilled_cell_follows_it(): void
    {
        $this->type('D5', '=C3*10');           // before the answer lands there
        $this->type('C1', '=SORT(A1:A3)');
        $this->type('D6', '=SUM(C1:C3)');

        $this->assertSame('30', $this->shown('D5'));
        $this->assertSame('6', $this->shown('D6'));

        $this->type('A1', '30');
        $this->assertSame('300', $this->shown('D5'));
        $this->assertSame('33', $this->shown('D6'));
    }

    public function test_something_in_the_way_is_a_spill_error_until_it_moves(): void
    {
        $this->type('C2', 'in the way');
        $this->type('C1', '=SORT(A1:A3)');

        $this->assertSame('#SPILL!', $this->shown('C1'));
        $this->assertSame(FormulaEngine::EXPLANATIONS['#SPILL!'], $this->cell('C1')->formula_error);
        $this->assertSame('in the way', $this->cell('C2')->raw_value);

        // Clearing what is in the way lets the answer land.
        $this->editor->set('selectedRow', 1)->set('selectedCol', 2);
        $this->cell('C2')->delete();
        app(Recalculator::class)->cellsWritten($this->spreadsheet->id, $this->tab->id, [[1, 2]]);

        $this->assertSame(['1', '2', '3'], [$this->shown('C1'), $this->shown('C2'), $this->shown('C3')]);
    }

    public function test_typing_on_a_spilled_cell_keeps_what_was_typed_and_blocks_the_answer(): void
    {
        $this->type('C1', '=SORT(A1:A3)');
        $this->type('C2', 'mine');

        $this->assertSame('mine', $this->cell('C2')->raw_value);
        $this->assertNull($this->cell('C2')->spill_row);
        $this->assertSame('#SPILL!', $this->shown('C1'));
        $this->assertNull($this->cell('C3'));
    }

    public function test_replacing_the_formula_takes_its_answer_away(): void
    {
        $this->type('C1', '=SORT(A1:A3)');
        $this->type('C1', '5');

        $this->assertNull($this->cell('C2'));
        $this->assertNull($this->cell('C1')->spill_rows);
    }

    public function test_formatting_on_a_spilled_cell_survives_the_answer_going(): void
    {
        $this->type('C1', '=SORT(A1:A3)');
        $this->cell('C2')->update(['formatting' => ['bold' => true]]);
        $this->type('C1', '5');

        $this->assertSame(['bold' => true], $this->cell('C2')->formatting);
        $this->assertNull($this->cell('C2')->computed_value);
    }

    public function test_an_answer_that_would_leave_the_sheet_is_refused(): void
    {
        DB::table('cells')->insert(['spreadsheet_id' => $this->spreadsheet->id, 'sheet_tab_id' => $this->tab->id, 'row_index' => SheetStructureService::GRID_ROWS - 1, 'col_index' => 5, 'raw_value' => '', 'formula' => '=SORT(A1:A3)', 'updated_at' => now()]);

        app(Recalculator::class)->cellsWritten($this->spreadsheet->id, $this->tab->id, [[SheetStructureService::GRID_ROWS - 1, 5]]);

        $cell = Cell::where('row_index', SheetStructureService::GRID_ROWS - 1)->sole();
        $this->assertSame('#SPILL!', $cell->computed_value);
        $this->assertSame('The answer needs more cells than the sheet has.', $cell->formula_error);
    }

    public function test_copies_leave_spilled_cells_out_and_a_restore_brings_them_back(): void
    {
        $this->type('C1', '=SORT(A1:A3)');

        $version = app(SheetVersions::class)->snapshot($this->spreadsheet->id, $this->tab->id, $this->user->id, SheetVersions::MANUAL, 'Copy');
        $this->assertSame(4, $version->cell_count);   // A1:A3 and the formula

        $this->type('C1', '7');
        DB::transaction(fn () => app(SheetVersions::class)->restore($version->fresh(), $this->user->id));
        app(Recalculator::class)->ensureBuilt($this->spreadsheet->id, $this->tab->id);

        $this->assertSame(['1', '2', '3'], [$this->shown('C1'), $this->shown('C2'), $this->shown('C3')]);
    }

    public function test_inserting_a_row_lays_the_answer_out_again(): void
    {
        config(['sheet.structure_ops_enabled' => true]);
        $this->type('C1', '=SORT(A1:A3)');

        app(SheetStructureService::class)->insertRow($this->spreadsheet->id, $this->tab->id, 0);
        app(Recalculator::class)->ensureBuilt($this->spreadsheet->id, $this->tab->id);

        $this->assertSame('=SORT(A2:A4)', $this->cell('C2')->formula);
        $this->assertSame(['1', '2', '3'], [$this->shown('C2'), $this->shown('C3'), $this->shown('C4')]);
        $this->assertNull($this->cell('C1'));
        $this->assertNull($this->cell('C5'));
    }

    public function test_exports_carry_the_answer(): void
    {
        $this->type('C1', '=SORT(A1:A3)');

        $csv = app(SpreadsheetImportExportService::class)->exportCsv($this->spreadsheet, $this->tab);

        $this->assertSame("3,,1\n1,,2\n2,,3\n", $csv);
    }
}
