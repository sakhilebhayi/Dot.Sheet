<?php

namespace Tests\Feature;

use App\Livewire\ChartBuilder;
use App\Models\Cell;
use App\Models\Spreadsheet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Regression coverage: ChartBuilder::parseRef used to be 1-based ("A1" ->
 * [col=1, row=1]) while Cell.row_index/col_index are stored 0-based
 * everywhere else in the app -- every chart's data range silently queried
 * one row/column past what the user actually selected, and would miss the
 * sheet's actual first row/column entirely.
 */
class ChartBuilderRangeParsingTest extends TestCase
{
    use RefreshDatabase;

    public function test_a1_reference_parses_to_the_actual_zero_based_first_cell(): void
    {
        $method = new ReflectionMethod(ChartBuilder::class, 'parseRef');
        $method->setAccessible(true);

        $this->assertSame([0, 0], $method->invoke(new ChartBuilder, 'A1'));
        $this->assertSame([1, 2], $method->invoke(new ChartBuilder, 'B3'));
    }

    public function test_a_chart_over_a1_a2_includes_the_actual_first_row(): void
    {
        $user = User::factory()->create();
        $spreadsheet = Spreadsheet::create([
            'uuid' => (string) str()->uuid(),
            'owner_id' => $user->id,
            'team_id' => null,
            'name' => 'Chart Range Workbook',
        ]);
        $tab = $spreadsheet->defaultSheetTab();

        // A1 = the very first cell -- row_index 0, col_index 0.
        Cell::create(['spreadsheet_id' => $spreadsheet->id, 'sheet_tab_id' => $tab->id, 'row_index' => 0, 'col_index' => 0, 'raw_value' => '5', 'computed_value' => '5', 'updated_at' => now()]);
        Cell::create(['spreadsheet_id' => $spreadsheet->id, 'sheet_tab_id' => $tab->id, 'row_index' => 1, 'col_index' => 0, 'raw_value' => '7', 'computed_value' => '7', 'updated_at' => now()]);

        $chartBuilder = new ChartBuilder;
        $chartBuilder->spreadsheet_id = $spreadsheet->id;
        $chartBuilder->sheet_tab_id = $tab->id;

        $method = new ReflectionMethod(ChartBuilder::class, 'getChartData');
        $method->setAccessible(true);
        $data = $method->invoke($chartBuilder, 'A1:A2', null);

        $this->assertSame([5.0, 7.0], $data['datasets'][0]['data']);
    }
}
