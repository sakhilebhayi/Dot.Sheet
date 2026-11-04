<?php

namespace Tests\Feature;

use App\Livewire\ChartBuilder;
use App\Models\Cell;
use App\Models\ChartConfig;
use App\Models\SheetTab;
use App\Models\Spreadsheet;
use App\Models\SpreadsheetVersion;
use App\Models\User;
use App\Services\CellWriter;
use App\Services\Formula\Recalculator;
use App\Services\SheetCharts;
use App\Services\SheetVersions;
use App\Services\StructureVersion;
use App\Services\WorkbookSheets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\TestCase;

class GridChartsTest extends TestCase
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
        $this->sheet = Spreadsheet::create(['uuid' => (string) str()->uuid(), 'owner_id' => $this->owner->id, 'team_id' => $this->owner->currentTeam->id, 'name' => 'Charted']);
        $this->tab = $this->sheet->sheetTabs()->firstOrFail();
        $this->actingAs($this->owner);
    }

    private function url(string $path = 'charts', ?SheetTab $tab = null): string
    {
        return "/spreadsheets/{$this->sheet->id}/tabs/".($tab ?? $this->tab)->id."/{$path}";
    }

    /**
     * @param  list<list<string>>  $rows  from A1
     */
    private function table(array $rows, ?SheetTab $tab = null, int $firstRow = 0): void
    {
        $edits = [];
        foreach ($rows as $r => $row) {
            foreach ($row as $c => $value) {
                if ($value !== '') {
                    $edits[] = ['row' => $firstRow + $r, 'col' => $c, 'value' => $value];
                }
            }
        }
        app(CellWriter::class)->write($this->sheet->id, ($tab ?? $this->tab)->id, $edits, $this->owner->id);
    }

    private function chart(string $range = 'A1:B3', ?string $labels = null, string $type = 'bar', ?SheetTab $tab = null): ChartConfig
    {
        return app(SheetCharts::class)->add($this->sheet->id, ($tab ?? $this->tab)->id, 'Sales', $type, $range, $labels);
    }

    /**
     * @return array<string, mixed>
     */
    private function read(string $range, ?string $labels = null): array
    {
        return app(SheetCharts::class)->read($this->tab->id, $range, $labels);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function change(array $body): TestResponse
    {
        return $this->postJson($this->url('structure'), ['version' => StructureVersion::current($this->sheet->id), ...$body]);
    }

    public function test_a_chart_is_added_with_its_labels_and_they_survive_saving(): void
    {
        $answer = $this->postJson($this->url(), ['title' => 'Sales', 'type' => 'line', 'data_range' => 'b2:$C$4', 'labels_range' => 'a2:a4'])->assertOk()->json();

        $this->assertSame('B2:C4', $answer['charts'][0]['data_range']);
        $this->assertSame('A2:A4', $answer['charts'][0]['labels_range']);
        $this->assertSame($answer['added'], $answer['charts'][0]['id']);
        $this->assertSame('A2:A4', ChartConfig::findOrFail($answer['added'])->labels_range);
    }

    public function test_a_chart_reads_the_sheet_as_it_is_now(): void
    {
        $this->table([['North', '10'], ['South', '20']]);
        $chart = $this->chart('A1:B2');

        $this->getJson($this->url("charts/{$chart->id}"))->assertOk()
            ->assertJsonPath('title', 'Sales')
            ->assertJsonPath('labels', ['North', 'South'])
            ->assertJsonPath('series.0.values', [10, 20]);

        $this->table([['', '99']]);
        $this->getJson($this->url("charts/{$chart->id}"))->assertJsonPath('series.0.values', [99, 20]);
    }

    public function test_a_formula_gives_its_answer(): void
    {
        $this->table([['North', '10'], ['South', '=B1*2']]);

        $this->getJson($this->url('charts/'.$this->chart('A1:B2')->id))->assertOk()->assertJsonPath('series.0.values', [10, 20]);
    }

    public function test_a_stale_sheet_is_worked_out_before_it_is_charted(): void
    {
        $this->table([['North', '10'], ['South', '=B1*2']]);
        $chart = $this->chart('A1:B2');

        // What an import leaves: new values, and formulas waiting to be worked out.
        Cell::where('sheet_tab_id', $this->tab->id)->where('row_index', 0)->where('col_index', 1)->update(['raw_value' => '50', 'computed_value' => '50']);
        app(Recalculator::class)->markStale($this->sheet->id, $this->tab->id);

        $this->getJson($this->url("charts/{$chart->id}"))->assertOk()->assertJsonPath('series.0.values', [50, 100]);
    }

    public function test_an_empty_or_text_cell_is_a_gap_and_never_a_nought(): void
    {
        $this->table([['1'], [''], ['n/a'], ['4']]);

        $this->assertSame([1.0, null, null, 4.0], $this->read('A1:A4')['series'][0]['values']);
    }

    public function test_a_table_with_headings_and_labels_charts_from_a_plain_selection(): void
    {
        $this->table([['Month', 'Sales', 'Costs'], ['Jan', '10', '4'], ['Feb', '20', '5']]);

        $read = $this->read('A1:C3');

        $this->assertSame(['Jan', 'Feb'], $read['labels']);
        $this->assertSame(['Sales', 'Costs'], array_column($read['series'], 'name'));
        $this->assertSame([10.0, 20.0], $read['series'][0]['values']);
        $this->assertSame([4.0, 5.0], $read['series'][1]['values']);
    }

    public function test_figures_with_no_heading_are_named_by_their_column_and_labelled_by_their_row(): void
    {
        $this->table([['', '10'], ['', '20']]);

        $read = $this->read('B1:B2');

        $this->assertSame(['Row 1', 'Row 2'], $read['labels']);
        $this->assertSame('B', $read['series'][0]['name']);
    }

    public function test_a_column_of_numbers_is_a_series_not_labels(): void
    {
        $this->table([['2024', '10'], ['2025', '20']]);

        $this->assertCount(2, $this->read('A1:B2')['series']);
    }

    public function test_a_single_row_runs_across_and_takes_its_labels_from_the_top_of_the_sheet(): void
    {
        $this->table([['', 'Jan', 'Feb', 'Mar'], ['Sales', '10', '20', '30'], ['Costs', '4', '5', '6']]);

        $read = $this->read('B3:D3');

        $this->assertCount(1, $read['series']);
        $this->assertSame([4.0, 5.0, 6.0], $read['series'][0]['values']);
        $this->assertSame(['Jan', 'Feb', 'Mar'], $read['labels']);
    }

    public function test_a_row_with_nothing_at_the_top_of_the_sheet_is_labelled_by_its_columns(): void
    {
        $this->table([['1', '2', '3']]);

        $this->assertSame(['A', 'B', 'C'], $this->read('A1:C1')['labels']);
    }

    public function test_a_wide_table_makes_its_rows_the_series(): void
    {
        $this->table([['Item', 'Jan', 'Feb', 'Mar'], ['Sales', '10', '20', '30'], ['Costs', '4', '5', '6']]);

        $read = $this->read('A1:D3');

        $this->assertSame(['Sales', 'Costs'], array_column($read['series'], 'name'));
        $this->assertSame(['Jan', 'Feb', 'Mar'], $read['labels']);
        $this->assertSame([10.0, 20.0, 30.0], $read['series'][0]['values']);
    }

    public function test_a_row_with_its_name_in_the_selection_keeps_it(): void
    {
        $this->table([['Sales', '10', '20', '30']]);

        $read = $this->read('A1:D1');

        $this->assertSame('Sales', $read['series'][0]['name']);
        $this->assertSame([10.0, 20.0, 30.0], $read['series'][0]['values']);
    }

    public function test_more_rows_than_a_wide_chart_can_show_are_counted(): void
    {
        $rows = [];
        for ($r = 0; $r < 8; $r++) {
            $rows[] = array_fill(0, 12, '1');
        }
        $this->table($rows);

        $read = $this->read('A1:L8');

        $this->assertCount(SheetCharts::MAX_SERIES, $read['series']);
        $this->assertSame(2, $read['more_series']);
    }

    public function test_labels_stay_with_their_rows_when_one_is_blank(): void
    {
        $this->table([['Jan', '1'], ['', '2'], ['Mar', '3']]);

        $this->assertSame(['Jan', '', 'Mar'], $this->read('B1:B3', 'A1:A3')['labels']);
    }

    public function test_a_heading_row_moves_labels_given_apart_along_with_it(): void
    {
        $this->table([['Month', 'Sales'], ['Jan', '1'], ['Feb', '2']]);

        $read = $this->read('B1:B3', 'A1:A3');

        $this->assertSame(['Jan', 'Feb'], $read['labels']);
        $this->assertSame('Sales', $read['series'][0]['name']);
    }

    public function test_a_whole_column_stops_where_the_data_stops(): void
    {
        $this->table([['1'], ['2']]);

        $read = $this->read('A1:A1000000');

        $this->assertSame([1.0, 2.0], $read['series'][0]['values']);
        $this->assertSame(0, $read['more_points']);
    }

    public function test_more_rows_or_columns_than_fit_are_counted_not_lost_quietly(): void
    {
        $rows = [];
        for ($r = 0; $r < SheetCharts::MAX_POINTS + 3; $r++) {
            $rows[] = [(string) $r];
        }
        $this->table($rows);
        $read = $this->read('A1:A'.count($rows));
        $this->assertCount(SheetCharts::MAX_POINTS, $read['series'][0]['values']);
        $this->assertSame(3, $read['more_points']);

        $this->table([['1', '2', '3', '4', '5', '6', '7', '8'], ['1', '2', '3', '4', '5', '6', '7', '8']], null, 600);
        $tall = $this->read('A601:H602', 'A601:A602');
        $this->assertCount(SheetCharts::MAX_SERIES, $tall['series'], 'labels set apart: it runs down, column by column');
        $this->assertSame(2, $tall['more_series']);
    }

    public function test_a_range_that_makes_no_sense_is_refused_in_words(): void
    {
        $post = fn (array $body) => $this->postJson($this->url(), ['title' => 'x', 'type' => 'bar', 'data_range' => 'A1:B2', 'labels_range' => null, ...$body]);

        $post(['data_range' => 'A0:B2'])->assertUnprocessable()->assertJsonPath('message', 'Figures must look like B2:D8.');
        $post(['data_range' => 'A1'])->assertUnprocessable()->assertJsonPath('message', 'Choose more than one cell to chart.');
        $post(['labels_range' => 'nonsense'])->assertUnprocessable()->assertJsonPath('message', 'Labels must look like A2:A8.');
        $post(['labels_range' => 'A1:B2'])->assertUnprocessable()->assertJsonPath('message', 'Labels come from one column.');
        $post(['title' => ''])->assertUnprocessable()->assertJsonPath('message', 'Give the chart a title.');
        $post(['type' => 'radar'])->assertUnprocessable();
        $this->assertSame(0, ChartConfig::count());
    }

    public function test_ranges_follow_rows_and_columns(): void
    {
        $this->table([['Jan', '1'], ['Feb', '2'], ['Mar', '3']]);
        $chart = $this->chart('B1:B3', 'A1:A3');

        $this->change(['action' => 'insert', 'axis' => 'row', 'at' => 0, 'count' => 1])->assertOk();
        $this->change(['action' => 'insert', 'axis' => 'col', 'at' => 1, 'count' => 1])->assertOk();

        $chart->refresh();
        $this->assertSame('C2:C4', $chart->data_range);
        $this->assertSame('A2:A4', $chart->labels_range);
        $this->getJson($this->url("charts/{$chart->id}"))->assertJsonPath('series.0.values', [1, 2, 3])->assertJsonPath('labels', ['Jan', 'Feb', 'Mar']);
    }

    public function test_a_row_going_inside_the_range_shrinks_it(): void
    {
        $this->table([['1'], ['2'], ['3']]);
        $chart = $this->chart('A1:A3');

        $this->change(['action' => 'delete', 'axis' => 'row', 'at' => 1, 'count' => 1])->assertOk();

        $this->assertSame('A1:A2', $chart->fresh()->data_range);
    }

    public function test_a_chart_whose_cells_all_went_says_so(): void
    {
        $this->table([['1', 'x'], ['2', 'y']]);
        $chart = $this->chart('A1:A2');

        $this->change(['action' => 'delete', 'axis' => 'col', 'at' => 0, 'count' => 1])->assertOk();

        $this->assertStringContainsString('#REF!', $chart->fresh()->data_range);
        $this->getJson($this->url("charts/{$chart->id}"))->assertOk()->assertJsonPath('gone', true)->assertJsonPath('series', []);
    }

    public function test_putting_a_copy_back_puts_the_chart_back_on_its_cells(): void
    {
        $this->table([['1'], ['2'], ['3']]);
        $chart = $this->chart('A2:A3');

        $copy = $this->change(['action' => 'delete', 'axis' => 'row', 'at' => 0, 'count' => 1])->assertOk()->json('undo.restore');
        $this->assertSame('A1:A2', $chart->fresh()->data_range);

        $this->postJson($this->url('restore'), ['version' => StructureVersion::current($this->sheet->id), 'copy' => $copy])->assertOk();

        $this->assertSame('A2:A3', $chart->fresh()->data_range);
    }

    public function test_a_copy_from_before_charts_were_kept_leaves_them_alone(): void
    {
        $this->table([['1'], ['2']]);
        $chart = $this->chart('A1:A2');
        $copy = app(SheetVersions::class)->snapshot($this->sheet->id, $this->tab->id, $this->owner->id, SheetVersions::MANUAL, 'Old');
        SpreadsheetVersion::whereKey($copy->id)->update(['chart_ranges' => null]);
        $chart->update(['data_range' => 'A1:A9']);

        app(SheetVersions::class)->restore($copy->fresh(), $this->owner->id);

        $this->assertSame('A1:A9', $chart->fresh()->data_range);
    }

    public function test_other_sheets_keep_their_charts_when_lines_move(): void
    {
        $second = app(WorkbookSheets::class)->add($this->sheet, 'Second');
        $this->table([['1'], ['2']]);
        $elsewhere = $this->chart('A1:A2', null, 'bar', $second);

        $this->change(['action' => 'insert', 'axis' => 'row', 'at' => 0, 'count' => 1])->assertOk();

        $this->assertSame('A1:A2', $elsewhere->fresh()->data_range);
    }

    public function test_charts_are_listed_for_their_own_sheet_and_reached_only_through_it(): void
    {
        $second = app(WorkbookSheets::class)->add($this->sheet, 'Second');
        $here = $this->chart('A1:A2');
        $elsewhere = $this->chart('A1:A2', null, 'bar', $second);

        $this->getJson($this->url())->assertOk()->assertJsonCount(1, 'charts')->assertJsonPath('charts.0.id', $here->id);
        $this->getJson($this->url("charts/{$elsewhere->id}"))->assertNotFound();
        $this->deleteJson($this->url("charts/{$elsewhere->id}"))->assertNotFound();
        $this->assertNotNull($elsewhere->fresh());
    }

    public function test_a_chart_can_be_deleted_and_the_figures_stay(): void
    {
        $this->table([['1'], ['2']]);
        $chart = $this->chart('A1:A2');

        $this->deleteJson($this->url("charts/{$chart->id}"))->assertOk()->assertJsonPath('charts', []);

        $this->assertNull($chart->fresh());
        $this->assertSame([1.0, 2.0], $this->read('A1:A2')['series'][0]['values']);
    }

    public function test_someone_who_can_read_sees_charts_and_changes_nothing(): void
    {
        $this->table([['1'], ['2']]);
        $chart = $this->chart('A1:A2');
        $reader = User::factory()->withPersonalTeam()->create();
        $this->sheet->sharedUsers()->attach($reader->id, ['permission' => 'view']);
        $this->actingAs($reader);

        $this->getJson($this->url())->assertOk()->assertJsonCount(1, 'charts');
        $this->getJson($this->url("charts/{$chart->id}"))->assertOk();
        $this->postJson($this->url(), ['title' => 'Mine', 'type' => 'bar', 'data_range' => 'A1:A2', 'labels_range' => null])->assertForbidden();
        $this->deleteJson($this->url("charts/{$chart->id}"))->assertForbidden();
        $this->assertNotNull($chart->fresh());
    }

    public function test_a_stranger_sees_nothing(): void
    {
        $chart = $this->chart('A1:A2');
        $this->actingAs(User::factory()->withPersonalTeam()->create());

        $this->getJson($this->url())->assertForbidden();
        $this->getJson($this->url("charts/{$chart->id}"))->assertForbidden();
    }

    public function test_the_classic_builder_keeps_labels_and_shows_gaps(): void
    {
        $this->table([['Jan', '1'], ['Feb', ''], ['Mar', '3']]);

        Livewire::test(ChartBuilder::class, ['spreadsheet_id' => $this->sheet->id, 'sheet_tab_id' => $this->tab->id])
            ->set('title', 'Sales')->set('type', 'line')->set('data_range', 'B1:B3')->set('labels_range', 'A1:A3')
            ->call('saveChart')
            ->assertDispatched('render-chart', function (string $event, array $params) {
                $data = $params[0]['data'];

                // Numbers come through the browser payload as plain JSON: 1.0 arrives as 1.
                return $data['labels'] === ['Jan', 'Feb', 'Mar'] && $data['datasets'][0]['data'] == [1, null, 3];
            });

        $this->assertSame('A1:A3', ChartConfig::firstOrFail()->labels_range);
    }
}
