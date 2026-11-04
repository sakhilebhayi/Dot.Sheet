<?php

namespace Tests\Feature;

use App\Models\Cell;
use App\Models\ChartConfig;
use App\Models\SheetTab;
use App\Models\Spreadsheet;
use App\Models\User;
use App\Services\CellWriter;
use App\Services\SheetProtections;
use App\Services\StructureVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Spreadsheet $sheet;

    private SheetTab $tab;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->withPersonalTeam()->create();
        $this->sheet = Spreadsheet::create(['uuid' => (string) Str::uuid(), 'owner_id' => $this->owner->id, 'name' => 'Numbers']);
        $this->tab = $this->sheet->sheetTabs()->firstOrFail();
        $this->tab->update(['name' => 'Cash book']);
        $this->actingAs($this->owner);
    }

    /** @param list<list<string>> $rows */
    private function fill(array $rows): void
    {
        $edits = [];
        foreach ($rows as $r => $row) {
            foreach ($row as $c => $value) {
                $edits[] = ['row' => $r, 'col' => $c, 'value' => $value];
            }
        }
        app(CellWriter::class)->write($this->sheet->id, $this->tab->id, $edits, $this->owner->id);
    }

    private function sales(): void
    {
        $this->fill([['Region', 'Item', 'Amount', 'Cost'], ['North', 'Bolts', '100', '60'], ['South', 'Nuts', '300', '100'], ['North', 'Nuts', '100', '40']]);
    }

    private function make(): SheetTab
    {
        $id = $this->postJson("/spreadsheets/{$this->sheet->id}/tabs/{$this->tab->id}/dashboard")->assertOk()->json('tab');

        return SheetTab::findOrFail($id);
    }

    private function shown(SheetTab $tab, int $row, int $col): ?string
    {
        $this->getJson("/spreadsheets/{$this->sheet->id}/tabs/{$tab->id}/sheet")->assertOk();
        $cell = Cell::where('sheet_tab_id', $tab->id)->where('row_index', $row)->where('col_index', $col)->first();

        return $cell?->formula === null ? $cell?->raw_value : $cell->computed_value;
    }

    public function test_a_dashboard_is_a_new_sheet_of_working_figures(): void
    {
        $this->sales();

        $tab = $this->make();

        $this->assertSame('Dashboard', $tab->name);
        $this->assertSame('Cash book at a glance', $this->shown($tab, 0, 0));
        $this->assertSame('3', $this->shown($tab, 4, 0));
        $this->assertSame('Total Amount', $this->shown($tab, 3, 1));
        $this->assertSame('500', $this->shown($tab, 4, 1));
        $this->assertSame('200', $this->shown($tab, 4, 2));
    }

    public function test_it_sums_up_by_the_first_column_of_words(): void
    {
        $this->sales();

        $tab = $this->make();

        $this->assertSame('Region', $this->shown($tab, 6, 0));
        $this->assertSame('North', $this->shown($tab, 7, 0));
        $this->assertSame('200', $this->shown($tab, 7, 1));
        $this->assertSame('300', $this->shown($tab, 8, 1));
        $this->assertSame('0.6', $this->shown($tab, 8, 2));
    }

    public function test_its_figures_follow_the_table(): void
    {
        $this->sales();
        $tab = $this->make();
        $this->shown($tab, 4, 1);

        $base = "/spreadsheets/{$this->sheet->id}/tabs/{$this->tab->id}";
        $this->getJson("{$base}/sheet")->assertOk();
        $this->postJson("{$base}/cells", ['version' => StructureVersion::current($this->sheet->id), 'edits' => [['row' => 1, 'col' => 2, 'value' => '200']]])->assertOk();

        $this->assertSame('600', $this->shown($tab, 4, 1));
    }

    public function test_a_chart_of_the_summary_sits_on_it(): void
    {
        $this->sales();

        $tab = $this->make();

        $chart = ChartConfig::where('sheet_tab_id', $tab->id)->sole();
        $this->assertSame('Amount by Region', $chart->title);
        $this->assertSame('B8:B9', $chart->data_range);
        $this->assertSame('A8:A9', $chart->labels_range);
        $this->assertTrue($chart->options['on_sheet']);
    }

    public function test_a_second_dashboard_takes_the_next_name(): void
    {
        $this->sales();

        $this->make();

        $this->assertSame('Dashboard 2', $this->make()->name);
    }

    public function test_a_column_of_too_many_different_words_is_not_summed_by(): void
    {
        $rows = [['Name', 'Amount']];
        foreach (range(1, 20) as $i) {
            $rows[] = ["Person {$i}", (string) $i];
        }
        $this->fill($rows);

        $tab = $this->make();

        $this->assertSame('210', $this->shown($tab, 4, 1));
        $this->assertNull($this->shown($tab, 6, 0));
        $this->assertSame(0, ChartConfig::where('sheet_tab_id', $tab->id)->count());
    }

    public function test_a_sheet_name_that_needs_quotes_gets_them(): void
    {
        $this->sales();

        $this->assertStringContainsString("'Cash book'!", (string) Cell::where('sheet_tab_id', $this->make()->id)->where('row_index', 4)->where('col_index', 0)->value('formula'));
    }

    public function test_no_table_and_no_figures_are_refused_in_words(): void
    {
        $url = "/spreadsheets/{$this->sheet->id}/tabs/{$this->tab->id}/dashboard";

        $this->postJson($url)->assertUnprocessable()->assertJsonPath('message', 'This sheet has no table to sum up. Put headings in the top row of one first.');

        $this->fill([['Name', 'Town'], ['Sipho', 'Durban']]);
        $this->postJson($url)->assertUnprocessable()->assertJsonPath('message', 'This table has no column of figures to sum up.');

        $this->assertSame(1, $this->sheet->sheetTabs()->count());
    }

    public function test_only_people_who_can_edit_make_one_and_a_protected_sheet_still_can_be_summed_up(): void
    {
        $this->sales();
        $url = "/spreadsheets/{$this->sheet->id}/tabs/{$this->tab->id}/dashboard";

        $reader = User::factory()->withPersonalTeam()->create();
        $this->sheet->sharedUsers()->attach($reader->id, ['permission' => 'view']);
        $this->actingAs($reader)->postJson($url)->assertForbidden();

        $editor = User::factory()->withPersonalTeam()->create();
        $this->sheet->sharedUsers()->attach($editor->id, ['permission' => 'edit']);
        app(SheetProtections::class)->protectSheet($this->sheet, $this->tab->id, $this->owner->id);
        $this->actingAs($editor)->postJson($url)->assertOk();
    }

    public function test_a_sheet_is_reached_only_through_its_own_workbook(): void
    {
        $other = Spreadsheet::create(['uuid' => (string) Str::uuid(), 'owner_id' => $this->owner->id, 'name' => 'Other']);

        $this->postJson("/spreadsheets/{$other->id}/tabs/{$this->tab->id}/dashboard")->assertNotFound();
    }
}
