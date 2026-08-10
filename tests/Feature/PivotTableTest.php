<?php

namespace Tests\Feature;

use App\Livewire\PivotTableBuilder;
use App\Models\Cell;
use App\Models\PivotTable;
use App\Models\SheetTab;
use App\Models\Spreadsheet;
use App\Models\User;
use App\Services\PivotTableService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PivotTableTest extends TestCase
{
    use RefreshDatabase;

    private function ownedSpreadsheet(User $user): Spreadsheet
    {
        return Spreadsheet::create([
            'uuid' => (string) str()->uuid(),
            'owner_id' => $user->id,
            'team_id' => null,
            'name' => 'Pivot Workbook',
        ]);
    }

    /**
     * Builds this table (A1:C7):
     *
     *   Region  Product  Amount
     *   West    Widget   10
     *   West    Gadget   20
     *   East    Widget   30
     *   East    Widget   5
     *   East    Gadget   15
     *   West    Widget   1
     */
    private function seedSourceData(Spreadsheet $spreadsheet, SheetTab $tab): void
    {
        $headers = ['Region', 'Product', 'Amount'];
        $rows = [
            ['West', 'Widget', '10'],
            ['West', 'Gadget', '20'],
            ['East', 'Widget', '30'],
            ['East', 'Widget', '5'],
            ['East', 'Gadget', '15'],
            ['West', 'Widget', '1'],
        ];

        foreach ($headers as $col => $value) {
            Cell::create(['spreadsheet_id' => $spreadsheet->id, 'sheet_tab_id' => $tab->id, 'row_index' => 0, 'col_index' => $col, 'raw_value' => $value, 'computed_value' => $value, 'updated_at' => now()]);
        }

        foreach ($rows as $rowIndex => $row) {
            foreach ($row as $col => $value) {
                Cell::create(['spreadsheet_id' => $spreadsheet->id, 'sheet_tab_id' => $tab->id, 'row_index' => $rowIndex + 1, 'col_index' => $col, 'raw_value' => $value, 'computed_value' => $value, 'updated_at' => now()]);
            }
        }
    }

    public function test_it_sums_values_grouped_by_row_field_only(): void
    {
        $user = User::factory()->create();
        $spreadsheet = $this->ownedSpreadsheet($user);
        $tab = $spreadsheet->defaultSheetTab();
        $this->seedSourceData($spreadsheet, $tab);

        $pivot = PivotTable::create([
            'spreadsheet_id' => $spreadsheet->id,
            'sheet_tab_id' => $tab->id,
            'title' => 'Amount by Region',
            'source_range' => 'A1:C7',
            'row_field' => 'A',
            'column_field' => null,
            'value_field' => 'C',
            'aggregation' => 'sum',
        ]);

        $result = app(PivotTableService::class)->compute($pivot);

        $this->assertSame(['East', 'West'], $result['rowLabels']);
        $this->assertSame(['Value'], $result['columnLabels']);
        $this->assertSame(50.0, $result['matrix']['East']['Value']); // 30+5+15
        $this->assertSame(31.0, $result['matrix']['West']['Value']); // 10+20+1
        $this->assertSame(81.0, $result['grandTotal']);
    }

    public function test_it_cross_tabulates_row_and_column_fields(): void
    {
        $user = User::factory()->create();
        $spreadsheet = $this->ownedSpreadsheet($user);
        $tab = $spreadsheet->defaultSheetTab();
        $this->seedSourceData($spreadsheet, $tab);

        $pivot = PivotTable::create([
            'spreadsheet_id' => $spreadsheet->id,
            'sheet_tab_id' => $tab->id,
            'title' => 'Amount by Region x Product',
            'source_range' => 'A1:C7',
            'row_field' => 'A',
            'column_field' => 'B',
            'value_field' => 'C',
            'aggregation' => 'sum',
        ]);

        $result = app(PivotTableService::class)->compute($pivot);

        $this->assertSame(['East', 'West'], $result['rowLabels']);
        $this->assertSame(['Gadget', 'Widget'], $result['columnLabels']);
        $this->assertSame(35.0, $result['matrix']['East']['Widget']); // 30+5
        $this->assertSame(15.0, $result['matrix']['East']['Gadget']);
        $this->assertSame(11.0, $result['matrix']['West']['Widget']); // 10+1
        $this->assertSame(20.0, $result['matrix']['West']['Gadget']);
        $this->assertSame(50.0, $result['rowTotals']['East']);
        $this->assertSame(31.0, $result['rowTotals']['West']);
        $this->assertSame(81.0, $result['grandTotal']);
    }

    public function test_count_aggregation_counts_populated_cells_not_their_sum(): void
    {
        $user = User::factory()->create();
        $spreadsheet = $this->ownedSpreadsheet($user);
        $tab = $spreadsheet->defaultSheetTab();
        $this->seedSourceData($spreadsheet, $tab);

        $pivot = PivotTable::create([
            'spreadsheet_id' => $spreadsheet->id,
            'sheet_tab_id' => $tab->id,
            'title' => 'Orders by Region',
            'source_range' => 'A1:C7',
            'row_field' => 'A',
            'column_field' => null,
            'value_field' => 'C',
            'aggregation' => 'count',
        ]);

        $result = app(PivotTableService::class)->compute($pivot);

        $this->assertSame(3.0, $result['matrix']['East']['Value']);
        $this->assertSame(3.0, $result['matrix']['West']['Value']);
    }

    public function test_average_min_max_aggregations(): void
    {
        $user = User::factory()->create();
        $spreadsheet = $this->ownedSpreadsheet($user);
        $tab = $spreadsheet->defaultSheetTab();
        $this->seedSourceData($spreadsheet, $tab);

        $base = [
            'spreadsheet_id' => $spreadsheet->id,
            'sheet_tab_id' => $tab->id,
            'source_range' => 'A1:C7',
            'row_field' => 'A',
            'column_field' => null,
            'value_field' => 'C',
        ];

        $avg = app(PivotTableService::class)->compute(PivotTable::create([...$base, 'title' => 'avg', 'aggregation' => 'average']));
        $this->assertEqualsWithDelta(50.0 / 3, $avg['matrix']['East']['Value'], 0.0001);

        $min = app(PivotTableService::class)->compute(PivotTable::create([...$base, 'title' => 'min', 'aggregation' => 'min']));
        $this->assertSame(5.0, $min['matrix']['East']['Value']);

        $max = app(PivotTableService::class)->compute(PivotTable::create([...$base, 'title' => 'max', 'aggregation' => 'max']));
        $this->assertSame(30.0, $max['matrix']['East']['Value']);
    }

    public function test_pivot_tables_are_scoped_per_tab(): void
    {
        $user = User::factory()->create();
        $spreadsheet = $this->ownedSpreadsheet($user);
        $tabOne = $spreadsheet->defaultSheetTab();
        $tabTwo = SheetTab::create(['spreadsheet_id' => $spreadsheet->id, 'name' => 'Sheet2', 'sort_order' => 1]);
        $this->seedSourceData($spreadsheet, $tabOne);
        // tab two has no data at all.

        $pivot = PivotTable::create([
            'spreadsheet_id' => $spreadsheet->id,
            'sheet_tab_id' => $tabTwo->id,
            'title' => 'Empty',
            'source_range' => 'A1:C7',
            'row_field' => 'A',
            'column_field' => null,
            'value_field' => 'C',
            'aggregation' => 'sum',
        ]);

        $result = app(PivotTableService::class)->compute($pivot);

        $this->assertEmpty($result['rowLabels']);
        $this->assertSame(0.0, $result['grandTotal']);
    }

    public function test_owner_can_create_and_open_a_pivot_table_via_the_panel(): void
    {
        $user = User::factory()->create();
        $spreadsheet = $this->ownedSpreadsheet($user);
        $tab = $spreadsheet->defaultSheetTab();
        $this->seedSourceData($spreadsheet, $tab);

        $component = Livewire::actingAs($user)->test(PivotTableBuilder::class, [
            'spreadsheet_id' => $spreadsheet->id,
            'sheet_tab_id' => $tab->id,
        ])
            ->set('title', 'Amount by Region')
            ->set('source_range', 'A1:C7')
            ->set('row_field', 'A')
            ->set('value_field', 'C')
            ->set('aggregation', 'sum')
            ->call('savePivot');

        $this->assertSame(1, PivotTable::where('spreadsheet_id', $spreadsheet->id)->count());
        $component->assertSet('openPivotId', fn ($id) => $id !== null);
        $result = $component->get('result');
        $this->assertSame(81.0, $result['grandTotal']);
    }

    public function test_owner_can_delete_a_pivot_table(): void
    {
        $user = User::factory()->create();
        $spreadsheet = $this->ownedSpreadsheet($user);
        $tab = $spreadsheet->defaultSheetTab();

        $pivot = PivotTable::create([
            'spreadsheet_id' => $spreadsheet->id,
            'sheet_tab_id' => $tab->id,
            'title' => 'Doomed',
            'source_range' => 'A1:C7',
            'row_field' => 'A',
            'value_field' => 'C',
            'aggregation' => 'sum',
        ]);

        Livewire::actingAs($user)->test(PivotTableBuilder::class, [
            'spreadsheet_id' => $spreadsheet->id,
            'sheet_tab_id' => $tab->id,
        ])->call('deletePivot', $pivot->id);

        $this->assertSame(0, PivotTable::where('spreadsheet_id', $spreadsheet->id)->count());
    }
}
