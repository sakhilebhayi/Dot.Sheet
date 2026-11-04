<?php

namespace Tests\Feature;

use App\Livewire\ShowSpreadsheet;
use App\Models\Cell;
use App\Models\SheetTab;
use App\Models\Spreadsheet;
use App\Models\User;
use App\Models\WorkflowExecutionLog;
use App\Notifications\WorkflowRuleTriggered;
use App\Services\SheetWorkflows;
use App\Services\StructureVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

class SheetWorkflowsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Spreadsheet $sheet;

    private SheetTab $tab;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->withPersonalTeam()->create();
        $this->sheet = Spreadsheet::create(['uuid' => (string) str()->uuid(), 'owner_id' => $this->owner->id, 'team_id' => $this->owner->currentTeam->id, 'name' => 'Stock']);
        $this->tab = $this->sheet->sheetTabs()->firstOrFail();
        $this->actingAs($this->owner);
    }

    /**
     * @param  array<string, mixed>  $rule
     */
    private function rule(array $rule = []): void
    {
        $this->sheet->update(['settings' => ['ai_workflows' => [['id' => 'low', 'name' => 'Low stock', 'column' => 'B', 'operator' => '<', 'value' => '10', 'action' => 'email_owner', 'enabled' => true, ...$rule]]]]);
    }

    private function type(int $row, int $col, string $value): TestResponse
    {
        return $this->postJson("/spreadsheets/{$this->sheet->id}/tabs/{$this->tab->id}/cells", [
            'version' => StructureVersion::current($this->sheet->id),
            'edits' => [['row' => $row, 'col' => $col, 'value' => $value]],
        ])->assertOk();
    }

    public function test_a_value_typed_in_the_new_editor_sets_off_a_rule(): void
    {
        Notification::fake();
        $this->rule();

        $this->type(3, 1, '4');

        Notification::assertSentTo($this->owner, WorkflowRuleTriggered::class, fn ($n) => $n->cellReference === 'B4' && (string) $n->actualValue === '4' && $n->ruleName === 'Low stock');
        $this->assertDatabaseHas('workflow_execution_logs', ['spreadsheet_id' => $this->sheet->id, 'cell_reference' => 'B4', 'status' => 'notified', 'triggered_by_user_id' => $this->owner->id]);
    }

    public function test_a_value_typed_in_the_classic_editor_still_sets_off_a_rule(): void
    {
        Notification::fake();
        $this->rule();

        Livewire::test(ShowSpreadsheet::class, ['spreadsheet_id' => $this->sheet->id])
            ->set('editingCell', '3_1')
            ->call('saveCell', '4');

        Notification::assertSentTo($this->owner, WorkflowRuleTriggered::class);
        $this->assertSame(1, WorkflowExecutionLog::count());
    }

    public function test_a_rule_stays_quiet_for_other_values_other_columns_and_when_switched_off(): void
    {
        Notification::fake();
        $this->rule();

        $this->type(0, 1, '50');
        $this->type(0, 2, '4');
        Notification::assertNothingSent();

        $this->rule(['enabled' => false]);
        $this->type(1, 1, '4');
        Notification::assertNothingSent();
    }

    public function test_a_formula_is_judged_by_its_answer(): void
    {
        Notification::fake();
        $this->rule();

        $this->type(0, 1, '=2+3');

        Notification::assertSentTo($this->owner, WorkflowRuleTriggered::class, fn ($n) => (string) $n->actualValue === '5');
    }

    public function test_the_same_value_in_the_same_cell_alerts_once_in_five_minutes(): void
    {
        Notification::fake();
        $this->rule();

        $this->type(0, 1, '4');
        $this->type(0, 1, '4');
        Notification::assertSentTimes(WorkflowRuleTriggered::class, 1);

        $this->type(0, 1, '3');
        Notification::assertSentTimes(WorkflowRuleTriggered::class, 2);
    }

    public function test_a_rule_can_mark_the_cell_and_the_answer_carries_the_colour(): void
    {
        Notification::fake();
        $this->rule(['action' => 'highlight_cell']);

        $this->type(0, 1, '4')->assertJsonPath('cells.0.x.bg_color', '#FEF08A');

        $this->assertDatabaseHas('workflow_execution_logs', ['status' => 'highlighted']);
    }

    public function test_a_refused_value_sets_nothing_off(): void
    {
        Notification::fake();
        $this->rule();
        $this->tab->forceFill(['layout' => ['merges' => [['r1' => 0, 'r2' => 1, 'c1' => 0, 'c2' => 1]]]])->save();

        $this->type(1, 1, '4')->assertJsonPath('refused.0.row', 1);

        Notification::assertNothingSent();
    }

    public function test_a_large_paste_sends_no_flood_of_alerts(): void
    {
        Notification::fake();
        $this->rule();
        $edits = array_map(fn (int $row) => ['row' => $row, 'col' => 1, 'value' => '4'], range(0, SheetWorkflows::MAX_WATCHED));

        $this->postJson("/spreadsheets/{$this->sheet->id}/tabs/{$this->tab->id}/cells", ['version' => StructureVersion::current($this->sheet->id), 'edits' => $edits])->assertOk();

        Notification::assertNothingSent();
    }

    public function test_mail_that_will_not_send_does_not_lose_the_edit(): void
    {
        $this->rule();
        Mail::shouldReceive('mailer')->andThrow(new RuntimeException('No mail server'));
        Mail::shouldReceive('send')->andThrow(new RuntimeException('No mail server'));
        $this->withoutExceptionHandling();

        $this->type(0, 1, '4');

        $this->assertSame('4', Cell::where('sheet_tab_id', $this->tab->id)->firstOrFail()->raw_value);
        $this->assertDatabaseHas('workflow_execution_logs', ['status' => 'failed']);
    }
}
