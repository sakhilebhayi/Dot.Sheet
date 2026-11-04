<?php

namespace Tests\Feature;

use App\Livewire\PivotTableBuilder;
use App\Models\Cell;
use App\Models\PivotTable;
use App\Models\SheetTab;
use App\Models\Spreadsheet;
use App\Models\SpreadsheetVersion;
use App\Models\User;
use App\Services\CellWriter;
use App\Services\Formula\Recalculator;
use App\Services\PivotTableService;
use App\Services\SheetVersions;
use App\Services\StructureVersion;
use App\Services\WorkbookSheets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GridPivotsTest extends TestCase
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
        $this->sheet = Spreadsheet::create(['uuid' => (string) str()->uuid(), 'owner_id' => $this->owner->id, 'team_id' => $this->owner->currentTeam->id, 'name' => 'Pivoted']);
        $this->tab = $this->sheet->sheetTabs()->firstOrFail();
        $this->actingAs($this->owner);
    }

    private function url(string $path = 'pivots', ?SheetTab $tab = null): string
    {
        return "/spreadsheets/{$this->sheet->id}/tabs/".($tab ?? $this->tab)->id."/{$path}";
    }

    /**
     * @param  list<list<string>>  $rows  from A1
     */
    private function table(array $rows, ?SheetTab $tab = null): void
    {
        $edits = [];
        foreach ($rows as $r => $row) {
            foreach ($row as $c => $value) {
                if ($value !== '') {
                    $edits[] = ['row' => $r, 'col' => $c, 'value' => $value];
                }
            }
        }
        app(CellWriter::class)->write($this->sheet->id, ($tab ?? $this->tab)->id, $edits, $this->owner->id);
    }

    private function sales(): void
    {
        $this->table([
            ['Region', 'Quarter', 'Amount'],
            ['East', 'Q1', '10'],
            ['East', 'Q2', '30'],
            ['West', 'Q1', '100'],
        ]);
    }

    private function pivot(string $aggregation = 'sum', ?string $across = null, string $source = 'A1:C4', ?SheetTab $tab = null): PivotTable
    {
        return app(PivotTableService::class)->add($this->sheet->id, ($tab ?? $this->tab)->id, 'Sales', $source, 'A', $across, 'C', $aggregation);
    }

    /**
     * @return array<string, mixed>
     */
    private function worked(string $aggregation = 'sum', ?string $across = null, string $source = 'A1:C4'): array
    {
        return app(PivotTableService::class)->compute($this->pivot($aggregation, $across, $source));
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function change(array $body): TestResponse
    {
        return $this->postJson($this->url('structure'), ['version' => StructureVersion::current($this->sheet->id), ...$body]);
    }

    /**
     * Worked by hand from East 10, 30 and West 100.
     *
     * @return array<string, array{string, float, float, float}>
     */
    public static function totals(): array
    {
        return [
            'sum' => ['sum', 40.0, 100.0, 140.0],
            'count' => ['count', 2.0, 1.0, 3.0],
            'average' => ['average', 20.0, 100.0, 140 / 3],
            'lowest' => ['min', 10.0, 100.0, 10.0],
            'highest' => ['max', 30.0, 100.0, 100.0],
        ];
    }

    #[DataProvider('totals')]
    public function test_totals_come_from_the_figures_and_not_from_the_cells_above_them(string $aggregation, float $east, float $west, float $total): void
    {
        $this->sales();

        $result = $this->worked($aggregation);

        $this->assertEqualsWithDelta($east, $result['matrix']['East']['Value'], 0.0001);
        $this->assertEqualsWithDelta($west, $result['matrix']['West']['Value'], 0.0001);
        $this->assertEqualsWithDelta($total, $result['grandTotal'], 0.0001);
        $this->assertEqualsWithDelta($total, $result['columnTotals']['Value'], 0.0001);
    }

    #[DataProvider('totals')]
    public function test_row_totals_across_columns_come_from_the_figures_too(string $aggregation, float $east, float $west, float $total): void
    {
        $this->sales();

        $result = $this->worked($aggregation, 'B');

        $this->assertEqualsWithDelta($east, $result['rowTotals']['East'], 0.0001);
        $this->assertEqualsWithDelta($west, $result['rowTotals']['West'], 0.0001);
        $this->assertEqualsWithDelta($total, $result['grandTotal'], 0.0001);
    }

    public function test_a_combination_with_no_figures_is_empty_and_not_nought(): void
    {
        $this->sales();

        $result = $this->worked('sum', 'B');

        $this->assertNull($result['matrix']['West']['Q2']);
        $this->assertSame(100.0, $result['matrix']['West']['Q1']);
    }

    public function test_a_real_nought_stays_a_nought(): void
    {
        $this->table([['Region', 'x', 'Amount'], ['East', '', '0']]);

        $this->assertSame(0.0, $this->worked('sum', null, 'A1:C2')['matrix']['East']['Value']);
    }

    public function test_a_row_with_no_label_stays_in_the_totals(): void
    {
        $this->table([['Region', 'x', 'Amount'], ['East', '', '10'], ['', '', '5']]);

        $result = $this->worked('sum', null, 'A1:C3');

        $this->assertSame(['East', PivotTableService::BLANK], $result['rowLabels']);
        $this->assertSame(15.0, $result['grandTotal']);
    }

    public function test_an_empty_row_in_the_table_is_not_data(): void
    {
        $this->table([['Region', 'x', 'Amount'], ['East', '', '10'], ['', 'note', ''], ['West', '', '5']]);

        $this->assertSame(['East', 'West'], $this->worked('sum', null, 'A1:C4')['rowLabels']);
    }

    public function test_cells_left_empty_after_clearing_do_not_make_a_row(): void
    {
        $this->table([['Region', 'x', 'Amount'], ['East', '', '10']]);
        foreach ([0, 2] as $col) {
            Cell::create(['spreadsheet_id' => $this->sheet->id, 'sheet_tab_id' => $this->tab->id, 'row_index' => 2, 'col_index' => $col, 'raw_value' => '', 'computed_value' => '', 'updated_at' => now()]);
        }

        $this->assertSame(['East'], $this->worked('sum', null, 'A1:C3')['rowLabels']);
    }

    public function test_count_counts_what_is_there_including_words_and_not_what_is_missing(): void
    {
        $this->table([['Region', 'x', 'Amount'], ['East', '', '10'], ['East', '', 'n/a'], ['East', '', '']]);

        $result = $this->worked('count', null, 'A1:C4');

        $this->assertSame(2.0, $result['matrix']['East']['Value']);
        $this->assertSame(0, $result['words'], 'a count can use them, so nothing was left out');
    }

    public function test_words_in_a_column_of_figures_are_left_out_and_said(): void
    {
        $this->table([['Region', 'x', 'Amount'], ['East', '', '10'], ['East', '', 'n/a']]);

        $result = $this->worked('sum', null, 'A1:C3');

        $this->assertSame(10.0, $result['matrix']['East']['Value']);
        $this->assertSame(1, $result['words']);
    }

    public function test_labels_sort_as_people_read_them(): void
    {
        $this->table([['Unit', 'x', 'Amount'], ['Unit 10', '', '1'], ['Unit 2', '', '1'], ['', '', '1'], ['unit 1', '', '1']]);

        $this->assertSame(['unit 1', 'Unit 2', 'Unit 10', PivotTableService::BLANK], $this->worked('sum', null, 'A1:C5')['rowLabels']);
    }

    public function test_years_as_labels_stay_labels(): void
    {
        $this->table([['Year', 'x', 'Amount'], ['2025', '', '1'], ['2024', '', '2']]);

        $result = $this->worked('sum', null, 'A1:C3');

        $this->assertSame(['2024', '2025'], $result['rowLabels']);
        $this->assertSame(2.0, $result['matrix']['2024']['Value']);
    }

    public function test_more_rows_than_fit_are_counted_and_stay_in_the_totals(): void
    {
        $rows = [['Id', 'x', 'Amount']];
        for ($r = 1; $r <= PivotTableService::MAX_ROWS + 2; $r++) {
            $rows[] = ["Row {$r}", '', '1'];
        }
        $this->table($rows);

        $result = $this->worked('sum', null, 'A1:C'.count($rows));

        $this->assertCount(PivotTableService::MAX_ROWS, $result['rowLabels']);
        $this->assertSame(2, $result['more_rows']);
        $this->assertSame((float) PivotTableService::MAX_ROWS + 2, $result['grandTotal']);
    }

    public function test_the_form_learns_a_tables_columns_from_its_headings(): void
    {
        $this->table([['Region', '', 'Amount'], ['East', 'x', '10']]);

        $this->getJson($this->url('pivots/fields').'?range=A1:C2')->assertOk()->assertExactJson(['fields' => [
            ['col' => 'A', 'name' => 'Region', 'figures' => false],
            ['col' => 'B', 'name' => 'Column B', 'figures' => false],
            ['col' => 'C', 'name' => 'Amount', 'figures' => true],
        ]]);
    }

    public function test_a_range_that_is_no_table_offers_nothing(): void
    {
        $this->getJson($this->url('pivots/fields').'?range=A1')->assertOk()->assertJsonPath('fields', []);
        $this->getJson($this->url('pivots/fields').'?range=nonsense')->assertOk()->assertJsonPath('fields', []);
    }

    public function test_a_pivot_is_added_read_and_deleted(): void
    {
        $this->sales();

        $answer = $this->postJson($this->url(), ['title' => 'By region', 'source_range' => 'a1:$C$4', 'row_field' => 'a', 'column_field' => 'B', 'value_field' => 'c', 'aggregation' => 'sum'])->assertOk()->json();
        $id = $answer['added'];
        $this->assertSame('A1:C4', $answer['pivots'][0]['source_range']);
        $this->assertSame('A', $answer['pivots'][0]['row_field']);

        $this->getJson($this->url("pivots/{$id}"))->assertOk()
            ->assertJsonPath('across', true)
            ->assertJsonPath('headings.row', 'Region')
            ->assertJsonPath('headings.value', 'Amount')
            ->assertJsonPath('columnLabels', ['Q1', 'Q2'])
            ->assertJsonPath('grandTotal', 140);

        $this->deleteJson($this->url("pivots/{$id}"))->assertOk()->assertJsonPath('pivots', []);
    }

    public function test_a_pivot_reads_the_sheet_as_it_is_now_worked_out_first(): void
    {
        $this->table([['Region', 'Base', 'Amount'], ['East', '5', '=B2*2']]);
        $pivot = $this->pivot('sum', null, 'A1:C2');
        $this->getJson($this->url("pivots/{$pivot->id}"))->assertJsonPath('grandTotal', 10);

        Cell::where('sheet_tab_id', $this->tab->id)->where('row_index', 1)->where('col_index', 1)->update(['raw_value' => '7', 'computed_value' => '7']);
        app(Recalculator::class)->markStale($this->sheet->id, $this->tab->id);

        $this->getJson($this->url("pivots/{$pivot->id}"))->assertJsonPath('grandTotal', 14);
    }

    public function test_a_pivot_that_makes_no_sense_is_refused_in_words(): void
    {
        $post = fn (array $body) => $this->postJson($this->url(), ['title' => 'x', 'source_range' => 'A1:C4', 'row_field' => 'A', 'column_field' => null, 'value_field' => 'C', 'aggregation' => 'sum', ...$body]);

        $post(['source_range' => 'nonsense'])->assertUnprocessable()->assertJsonPath('message', 'The table must look like A1:D20.');
        $post(['source_range' => 'A1:C1'])->assertUnprocessable()->assertJsonPath('message', 'Choose a table with headings and at least one row under them.');
        $post(['row_field' => 'F'])->assertUnprocessable()->assertJsonPath('message', 'Choose columns inside the table.');
        $post(['value_field' => 'F'])->assertUnprocessable()->assertJsonPath('message', 'Choose columns inside the table.');
        $post(['column_field' => 'F'])->assertUnprocessable()->assertJsonPath('message', 'Choose columns inside the table.');
        $post(['aggregation' => 'median'])->assertUnprocessable();
        $this->assertSame(0, PivotTable::count());
    }

    public function test_a_pivot_follows_rows_and_columns(): void
    {
        $this->sales();
        $pivot = $this->pivot('sum', 'B');

        $this->change(['action' => 'insert', 'axis' => 'row', 'at' => 0, 'count' => 1])->assertOk();
        $this->change(['action' => 'insert', 'axis' => 'col', 'at' => 1, 'count' => 1])->assertOk();

        $pivot->refresh();
        $this->assertSame('A2:D5', $pivot->source_range);
        $this->assertSame(['A', 'C', 'D'], [$pivot->row_field, $pivot->column_field, $pivot->value_field]);
        $this->assertSame(140.0, app(PivotTableService::class)->compute($pivot)['grandTotal']);
    }

    public function test_a_row_going_from_the_table_shrinks_it(): void
    {
        $this->sales();
        $pivot = $this->pivot();

        $this->change(['action' => 'delete', 'axis' => 'row', 'at' => 3, 'count' => 1])->assertOk();

        $this->assertSame('A1:C3', $pivot->fresh()->source_range);
        $this->assertSame(40.0, app(PivotTableService::class)->compute($pivot->fresh())['grandTotal']);
    }

    public function test_a_pivot_whose_column_went_says_so_rather_than_counting_the_wrong_one(): void
    {
        $this->sales();
        $pivot = $this->pivot('sum', 'B');

        $this->change(['action' => 'delete', 'axis' => 'col', 'at' => 2, 'count' => 1])->assertOk();

        $this->assertSame('#REF!', $pivot->fresh()->value_field);
        $this->getJson($this->url("pivots/{$pivot->id}"))->assertOk()->assertJsonPath('gone', true)->assertJsonPath('rowLabels', []);
    }

    public function test_a_column_going_before_the_table_moves_its_columns_down(): void
    {
        $this->table([['x', 'Region', 'Amount'], ['', 'East', '10']]);
        $pivot = app(PivotTableService::class)->add($this->sheet->id, $this->tab->id, 'S', 'B1:C2', 'B', null, 'C', 'sum');

        $this->change(['action' => 'delete', 'axis' => 'col', 'at' => 0, 'count' => 1])->assertOk();

        $pivot->refresh();
        $this->assertSame(['A1:B2', 'A', 'B'], [$pivot->source_range, $pivot->row_field, $pivot->value_field]);
        $this->assertSame(10.0, app(PivotTableService::class)->compute($pivot)['grandTotal']);
    }

    public function test_putting_a_copy_back_puts_the_pivot_back_on_its_cells(): void
    {
        $this->table([['x', 'Region', 'Amount'], ['', 'East', '10']]);
        $pivot = app(PivotTableService::class)->add($this->sheet->id, $this->tab->id, 'S', 'B1:C2', 'B', null, 'C', 'sum');

        $copy = $this->change(['action' => 'delete', 'axis' => 'col', 'at' => 0, 'count' => 1])->assertOk()->json('undo.restore');
        $this->postJson($this->url('restore'), ['version' => StructureVersion::current($this->sheet->id), 'copy' => $copy])->assertOk();

        $pivot->refresh();
        $this->assertSame(['B1:C2', 'B', 'C'], [$pivot->source_range, $pivot->row_field, $pivot->value_field]);
    }

    public function test_a_copy_from_before_pivots_were_kept_leaves_them_alone(): void
    {
        $this->sales();
        $pivot = $this->pivot();
        $copy = app(SheetVersions::class)->snapshot($this->sheet->id, $this->tab->id, $this->owner->id, SheetVersions::MANUAL, 'Old');
        SpreadsheetVersion::whereKey($copy->id)->update(['pivot_sources' => null]);
        $pivot->update(['source_range' => 'A1:C9']);

        app(SheetVersions::class)->restore($copy->fresh(), $this->owner->id);

        $this->assertSame('A1:C9', $pivot->fresh()->source_range);
    }

    public function test_pivots_are_listed_for_their_own_sheet_and_reached_only_through_it(): void
    {
        $second = app(WorkbookSheets::class)->add($this->sheet, 'Second');
        $here = $this->pivot();
        $elsewhere = $this->pivot('sum', null, 'A1:C4', $second);

        $this->getJson($this->url())->assertOk()->assertJsonCount(1, 'pivots')->assertJsonPath('pivots.0.id', $here->id);
        $this->getJson($this->url("pivots/{$elsewhere->id}"))->assertNotFound();
        $this->deleteJson($this->url("pivots/{$elsewhere->id}"))->assertNotFound();
        $this->assertNotNull($elsewhere->fresh());
    }

    public function test_someone_who_can_read_sees_pivots_and_changes_nothing(): void
    {
        $this->sales();
        $pivot = $this->pivot();
        $reader = User::factory()->withPersonalTeam()->create();
        $this->sheet->sharedUsers()->attach($reader->id, ['permission' => 'view']);
        $this->actingAs($reader);

        $this->getJson($this->url())->assertOk()->assertJsonCount(1, 'pivots');
        $this->getJson($this->url("pivots/{$pivot->id}"))->assertOk();
        $this->getJson($this->url('pivots/fields').'?range=A1:C4')->assertForbidden();
        $this->postJson($this->url(), ['title' => 'x', 'source_range' => 'A1:C4', 'row_field' => 'A', 'column_field' => null, 'value_field' => 'C', 'aggregation' => 'sum'])->assertForbidden();
        $this->deleteJson($this->url("pivots/{$pivot->id}"))->assertForbidden();
        $this->assertNotNull($pivot->fresh());
    }

    public function test_a_stranger_sees_nothing(): void
    {
        $pivot = $this->pivot();
        $this->actingAs(User::factory()->withPersonalTeam()->create());

        $this->getJson($this->url())->assertForbidden();
        $this->getJson($this->url("pivots/{$pivot->id}"))->assertForbidden();
    }

    public function test_the_classic_builder_shows_a_true_average_and_a_blank_for_nothing(): void
    {
        $this->sales();

        $panel = Livewire::test(PivotTableBuilder::class, ['spreadsheet_id' => $this->sheet->id, 'sheet_tab_id' => $this->tab->id])
            ->set('title', 'Average')->set('source_range', 'A1:C4')->set('row_field', 'A')->set('column_field', 'B')->set('value_field', 'C')->set('aggregation', 'average')
            ->call('savePivot');

        $result = $panel->get('result');
        $this->assertEqualsWithDelta(140 / 3, $result['grandTotal'], 0.0001);
        $this->assertNull($result['matrix']['West']['Q2']);
        $panel->assertSee('46.67')->assertDontSee('140.00');
    }

    public function test_the_classic_builder_refuses_a_column_outside_the_table(): void
    {
        Livewire::test(PivotTableBuilder::class, ['spreadsheet_id' => $this->sheet->id, 'sheet_tab_id' => $this->tab->id])
            ->set('title', 'x')->set('source_range', 'A1:C4')->set('row_field', 'F')->set('value_field', 'C')->set('aggregation', 'sum')
            ->call('savePivot')
            ->assertHasErrors('source_range');

        $this->assertSame(0, PivotTable::count());
    }
}
