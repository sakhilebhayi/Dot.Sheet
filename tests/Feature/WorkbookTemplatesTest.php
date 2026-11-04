<?php

namespace Tests\Feature;

use App\Models\Cell;
use App\Models\ChartConfig;
use App\Models\SheetTab;
use App\Models\Spreadsheet;
use App\Models\Team;
use App\Models\User;
use App\Services\RangeText;
use App\Services\StructureVersion;
use App\Services\TemplateCatalogue;
use App\Services\WorkbookBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WorkbookTemplatesTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->withPersonalTeam()->create();
        $this->actingAs($this->owner);
    }

    /** @return array<string, array{0: string}> */
    public static function templates(): array
    {
        $keys = array_map(fn (string $file) => basename($file, '.php'), glob(dirname(__DIR__, 2).'/resources/templates/*.php') ?: []);

        return array_combine($keys, array_map(fn (string $key) => [$key], $keys));
    }

    private function make(string $key): Spreadsheet
    {
        return app(WorkbookBuilder::class)->build(app(TemplateCatalogue::class)->find($key), 'Test', $this->owner, null);
    }

    private function tab(Spreadsheet $spreadsheet, string $name): SheetTab
    {
        return $spreadsheet->sheetTabs()->where('name', $name)->sole();
    }

    /** @param array<string, string> $values reference => value */
    private function fillIn(SheetTab $tab, array $values): void
    {
        $edits = [];
        foreach ($values as $reference => $value) {
            $area = RangeText::parse($reference);
            $edits[] = ['row' => $area['r1'], 'col' => $area['c1'], 'value' => $value];
        }
        $base = "/spreadsheets/{$tab->spreadsheet_id}/tabs/{$tab->id}";
        $this->getJson("{$base}/sheet")->assertOk();
        $this->postJson("{$base}/cells", ['version' => StructureVersion::current($tab->spreadsheet_id), 'edits' => $edits])->assertOk();
    }

    private function shown(SheetTab $tab, string $reference): ?string
    {
        $this->getJson("/spreadsheets/{$tab->spreadsheet_id}/tabs/{$tab->id}/sheet")->assertOk();
        $area = RangeText::parse($reference);

        return Cell::where('sheet_tab_id', $tab->id)->where('row_index', $area['r1'])->where('col_index', $area['c1'])->value('computed_value');
    }

    private function serial(string $date): int
    {
        return (int) Carbon::parse('1899-12-30')->diffInDays(Carbon::parse($date));
    }

    #[DataProvider('templates')]
    public function test_every_template_builds_and_shows_no_errors_while_empty(string $key): void
    {
        $spreadsheet = $this->make($key);

        foreach ($spreadsheet->sheetTabs as $tab) {
            $this->getJson("/spreadsheets/{$spreadsheet->id}/tabs/{$tab->id}/sheet")->assertOk();
        }

        $errors = Cell::where('spreadsheet_id', $spreadsheet->id)->where(fn ($query) => $query->whereNotNull('formula_error')->orWhere('computed_value', 'like', '#%'))
            ->get(['sheet_tab_id', 'row_index', 'col_index', 'formula', 'computed_value']);
        $this->assertCount(0, $errors, $errors->take(3)->toJson());
        $this->assertGreaterThan(0, Cell::where('spreadsheet_id', $spreadsheet->id)->whereNotNull('formula')->count(), 'a template holds working formulas');
    }

    #[DataProvider('templates')]
    public function test_no_template_invents_a_figure(string $key): void
    {
        $spreadsheet = $this->make($key);

        // Headings and labels are text. The one number a template may carry is a published rate.
        $typed = Cell::where('spreadsheet_id', $spreadsheet->id)->whereNull('formula')->pluck('raw_value')
            ->filter(fn ($value) => is_numeric($value))
            ->reject(fn ($value) => $key === 'attendance-register' && (int) $value >= 1 && (int) $value <= 31);

        $this->assertSame($key === 'expense-tracker' ? ['0.15'] : [], $typed->values()->all());
    }

    #[DataProvider('templates')]
    public function test_every_template_is_described_for_the_page(string $key): void
    {
        $template = app(TemplateCatalogue::class)->find($key);

        $this->assertSame($key, $template['key']);
        $this->assertContains($template['group'], TemplateCatalogue::GROUPS);
        $this->assertNotSame('', $template['title']);
        $this->assertNotSame('', $template['summary']);
        $this->assertNotEmpty($template['words']);
    }

    public function test_a_built_workbook_has_only_its_own_sheets_in_order(): void
    {
        $spreadsheet = $this->make('expense-tracker');

        $this->assertSame(['Expenses', 'Summary'], $spreadsheet->sheetTabs()->orderBy('sort_order')->pluck('name')->all());
        $this->assertSame($this->owner->id, $spreadsheet->owner_id);
    }

    public function test_the_expense_tracker_totals_and_works_out_vat(): void
    {
        $spreadsheet = $this->make('expense-tracker');
        $tab = $this->tab($spreadsheet, 'Expenses');
        $this->fillIn($tab, ['C2' => 'Rent', 'E2' => '1150', 'F2' => 'Yes', 'C3' => 'Rent', 'E3' => '850', 'F3' => 'No', 'C4' => 'Fuel and transport', 'E4' => '500']);

        $summary = $this->tab($spreadsheet, 'Summary');
        $this->assertEqualsWithDelta(150, (float) $this->shown($tab, 'G2'), 0.0001);
        $this->assertSame('', (string) $this->shown($tab, 'G3'));
        $this->assertSame('2000', $this->shown($summary, 'B5'));
        $this->assertSame('500', $this->shown($summary, 'B8'));
        $this->assertSame('2500', $this->shown($summary, 'B14'));
        $this->assertSame('0.8', $this->shown($summary, 'C5'));
    }

    public function test_the_sales_tracker_counts_only_won_deals_for_each_rep(): void
    {
        $spreadsheet = $this->make('sales-tracker');
        $this->fillIn($this->tab($spreadsheet, 'Sales'), ['B2' => 'Thandi', 'E2' => '2', 'F2' => '100', 'H2' => 'Won', 'B3' => 'Thandi', 'E3' => '5', 'F3' => '100', 'H3' => 'Lost']);
        $byRep = $this->tab($spreadsheet, 'By rep');
        $this->fillIn($byRep, ['A5' => 'Thandi', 'D5' => '400']);

        $this->assertSame('200', $this->shown($byRep, 'B5'));
        $this->assertSame('1', $this->shown($byRep, 'C5'));
        $this->assertSame('0.5', $this->shown($byRep, 'E5'));
    }

    public function test_the_stock_register_values_stock_and_flags_what_to_reorder(): void
    {
        $spreadsheet = $this->make('stock-register');
        $stock = $this->tab($spreadsheet, 'Stock');
        $this->fillIn($stock, ['B2' => 'Bolts', 'D2' => '10', 'E2' => '4', 'F2' => '5', 'B3' => 'Nuts', 'D3' => '2', 'E3' => '50', 'F3' => '5']);

        $summary = $this->tab($spreadsheet, 'Summary');
        $this->assertSame('Reorder', $this->shown($stock, 'H2'));
        $this->assertSame('OK', $this->shown($stock, 'H3'));
        $this->assertSame('2', $this->shown($summary, 'B3'));
        $this->assertSame('140', $this->shown($summary, 'B4'));
        $this->assertSame('1', $this->shown($summary, 'B5'));
    }

    public function test_the_attendance_register_counts_each_kind_of_day(): void
    {
        $register = $this->tab($this->make('attendance-register'), 'Register');
        $this->fillIn($register, ['A2' => 'Sipho', 'C2' => 'P', 'D2' => 'P', 'E2' => 'A', 'F2' => 'L', 'AG2' => 'S']);

        $this->assertSame('2', $this->shown($register, 'AH2'));
        $this->assertSame('1', $this->shown($register, 'AI2'));
        $this->assertSame('1', $this->shown($register, 'AJ2'));
        $this->assertSame('1', $this->shown($register, 'AK2'));
    }

    public function test_the_leave_tracker_counts_working_days_and_only_approved_annual_leave(): void
    {
        $spreadsheet = $this->make('leave-tracker');
        $leave = $this->tab($spreadsheet, 'Leave');
        // Monday 2 November to Friday 13 November 2026 is ten working days.
        $this->fillIn($leave, ['A2' => 'Sipho', 'B2' => 'Annual', 'C2' => (string) $this->serial('2026-11-02'), 'D2' => (string) $this->serial('2026-11-13'), 'F2' => 'Approved',
            'A3' => 'Sipho', 'B3' => 'Annual', 'C3' => (string) $this->serial('2026-12-01'), 'D3' => (string) $this->serial('2026-12-01'), 'F3' => 'Requested']);
        $balances = $this->tab($spreadsheet, 'Balances');
        $this->fillIn($balances, ['A2' => 'Sipho', 'B2' => '15']);

        $this->assertSame('10', $this->shown($leave, 'E2'));
        $this->assertSame('10', $this->shown($balances, 'C2'));
        $this->assertSame('5', $this->shown($balances, 'D2'));
    }

    public function test_the_project_budget_shows_what_is_left_and_an_overrun(): void
    {
        $budget = $this->tab($this->make('project-budget'), 'Budget');
        $this->fillIn($budget, ['C2' => '1000', 'D2' => '200', 'E2' => '300', 'C3' => '100', 'E3' => '150']);

        $this->assertSame('500', $this->shown($budget, 'F2'));
        $this->assertSame('-50', $this->shown($budget, 'F3'));
        $this->assertSame('1.5', $this->shown($budget, 'G3'));
        $this->assertSame('450', $this->shown($budget, 'I5'));
    }

    public function test_the_task_tracker_flags_late_work_but_not_finished_work(): void
    {
        $spreadsheet = $this->make('task-tracker');
        $tasks = $this->tab($spreadsheet, 'Tasks');
        $this->fillIn($tasks, ['D2' => '40000', 'E2' => 'In progress', 'D3' => '40000', 'E3' => 'Done', 'D4' => '60000', 'E4' => 'In progress']);

        $this->assertSame('Overdue', $this->shown($tasks, 'F2'));
        $this->assertSame('', (string) $this->shown($tasks, 'F3'));
        $this->assertSame('', (string) $this->shown($tasks, 'F4'));
        $summary = $this->tab($spreadsheet, 'Summary');
        $this->assertSame('2', $this->shown($summary, 'B4'));
        $this->assertSame('1', $this->shown($summary, 'B8'));
    }

    public function test_the_financial_summary_carries_through_to_net_profit(): void
    {
        $summary = $this->tab($this->make('financial-summary'), 'Summary');
        $this->fillIn($summary, ['B2' => '1000', 'B3' => '100', 'B5' => '400', 'B8' => '200', 'C2' => '500']);

        $this->assertSame('1100', $this->shown($summary, 'B4'));
        $this->assertSame('700', $this->shown($summary, 'B6'));
        $this->assertSame('500', $this->shown($summary, 'B15'));
        $this->assertSame('1000', $this->shown($summary, 'N15'));
        $this->assertSame('1', $this->shown($summary, 'C7'));
    }

    public function test_the_cash_flow_opens_each_month_on_the_last_one_s_close(): void
    {
        $forecast = $this->tab($this->make('cash-flow'), 'Forecast');
        $this->fillIn($forecast, ['B2' => '1000', 'B4' => '500', 'B9' => '200', 'C4' => '100']);

        $this->assertSame('1300', $this->shown($forecast, 'B16'));
        $this->assertSame('1300', $this->shown($forecast, 'C2'));
        $this->assertSame('1400', $this->shown($forecast, 'C16'));
        $this->assertSame('1400', $this->shown($forecast, 'M16'));
    }

    public function test_the_invoice_register_knows_paid_open_and_overdue(): void
    {
        $spreadsheet = $this->make('invoice-register');
        $invoices = $this->tab($spreadsheet, 'Invoices');
        $this->fillIn($invoices, [
            'C2' => '40000', 'D2' => '30', 'F2' => '100', 'G2' => '100',
            'C3' => '40000', 'D3' => '30', 'F3' => '200', 'G3' => '50',
            'C4' => '60000', 'D4' => '30', 'F4' => '300',
        ]);

        $this->assertSame('40030', $this->shown($invoices, 'E2'));
        $this->assertSame('Paid', $this->shown($invoices, 'I2'));
        $this->assertSame('Overdue', $this->shown($invoices, 'I3'));
        $this->assertSame('Open', $this->shown($invoices, 'I4'));
        $owed = $this->tab($spreadsheet, 'Owed');
        $this->assertSame('300', $this->shown($owed, 'B3'));
        $this->assertSame('150', $this->shown($owed, 'B4'));
        $this->assertSame('450', $this->shown($owed, 'B5'));
    }

    public function test_equipment_hours_work_out_standby_availability_and_utilisation(): void
    {
        $spreadsheet = $this->make('equipment-hours');
        $hours = $this->tab($spreadsheet, 'Hours');
        $this->fillIn($hours, ['B2' => 'T-01', 'E2' => '10', 'F2' => '6', 'G2' => '2', 'B3' => 'T-01', 'E3' => '10', 'F3' => '8', 'G3' => '0']);
        $unit = $this->tab($spreadsheet, 'By equipment');
        $this->fillIn($unit, ['A2' => 'T-01']);

        $this->assertSame('2', $this->shown($hours, 'H2'));
        $this->assertSame('20', $this->shown($unit, 'B2'));
        $this->assertSame('14', $this->shown($unit, 'C2'));
        $this->assertSame('0.9', $this->shown($unit, 'E2'));
        $this->assertEqualsWithDelta(14 / 18, (float) $this->shown($unit, 'F2'), 0.0001);
    }

    public function test_the_production_log_totals_by_section(): void
    {
        $spreadsheet = $this->make('production-log');
        $shifts = $this->tab($spreadsheet, 'Shifts');
        $this->fillIn($shifts, ['C2' => 'North', 'D2' => '100', 'E2' => '90', 'F2' => '4', 'C3' => 'North', 'D3' => '50', 'E3' => '40']);
        $sections = $this->tab($spreadsheet, 'By section');
        $this->fillIn($sections, ['A4' => 'North']);

        $this->assertSame('25', $this->shown($shifts, 'G2'));
        $this->assertSame('150', $this->shown($sections, 'B4'));
        $this->assertSame('130', $this->shown($sections, 'C14'));
    }

    public function test_the_incident_register_follows_each_action(): void
    {
        $spreadsheet = $this->make('incident-register');
        $incidents = $this->tab($spreadsheet, 'Incidents');
        $this->fillIn($incidents, [
            'B2' => 'Shaft', 'C2' => 'Near miss', 'H2' => '40000', 'I2' => '40001',
            'B3' => 'Plant', 'C3' => 'Near miss', 'H3' => '40000',
            'B4' => 'Yard', 'C4' => 'First aid', 'H4' => '60000',
        ]);

        $this->assertSame('Closed', $this->shown($incidents, 'J2'));
        $this->assertSame('Overdue', $this->shown($incidents, 'J3'));
        $this->assertSame('Open', $this->shown($incidents, 'J4'));
        $summary = $this->tab($spreadsheet, 'Summary');
        $this->assertSame('2', $this->shown($summary, 'B3'));
        $this->assertSame('1', $this->shown($summary, 'B10'));
        $this->assertSame('1', $this->shown($summary, 'B11'));
    }

    public function test_the_maintenance_schedule_says_when_a_job_is_due(): void
    {
        $schedule = $this->tab($this->make('maintenance-schedule'), 'Schedule');
        $today = $this->serial(now()->toDateString());
        $this->fillIn($schedule, ['C2' => '30', 'D2' => (string) ($today - 40), 'C3' => '30', 'D3' => (string) ($today - 25), 'C4' => '30', 'D4' => (string) $today]);

        $this->assertSame('Overdue', $this->shown($schedule, 'G2'));
        $this->assertSame('Due soon', $this->shown($schedule, 'G3'));
        $this->assertSame('OK', $this->shown($schedule, 'G4'));
    }

    public function test_the_household_budget_shows_what_is_left(): void
    {
        $budget = $this->tab($this->make('household-budget'), 'Budget');
        $this->fillIn($budget, ['B4' => '1000', 'C4' => '1000', 'B9' => '300', 'C9' => '350']);

        $this->assertSame('700', $this->shown($budget, 'B21'));
        $this->assertSame('650', $this->shown($budget, 'C21'));
        $this->assertSame('-50', $this->shown($budget, 'D9'));
    }

    public function test_allowed_values_and_colour_rules_arrive_on_the_sheet(): void
    {
        $tab = $this->tab($this->make('expense-tracker'), 'Expenses');

        $layout = $tab->layout;
        $this->assertSame('list', $layout['validation_rules'][0]['type']);
        $this->assertStringContainsString('Rent', $layout['validation_rules'][0]['items']);
        $this->assertSame([1, 2, 200, 2], [$layout['validation_rules'][0]['row'], $layout['validation_rules'][0]['col'], $layout['validation_rules'][0]['row2'], $layout['validation_rules'][0]['col2']]);
        $this->assertSame('>', $layout['conditional_formatting_rules'][0]['operator']);
        $this->assertSame(1, $layout['frozen_rows']);
        $this->assertSame(240, $layout['col_widths'][1]);
        $this->assertTrue(Cell::where('sheet_tab_id', $tab->id)->where('row_index', 0)->where('col_index', 0)->value('formatting')['bold']);
    }

    public function test_a_typed_value_outside_a_template_s_choices_is_refused(): void
    {
        $tab = $this->tab($this->make('expense-tracker'), 'Expenses');
        $base = "/spreadsheets/{$tab->spreadsheet_id}/tabs/{$tab->id}";

        $answer = $this->postJson("{$base}/cells", ['version' => 0, 'edits' => [['row' => 1, 'col' => 2, 'value' => 'Not a category']]])->assertOk()->json();

        $this->assertNotEmpty($answer['refused']);
    }

    public function test_charts_come_with_the_template(): void
    {
        $spreadsheet = $this->make('expense-tracker');

        $this->assertSame('Expenses by category', ChartConfig::where('spreadsheet_id', $spreadsheet->id)->sole()->title);
    }

    public function test_the_page_lists_the_templates_by_group(): void
    {
        $this->get('/spreadsheets/create')->assertOk()
            ->assertSee('Equipment operating-hours report')->assertSee('Mining')->assertSee('expense-tracker')->assertSee(route('spreadsheets.from-template'));
    }

    public function test_starting_from_a_template_opens_it_named_after_it(): void
    {
        $response = $this->post(route('spreadsheets.from-template'), ['template' => 'stock-register']);

        $spreadsheet = Spreadsheet::where('name', 'Stock inventory register')->sole();
        $response->assertRedirect(route('spreadsheets.show', $spreadsheet));
        $this->assertSame($this->owner->id, $spreadsheet->owner_id);
        $this->assertNull($spreadsheet->team_id);
    }

    public function test_a_name_typed_first_is_used(): void
    {
        $this->post(route('spreadsheets.from-template'), ['template' => 'stock-register', 'name' => '  Yard stock ']);

        $this->assertSame(1, Spreadsheet::where('name', 'Yard stock')->count());
    }

    public function test_an_unknown_template_is_refused(): void
    {
        $this->postJson(route('spreadsheets.from-template'), ['template' => 'nothing'])->assertUnprocessable();
        $this->assertSame(0, Spreadsheet::count());
    }

    public function test_a_template_goes_to_a_team_the_person_may_add_to_and_no_other(): void
    {
        $team = $this->owner->currentTeam;
        $this->post(route('spreadsheets.from-template'), ['template' => 'stock-register', 'team_id' => $team->id]);
        $this->assertSame($team->id, Spreadsheet::sole()->team_id);

        $other = Team::factory()->create();
        $this->post(route('spreadsheets.from-template'), ['template' => 'stock-register', 'team_id' => $other->id])->assertNotFound();
        $this->assertSame(1, Spreadsheet::count());
    }

    public function test_a_team_member_who_can_only_read_cannot_add_a_template_to_the_team(): void
    {
        $team = $this->owner->currentTeam;
        $viewer = User::factory()->withPersonalTeam()->create();
        $team->users()->attach($viewer, ['role' => 'viewer']);
        $this->actingAs($viewer);

        $this->post(route('spreadsheets.from-template'), ['template' => 'stock-register', 'team_id' => $team->id])->assertForbidden();

        $this->assertSame(0, Spreadsheet::count());
    }
}
