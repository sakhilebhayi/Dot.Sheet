<?php

namespace Tests\Feature;

use App\Livewire\ShowSpreadsheet;
use App\Models\SheetTab;
use App\Models\Spreadsheet;
use App\Models\User;
use App\Services\CellWriter;
use App\Services\SheetStructureService;
use App\Services\StructureVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\TestCase;

class GridRulesTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Spreadsheet $sheet;

    private SheetTab $tab;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->withPersonalTeam()->create();
        $this->sheet = Spreadsheet::create(['uuid' => (string) str()->uuid(), 'owner_id' => $this->owner->id, 'team_id' => $this->owner->currentTeam->id, 'name' => 'Rules']);
        $this->tab = $this->sheet->sheetTabs()->firstOrFail();
        $this->actingAs($this->owner);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function ask(array $body): TestResponse
    {
        return $this->postJson("/spreadsheets/{$this->sheet->id}/tabs/{$this->tab->id}/rules", ['version' => StructureVersion::current($this->sheet->id), ...$body]);
    }

    /**
     * @param  array<string, mixed>  $change
     * @return array<string, mixed>
     */
    private function rule(array $change = []): array
    {
        return ['target' => 'column', 'row' => 0, 'col' => 1, 'operator' => '<', 'value' => '0', 'color' => '#F7E0DA', ...$change];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function stored(): array
    {
        return $this->tab->fresh()->layout['conditional_formatting_rules'] ?? [];
    }

    public function test_a_rule_is_saved_with_an_id_and_the_answer_says_how_to_undo_it(): void
    {
        $body = $this->ask(['add' => $this->rule()])->assertOk()->json();

        $id = $this->stored()[0]['id'];
        $this->assertNotEmpty($id);
        $this->assertSame(['remove' => $id], $body['undo']);
        $this->assertSame($this->stored(), $body['rules']);
    }

    public function test_removing_a_rule_answers_with_the_rule_and_undo_restores_it_with_its_id(): void
    {
        $this->ask(['add' => $this->rule()]);
        $this->ask(['add' => $this->rule(['col' => 2])]);
        $first = $this->stored()[0];

        $undo = $this->ask(['remove' => $first['id']])->assertOk()->json('undo');
        $this->assertCount(1, $this->stored());
        $this->assertSame(2, $this->stored()[0]['col']);

        $this->ask($undo)->assertOk();
        $this->assertContains($first['id'], array_column($this->stored(), 'id'));
    }

    public function test_removing_a_rule_that_has_gone_changes_nothing(): void
    {
        $this->ask(['add' => $this->rule()]);

        $this->ask(['remove' => 'not-a-rule'])->assertOk()->assertJsonPath('undo', null);

        $this->assertCount(1, $this->stored());
    }

    public function test_the_classic_editor_draws_a_rule_set_here(): void
    {
        app(CellWriter::class)->write($this->sheet->id, $this->tab->id, [['row' => 0, 'col' => 1, 'value' => '-5']], $this->owner->id);
        $this->ask(['add' => $this->rule()])->assertOk();

        $editor = Livewire::test(ShowSpreadsheet::class, ['spreadsheet_id' => $this->sheet->id]);

        $this->assertSame($this->stored(), $editor->get('conditionalFormattingRules'));
    }

    public function test_only_sensible_rules_are_taken(): void
    {
        foreach ([
            ['target' => 'sheet'],
            ['operator' => 'matches'],
            ['color' => 'red'],
            ['color' => 'url(evil)'],
            ['col' => SheetStructureService::GRID_COLS],
            ['row' => SheetStructureService::GRID_ROWS],
            ['value' => str_repeat('x', 256)],
            ['operator' => '<', 'value' => 'lots'],
            ['operator' => '>=', 'value' => ''],
        ] as $bad) {
            $this->ask(['add' => $this->rule($bad)])->assertStatus(422);
        }

        $this->assertSame([], $this->stored());
    }

    public function test_only_what_a_rule_is_made_of_is_stored(): void
    {
        $this->ask(['add' => $this->rule(['script' => 'alert(1)'])])->assertOk();

        $this->assertArrayNotHasKey('script', $this->stored()[0]);
    }

    public function test_text_rules_need_no_number(): void
    {
        $this->ask(['add' => $this->rule(['operator' => 'contains', 'value' => 'late'])])->assertOk();
        $this->ask(['add' => $this->rule(['operator' => '==', 'value' => ''])])->assertOk();

        $this->assertCount(2, $this->stored());
    }

    public function test_a_sheet_holds_a_limited_number_of_rules(): void
    {
        $this->tab->forceFill(['layout' => ['conditional_formatting_rules' => array_fill(0, 200, $this->rule(['id' => 'x']))]])->save();

        $this->ask(['add' => $this->rule()])->assertStatus(422);

        $this->assertCount(200, $this->stored());
    }

    public function test_a_stale_browser_changes_nothing(): void
    {
        $this->ask(['add' => $this->rule(), 'version' => 999])->assertStatus(409);

        $this->assertSame([], $this->stored());
    }

    public function test_only_people_who_can_edit_may_change_rules(): void
    {
        $viewer = User::factory()->withPersonalTeam()->create();
        $this->sheet->sharedUsers()->attach($viewer->id, ['permission' => 'view']);
        $this->actingAs($viewer);

        $this->ask(['add' => $this->rule()])->assertForbidden();
    }

    public function test_a_rule_follows_its_column_when_one_goes_in(): void
    {
        config(['sheet.structure_ops_enabled' => true]);
        $this->ask(['add' => $this->rule()]);

        app(SheetStructureService::class)->insertColumn($this->sheet->id, $this->tab->id, 0);

        $this->assertSame(2, $this->stored()[0]['col']);
    }
}
