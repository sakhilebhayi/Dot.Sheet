<?php

namespace Tests\Feature;

use App\Models\Cell;
use App\Models\NamedRange;
use App\Models\SheetTab;
use App\Models\Spreadsheet;
use App\Models\User;
use App\Services\FormulaEvaluatorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NamedRangesTest extends TestCase
{
    use RefreshDatabase;

    private function ownedSpreadsheet(User $user): Spreadsheet
    {
        return Spreadsheet::create([
            'uuid' => (string) str()->uuid(),
            'owner_id' => $user->id,
            'team_id' => null,
            'name' => 'Named Ranges Workbook',
        ]);
    }

    public function test_a_single_cell_named_range_resolves_in_a_formula(): void
    {
        $user = User::factory()->create();
        $spreadsheet = $this->ownedSpreadsheet($user);
        $tab = $spreadsheet->defaultSheetTab();

        NamedRange::create([
            'spreadsheet_id' => $spreadsheet->id,
            'sheet_tab_id' => $tab->id,
            'name' => 'TaxRate',
            'start_row' => 0, 'start_col' => 0, 'end_row' => 0, 'end_col' => 0,
        ]);

        Cell::create(['spreadsheet_id' => $spreadsheet->id, 'sheet_tab_id' => $tab->id, 'row_index' => 0, 'col_index' => 0, 'raw_value' => '15', 'computed_value' => '15', 'updated_at' => now()]);

        $result = (new FormulaEvaluatorService)->evaluate('=TaxRate', $spreadsheet->id, $tab->id);

        $this->assertFalse($result['error']);
        $this->assertSame(15.0, (float) $result['value']);
    }

    public function test_a_multi_cell_named_range_sums_correctly(): void
    {
        $user = User::factory()->create();
        $spreadsheet = $this->ownedSpreadsheet($user);
        $tab = $spreadsheet->defaultSheetTab();

        // Revenue = A1:A3
        NamedRange::create([
            'spreadsheet_id' => $spreadsheet->id,
            'sheet_tab_id' => $tab->id,
            'name' => 'Revenue',
            'start_row' => 0, 'start_col' => 0, 'end_row' => 2, 'end_col' => 0,
        ]);

        foreach ([['row' => 0, 'val' => '10'], ['row' => 1, 'val' => '20'], ['row' => 2, 'val' => '30']] as $c) {
            Cell::create(['spreadsheet_id' => $spreadsheet->id, 'sheet_tab_id' => $tab->id, 'row_index' => $c['row'], 'col_index' => 0, 'raw_value' => $c['val'], 'computed_value' => $c['val'], 'updated_at' => now()]);
        }

        $result = (new FormulaEvaluatorService)->evaluate('=SUM(Revenue)', $spreadsheet->id, $tab->id);

        $this->assertFalse($result['error']);
        $this->assertSame(60.0, (float) $result['value']);
    }

    public function test_a_raw_range_reference_also_sums_correctly_now(): void
    {
        // Regression coverage: SUM(A1:A3) previously evaluated to 0
        // because ranges were never expanded inside function args, only
        // used for dependency-graph tracking.
        $user = User::factory()->create();
        $spreadsheet = $this->ownedSpreadsheet($user);
        $tab = $spreadsheet->defaultSheetTab();

        foreach ([['row' => 0, 'val' => '1'], ['row' => 1, 'val' => '2'], ['row' => 2, 'val' => '3']] as $c) {
            Cell::create(['spreadsheet_id' => $spreadsheet->id, 'sheet_tab_id' => $tab->id, 'row_index' => $c['row'], 'col_index' => 0, 'raw_value' => $c['val'], 'computed_value' => $c['val'], 'updated_at' => now()]);
        }

        $result = (new FormulaEvaluatorService)->evaluate('=SUM(A1:A3)', $spreadsheet->id, $tab->id);

        $this->assertFalse($result['error']);
        $this->assertSame(6.0, (float) $result['value']);
    }

    public function test_named_ranges_do_not_leak_across_tabs(): void
    {
        $user = User::factory()->create();
        $spreadsheet = $this->ownedSpreadsheet($user);
        $tabOne = $spreadsheet->defaultSheetTab();
        $tabTwo = SheetTab::create(['spreadsheet_id' => $spreadsheet->id, 'name' => 'Sheet2', 'sort_order' => 1]);

        NamedRange::create([
            'spreadsheet_id' => $spreadsheet->id,
            'sheet_tab_id' => $tabOne->id,
            'name' => 'Revenue',
            'start_row' => 0, 'start_col' => 0, 'end_row' => 0, 'end_col' => 0,
        ]);

        // A formula on tab two referencing "Revenue" shouldn't resolve --
        // the name only exists on tab one.
        $result = (new FormulaEvaluatorService)->evaluate('=Revenue', $spreadsheet->id, $tabTwo->id);

        // Unresolved "Revenue" falls through evaluateExpression's default
        // branch (not a number, not a cell ref, not a recognised
        // function) and returns 0 rather than erroring.
        $this->assertFalse($result['error']);
        $this->assertSame(0.0, (float) $result['value']);
    }
}
