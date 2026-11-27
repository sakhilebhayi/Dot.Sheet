<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Cell;
use App\Models\Spreadsheet;
use App\Models\User;
use App\Notifications\WorkflowRuleTriggered;
use App\Services\StructureVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

/** EA-6: workflow rules in the grid editor, moved from the retired classic editor. */
class GridWorkflowRulesTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Spreadsheet $sheet;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->withPersonalTeam()->create();
        $this->sheet = Spreadsheet::create(['uuid' => (string) Str::uuid(), 'owner_id' => $this->owner->id, 'team_id' => null, 'name' => 'Stock']);
    }

    private function url(string $more = ''): string
    {
        return "/spreadsheets/{$this->sheet->id}/workflows{$more}";
    }

    public function test_a_rule_is_added_listed_and_runs_when_a_cell_meets_it(): void
    {
        Notification::fake();

        $this->actingAs($this->owner)->postJson($this->url(), ['name' => 'Low stock', 'column' => 'b', 'operator' => '<', 'value' => '10', 'action' => 'email_owner', 'email' => true])
            ->assertOk()->assertJsonPath('rules.0.name', 'Low stock')->assertJsonPath('rules.0.column', 'B')->assertJsonPath('can_change', true);
        $this->assertTrue(AuditEvent::where('spreadsheet_id', $this->sheet->id)->where('action', 'Added a workflow rule')->exists());

        $tab = $this->sheet->defaultSheetTab();
        $this->postJson("/spreadsheets/{$this->sheet->id}/tabs/{$tab->id}/cells", ['version' => StructureVersion::current($this->sheet->id), 'edits' => [['row' => 0, 'col' => 1, 'value' => '4']]])->assertOk();

        Notification::assertSentTo($this->owner, WorkflowRuleTriggered::class, fn ($n) => $n->ruleName === 'Low stock' && $n->cellReference === 'B1');
        $this->assertSame('4', Cell::where('sheet_tab_id', $tab->id)->where('col_index', 1)->value('raw_value'));
    }

    public function test_a_rule_is_deleted(): void
    {
        $id = $this->actingAs($this->owner)->postJson($this->url(), ['name' => 'High', 'column' => 'C', 'operator' => '>', 'value' => '100', 'action' => 'highlight_cell'])->json('rules.0.id');

        $this->deleteJson($this->url('/'.$id))->assertOk()->assertJsonCount(0, 'rules');
    }

    public function test_a_viewer_sees_the_rules_but_cannot_change_them(): void
    {
        $this->actingAs($this->owner)->postJson($this->url(), ['name' => 'High', 'column' => 'C', 'operator' => '>', 'value' => '100', 'action' => 'highlight_cell'])->assertOk();
        $viewer = User::factory()->create();
        $this->sheet->sharedUsers()->attach($viewer->id, ['permission' => 'view']);

        $this->actingAs($viewer)->getJson($this->url())->assertOk()->assertJsonCount(1, 'rules')->assertJsonPath('can_change', false);
        $this->postJson($this->url(), ['name' => 'Mine', 'column' => 'A', 'operator' => '<', 'value' => '1', 'action' => 'email_owner'])->assertForbidden();
        $this->actingAs(User::factory()->create())->getJson($this->url())->assertForbidden();
    }

    public function test_bad_rules_are_refused_with_a_reason(): void
    {
        $this->actingAs($this->owner)->postJson($this->url(), ['name' => 'x', 'column' => '12', 'operator' => 'like', 'value' => '', 'action' => 'delete_everything'])
            ->assertUnprocessable()->assertJsonValidationErrors(['name', 'column', 'operator', 'value', 'action']);
    }

    public function test_the_retired_classic_editor_and_its_scripts_are_gone_from_the_grid_page(): void
    {
        $this->actingAs($this->owner)->get("/spreadsheets/{$this->sheet->id}")->assertOk()
            ->assertDontSee('Classic editor')->assertDontSee('/classic', false)->assertSee('id="workflows"', false);
    }
}
