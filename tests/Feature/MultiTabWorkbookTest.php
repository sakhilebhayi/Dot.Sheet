<?php

namespace Tests\Feature;

use App\Models\Cell;
use App\Models\SheetTab;
use App\Models\Spreadsheet;
use App\Models\User;
use App\Services\FormulaEvaluatorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MultiTabWorkbookTest extends TestCase
{
    use RefreshDatabase;

    private function ownedSpreadsheet(User $user, string $name = 'Workbook'): Spreadsheet
    {
        return Spreadsheet::create([
            'uuid' => (string) str()->uuid(),
            'owner_id' => $user->id,
            'team_id' => null,
            'name' => $name,
        ]);
    }

    public function test_a_new_spreadsheet_gets_one_default_tab(): void
    {
        $user = User::factory()->create();
        $spreadsheet = $this->ownedSpreadsheet($user);

        $this->assertSame(1, SheetTab::where('spreadsheet_id', $spreadsheet->id)->count());
        $this->assertSame('Sheet1', $spreadsheet->defaultSheetTab()->name);
    }

    public function test_formula_evaluation_only_sees_cells_on_the_same_tab(): void
    {
        $user = User::factory()->create();
        $spreadsheet = $this->ownedSpreadsheet($user);
        $tabOne = $spreadsheet->defaultSheetTab();
        $tabTwo = SheetTab::create(['spreadsheet_id' => $spreadsheet->id, 'name' => 'Sheet2', 'sort_order' => 1]);

        // A1 on tab two holds 999 -- must not leak into tab one's SUM.
        Cell::create(['spreadsheet_id' => $spreadsheet->id, 'sheet_tab_id' => $tabTwo->id, 'row_index' => 0, 'col_index' => 0, 'raw_value' => '999', 'computed_value' => '999', 'updated_at' => now()]);
        Cell::create(['spreadsheet_id' => $spreadsheet->id, 'sheet_tab_id' => $tabOne->id, 'row_index' => 0, 'col_index' => 0, 'raw_value' => '2', 'computed_value' => '2', 'updated_at' => now()]);
        Cell::create(['spreadsheet_id' => $spreadsheet->id, 'sheet_tab_id' => $tabOne->id, 'row_index' => 0, 'col_index' => 1, 'raw_value' => '3', 'computed_value' => '3', 'updated_at' => now()]);

        $service = new FormulaEvaluatorService;
        $result = $service->evaluate('=SUM(A1,B1)', $spreadsheet->id, $tabOne->id);

        $this->assertFalse($result['error']);
        $this->assertSame(5.0, (float) $result['value']);
    }
}
