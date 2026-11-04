<?php

namespace Tests\Feature;

use App\Livewire\ShowSpreadsheet;
use App\Models\Cell;
use App\Models\NamedRange;
use App\Models\SheetTab;
use App\Models\Spreadsheet;
use App\Models\User;
use App\Services\Formula\FormulaEngine;
use App\Services\Formula\Recalculator;
use App\Services\SheetStructureService;
use App\Services\SpreadsheetImportExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The formula engine through the editor: real results, dependants that follow, sheets that read each
 * other, error values and circular references.
 */
class FormulaEngineTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Spreadsheet $spreadsheet;

    private SheetTab $tab;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->withPersonalTeam()->create();
        $this->spreadsheet = Spreadsheet::create(['uuid' => (string) str()->uuid(), 'owner_id' => $this->user->id, 'team_id' => $this->user->currentTeam->id, 'name' => 'Budget']);
        $this->tab = $this->spreadsheet->defaultSheetTab();
    }

    private function editor()
    {
        return Livewire::actingAs($this->user)->test(ShowSpreadsheet::class, ['spreadsheet_id' => $this->spreadsheet->id]);
    }

    /** Type into a cell given as "B3". */
    private function type($editor, string $reference, string $value)
    {
        preg_match('/^([A-Z])(\d+)$/', $reference, $m);

        return $editor->set('editingCell', ((int) $m[2] - 1).'_'.(ord($m[1]) - 65))->call('saveCell', $value);
    }

    private function cell(string $reference, ?SheetTab $tab = null): ?Cell
    {
        preg_match('/^([A-Z])(\d+)$/', $reference, $m);

        return Cell::where('sheet_tab_id', ($tab ?? $this->tab)->id)->where('row_index', (int) $m[2] - 1)->where('col_index', ord($m[1]) - 65)->first();
    }

    private function answer(string $reference, ?SheetTab $tab = null): ?string
    {
        return $this->cell($reference, $tab)?->computed_value;
    }

    /**
     * Every function the brief names, against this table:
     *
     *      A       B   C           D
     *   1  North   10  2026-01-31  apple
     *   2  South   20  2026-02-15  pear
     *   3  North   30  2026-03-10  apple
     *
     * @return array<string, array{string, string}>
     */
    public static function functions(): array
    {
        return [
            'SUM' => ['=SUM(B1:B3)', '60'],
            'AVERAGE' => ['=AVERAGE(B1:B3)', '20'],
            'COUNT' => ['=COUNT(B1:B3)', '3'],
            'COUNTA' => ['=COUNTA(A1:A3)', '3'],
            'COUNTIF' => ['=COUNTIF(A1:A3,"North")', '2'],
            'COUNTIFS' => ['=COUNTIFS(A1:A3,"North",B1:B3,">15")', '1'],
            'SUMIF' => ['=SUMIF(A1:A3,"North",B1:B3)', '40'],
            'SUMIFS' => ['=SUMIFS(B1:B3,A1:A3,"North",D1:D3,"apple")', '40'],
            'IF' => ['=IF(B1>5,"big","small")', 'big'],
            'IFS' => ['=IFS(B1>50,"a",B1>5,"b")', 'b'],
            'AND' => ['=AND(B1>5,B2>5)', 'TRUE'],
            'OR' => ['=OR(B1>50,B2>50)', 'FALSE'],
            'IFERROR' => ['=IFERROR(1/0,"none")', 'none'],
            'XLOOKUP' => ['=XLOOKUP("South",A1:A3,B1:B3)', '20'],
            'VLOOKUP' => ['=VLOOKUP("South",A1:B3,2,FALSE)', '20'],
            'INDEX' => ['=INDEX(B1:B3,3)', '30'],
            'MATCH' => ['=MATCH(20,B1:B3,0)', '2'],
            'FILTER' => ['=SUM(FILTER(B1:B3,A1:A3="North"))', '40'],
            'SORT' => ['=INDEX(SORT(B1:B3,1,-1),1)', '30'],
            'UNIQUE' => ['=COUNTA(UNIQUE(A1:A3))', '2'],
            'LEFT' => ['=LEFT(A1,3)', 'Nor'],
            'RIGHT' => ['=RIGHT(A1,2)', 'th'],
            'MID' => ['=MID(A2,2,2)', 'ou'],
            'TEXT' => ['=TEXT(1234.5,"#,##0.00")', '1,234.50'],
            'DATE' => ['=DATE(2026,1,31)', '46053'],
            'EOMONTH' => ['=EOMONTH(DATE(2026,1,15),0)', '46053'],
            'PMT' => ['=ROUND(PMT(0.1/12,12,-1000),2)', '87.92'],
            'operators' => ['=2+3*4-10/4', '11.5'],
            'joining text' => ['=A1&"-"&B1', 'North-10'],
            'decimals as a person expects' => ['=0.1+0.2', '0.3'],
        ];
    }

    #[DataProvider('functions')]
    public function test_the_brief_functions_calculate(string $formula, string $expected): void
    {
        $rows = [['North', '10', '2026-01-31', 'apple'], ['South', '20', '2026-02-15', 'pear'], ['North', '30', '2026-03-10', 'apple']];
        foreach ($rows as $r => $row) {
            foreach ($row as $c => $value) {
                Cell::create(['spreadsheet_id' => $this->spreadsheet->id, 'sheet_tab_id' => $this->tab->id, 'row_index' => $r, 'col_index' => $c, 'raw_value' => $value, 'updated_at' => now()]);
            }
        }

        $this->type($this->editor(), 'F1', $formula);

        $this->assertSame($expected, $this->answer('F1'), (string) $this->cell('F1')->formula_error);
    }

    public function test_today_and_now_are_dates_as_numbers(): void
    {
        $editor = $this->editor();
        $this->type($editor, 'A1', '=TODAY()');
        $this->type($editor, 'A2', '=NOW()');

        $this->assertGreaterThan(46000, (float) $this->answer('A1'));
        $this->assertGreaterThanOrEqual((float) $this->answer('A1'), (float) $this->answer('A2'));
    }

    public function test_a_change_reaches_every_formula_that_depends_on_it_in_order(): void
    {
        $editor = $this->editor();
        $this->type($editor, 'A1', '2');
        $this->type($editor, 'C1', '=B1+A1');   // reads a formula that does not exist yet
        $this->type($editor, 'B1', '=A1*10');
        $this->type($editor, 'D1', '=SUM(A1:C1)');

        $this->assertSame(['20', '22', '44'], [$this->answer('B1'), $this->answer('C1'), $this->answer('D1')]);

        $this->type($editor, 'A1', '5');

        $this->assertSame(['50', '55', '110'], [$this->answer('B1'), $this->answer('C1'), $this->answer('D1')]);
        // What is on screen moved too, without a reload.
        $this->assertSame('110', $editor->get('cellData')[0][3]['computed_value']);
    }

    public function test_only_the_formulas_that_read_a_cell_are_worked_out(): void
    {
        $editor = $this->editor();
        $this->type($editor, 'A1', '1');
        $this->type($editor, 'B1', '=A1+1');
        $this->type($editor, 'B2', '=7*6');

        $worked = app(Recalculator::class)->cellsWritten($this->spreadsheet->id, $this->tab->id, [[0, 0]]);

        $this->assertSame(["{$this->tab->id}:0:1"], $worked);
    }

    public function test_whole_columns_and_rows_follow_new_cells(): void
    {
        $editor = $this->editor();
        $this->type($editor, 'C1', '=SUM(A:A)');
        $this->type($editor, 'C2', '=SUM(5:5)');
        $this->type($editor, 'A7', '4');
        $this->type($editor, 'B5', '6');

        $this->assertSame('4', $this->answer('C1'));
        $this->assertSame('6', $this->answer('C2'));
    }

    public function test_text_inside_quotes_is_not_a_reference(): void
    {
        $editor = $this->editor();
        $this->type($editor, 'A1', '=IF(1=1,"B2","x")');
        $this->type($editor, 'B2', 'anything');

        $this->assertSame('B2', $this->answer('A1'));
        $this->assertSame(0, DB::table('cell_precedents')->count());
    }

    public function test_sheets_read_each_other_by_name(): void
    {
        $second = SheetTab::create(['spreadsheet_id' => $this->spreadsheet->id, 'name' => 'Sales 2026', 'sort_order' => 1]);
        $editor = $this->editor();
        $this->type($editor, 'A1', "='Sales 2026'!A1*2");
        $this->type($editor, 'B1', '=A1+1');

        $editor->call('switchTab', $second->id);
        $this->type($editor, 'A1', '21');

        $this->assertSame('42', $this->answer('A1'));
        $this->assertSame('43', $this->answer('B1'));
    }

    public function test_adding_a_sheet_gives_a_name_meaning_and_renaming_it_keeps_the_answer(): void
    {
        $editor = $this->editor();
        $this->type($editor, 'A1', '=Costs!A1');
        $this->assertSame('#REF!', $this->answer('A1'));

        $editor->call('createTab', 'Costs');
        $costs = SheetTab::where('name', 'Costs')->sole();
        $this->type($editor, 'A1', '9');
        $editor->call('switchTab', $this->tab->id);
        $this->assertSame('9', $this->answer('A1'));

        $editor->call('renameTab', $costs->id, 'Spend');
        $this->assertSame('9', $this->answer('A1'));
        $this->assertSame('=Spend!A1', $this->cell('A1')->formula);
    }

    public function test_error_values_say_what_went_wrong_and_pass_along(): void
    {
        $editor = $this->editor();
        $this->type($editor, 'A1', '=1/0');
        $this->type($editor, 'B1', '=A1+1');
        $this->type($editor, 'C1', '=NOSUCHFUNCTION(1)');
        $this->type($editor, 'D1', '=SUM(1');
        $this->type($editor, 'E1', '=IFERROR(A1,"caught")');

        $this->assertSame('#DIV/0!', $this->answer('A1'));
        $this->assertSame(FormulaEngine::EXPLANATIONS['#DIV/0!'], $this->cell('A1')->formula_error);
        $this->assertSame('#DIV/0!', $this->answer('B1'));
        $this->assertSame('#NAME?', $this->answer('C1'));
        $this->assertSame(FormulaEngine::UNREADABLE, $this->answer('D1'));
        $this->assertStringStartsWith('This formula could not be read', $this->cell('D1')->formula_error);
        $this->assertSame('caught', $this->answer('E1'));
        $this->assertNull($this->cell('E1')->formula_error);

        $this->type($editor, 'A1', '=4/2');
        $this->assertSame('3', $this->answer('B1'));
        $this->assertNull($this->cell('B1')->formula_error);
    }

    public function test_a_circular_reference_is_named_and_can_be_fixed(): void
    {
        $editor = $this->editor();
        $this->type($editor, 'A1', '=B1+1');
        $this->type($editor, 'B1', '=C1+1');
        $this->type($editor, 'D1', '=A1*2');   // reads the cycle without being part of it
        $this->type($editor, 'C1', '=A1+1');   // closes it

        foreach (['A1', 'B1', 'C1'] as $reference) {
            $this->assertSame(Recalculator::CIRCULAR_VALUE, $this->answer($reference), $reference);
            $this->assertSame(Recalculator::CIRCULAR, $this->cell($reference)->formula_error);
        }
        $this->assertSame('#REF!', $this->answer('D1'));
        $this->assertNotSame(Recalculator::CIRCULAR, $this->cell('D1')->formula_error);

        $this->type($editor, 'C1', '1');

        $this->assertSame(['3', '2', '6'], [$this->answer('A1'), $this->answer('B1'), $this->answer('D1')]);
        $this->assertNull($this->cell('A1')->formula_error);
    }

    public function test_a_formula_that_reads_its_own_cell_is_circular(): void
    {
        $editor = $this->editor();
        $this->type($editor, 'A3', '=SUM(A1:A3)');

        $this->assertSame(Recalculator::CIRCULAR_VALUE, $this->answer('A3'));
    }

    public function test_named_ranges_are_references(): void
    {
        NamedRange::create(['spreadsheet_id' => $this->spreadsheet->id, 'sheet_tab_id' => $this->tab->id, 'name' => 'Revenue', 'start_row' => 0, 'start_col' => 0, 'end_row' => 1, 'end_col' => 0]);
        $editor = $this->editor();
        $this->type($editor, 'A1', '3');
        $this->type($editor, 'C1', '=SUM(Revenue)');
        $this->type($editor, 'A2', '4');

        $this->assertSame('7', $this->answer('C1'));
    }

    public function test_undo_and_clear_reach_the_formulas_that_read_the_cell(): void
    {
        $editor = $this->editor();
        $this->type($editor, 'A1', '1');
        $this->type($editor, 'B1', '=A1*3');
        $this->type($editor, 'A1', '2');
        $this->assertSame('6', $this->answer('B1'));

        $editor->call('undoAction');
        $this->assertSame('3', $this->answer('B1'));

        $editor->call('redoAction');
        $this->assertSame('6', $this->answer('B1'));
    }

    public function test_a_zero_result_shows_and_a_formula_opens_for_editing(): void
    {
        $editor = $this->editor();
        $this->type($editor, 'A1', '=1-1');

        $this->assertMatchesRegularExpression('/>\s*0\s*<\/div>/', $editor->html());
        $editor->call('startEditing', 0, 0)->assertSet('editingValue', '=1-1');
    }

    public function test_imported_and_pasted_formulas_are_worked_out_when_the_sheet_is_opened(): void
    {
        app(SpreadsheetImportExportService::class)->importCsv($this->spreadsheet, "2,3,=A1+B1\n", $this->tab);
        $this->assertNull($this->tab->fresh()->formulas_built_at);

        $this->editor();

        $this->assertSame('5', $this->answer('C1'));
        $this->assertNotNull($this->tab->fresh()->formulas_built_at);
    }

    public function test_an_export_works_out_a_sheet_nobody_has_opened(): void
    {
        app(SpreadsheetImportExportService::class)->importCsv($this->spreadsheet, "2,3,=A1+B1\n", $this->tab);

        $csv = app(SpreadsheetImportExportService::class)->exportCsv($this->spreadsheet, $this->tab);

        $this->assertStringContainsString('2,3,5', $csv);
    }

    public function test_a_sheet_from_before_this_engine_is_worked_out_on_first_open(): void
    {
        Cell::create(['spreadsheet_id' => $this->spreadsheet->id, 'sheet_tab_id' => $this->tab->id, 'row_index' => 0, 'col_index' => 0, 'raw_value' => '', 'formula' => '=2+3*4', 'computed_value' => '0', 'updated_at' => now()]);

        $this->editor();

        $this->assertSame('14', $this->answer('A1'));
    }

    public function test_a_stale_sheet_is_read_after_the_stale_sheets_it_reads(): void
    {
        $second = SheetTab::create(['spreadsheet_id' => $this->spreadsheet->id, 'name' => 'Inputs', 'sort_order' => 1]);
        Cell::create(['spreadsheet_id' => $this->spreadsheet->id, 'sheet_tab_id' => $second->id, 'row_index' => 0, 'col_index' => 0, 'raw_value' => '', 'formula' => '=20+1', 'computed_value' => null, 'updated_at' => now()]);
        Cell::create(['spreadsheet_id' => $this->spreadsheet->id, 'sheet_tab_id' => $this->tab->id, 'row_index' => 0, 'col_index' => 0, 'raw_value' => '', 'formula' => '=Inputs!A1*2', 'computed_value' => null, 'updated_at' => now()]);

        $this->editor();

        $this->assertSame('42', $this->answer('A1'));
    }

    public function test_inserting_a_row_keeps_formulas_and_their_dependencies_right(): void
    {
        config(['sheet.structure_ops_enabled' => true]);
        $editor = $this->editor();
        $this->type($editor, 'A1', '1');
        $this->type($editor, 'A2', '2');
        $this->type($editor, 'B5', '=SUM(A1:A2)');

        app(SheetStructureService::class)->insertRow($this->spreadsheet->id, $this->tab->id, 1);

        $this->assertSame('3', $this->answer('B6'));
        // The formula moved to row 6 and still follows what it reads, now in A1 and A3.
        $this->assertSame('=SUM(A1:A3)', $this->cell('B6')->formula);
        $fresh = $this->editor();
        $this->type($fresh, 'A3', '10');
        $this->assertSame('11', $this->answer('B6'));
    }

    public function test_recalculating_many_formulas_stays_light(): void
    {
        for ($r = 0; $r < 1000; $r++) {
            $rows[] = ['spreadsheet_id' => $this->spreadsheet->id, 'sheet_tab_id' => $this->tab->id, 'row_index' => $r, 'col_index' => 0, 'raw_value' => (string) $r, 'formula' => null, 'updated_at' => now()];
            $rows[] = ['spreadsheet_id' => $this->spreadsheet->id, 'sheet_tab_id' => $this->tab->id, 'row_index' => $r, 'col_index' => 1, 'raw_value' => '', 'formula' => '=SUM(A$1:A'.($r + 1).')', 'updated_at' => now()];
        }
        foreach (array_chunk($rows, 400) as $chunk) {
            DB::table('cells')->insert($chunk);
        }

        $started = microtime(true);
        app(Recalculator::class)->rebuildTab($this->spreadsheet->id, $this->tab->id);

        $this->assertSame((string) (999 * 1000 / 2), Cell::where('row_index', 999)->where('col_index', 1)->value('computed_value'));
        $this->assertLessThan(10, microtime(true) - $started);
    }
}
