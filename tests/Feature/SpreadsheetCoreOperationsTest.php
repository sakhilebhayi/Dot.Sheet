<?php

namespace Tests\Feature;

use App\Models\Cell;
use App\Models\Spreadsheet;
use App\Models\User;
use App\Services\FormulaEvaluatorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SpreadsheetCoreOperationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_core_spreadsheet_create_and_update_operations(): void
    {
        $user = User::factory()->create();

        $spreadsheet = Spreadsheet::create([
            'uuid' => (string) str()->uuid(),
            'owner_id' => $user->id,
            'team_id' => null,
            'name' => 'Q1 Model',
        ]);

        $this->assertSame('Q1 Model', $spreadsheet->name);

        $spreadsheet->update([
            'name' => 'Q1 Model Revised',
            'settings' => [
                'theme' => 'compact',
            ],
        ]);

        $spreadsheet->refresh();
        $this->assertSame('Q1 Model Revised', $spreadsheet->name);
        $this->assertSame('compact', $spreadsheet->settings['theme'] ?? null);
    }

    public function test_formula_evaluation_persists_computed_value_for_spreadsheet_cells(): void
    {
        $user = User::factory()->create();

        $spreadsheet = Spreadsheet::create([
            'uuid' => (string) str()->uuid(),
            'owner_id' => $user->id,
            'team_id' => null,
            'name' => 'Formula Sheet',
            'settings' => [],
        ]);

        Cell::create([
            'spreadsheet_id' => $spreadsheet->id,
            'row_index' => 0,
            'col_index' => 0,
            'raw_value' => '2',
            'computed_value' => '2',
            'formula' => null,
            'updated_at' => now(),
        ]);

        Cell::create([
            'spreadsheet_id' => $spreadsheet->id,
            'row_index' => 0,
            'col_index' => 1,
            'raw_value' => '3',
            'computed_value' => '3',
            'formula' => null,
            'updated_at' => now(),
        ]);

        $service = new FormulaEvaluatorService;
        $evaluation = $service->evaluate('=SUM(A1,B1)', $spreadsheet->id);

        $this->assertFalse($evaluation['error']);

        Cell::updateOrCreate(
            [
                'spreadsheet_id' => $spreadsheet->id,
                'row_index' => 1,
                'col_index' => 0,
            ],
            [
                'raw_value' => '',
                'computed_value' => (string) $evaluation['value'],
                'formula' => '=SUM(A1,B1)',
                'updated_at' => now(),
            ]
        );

        $formulaCell = Cell::where('spreadsheet_id', $spreadsheet->id)
            ->where('row_index', 1)
            ->where('col_index', 0)
            ->first();

        $this->assertNotNull($formulaCell);
        $this->assertSame('=SUM(A1,B1)', $formulaCell->formula);
        $this->assertSame(5.0, (float) $formulaCell->computed_value);
    }
}
