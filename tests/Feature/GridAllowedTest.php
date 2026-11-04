<?php

namespace Tests\Feature;

use App\Livewire\ShowSpreadsheet;
use App\Models\Cell;
use App\Models\SheetTab;
use App\Models\Spreadsheet;
use App\Models\User;
use App\Services\CellRules;
use App\Services\SheetStructureService;
use App\Services\StructureVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\TestCase;

class GridAllowedTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Spreadsheet $sheet;

    private SheetTab $tab;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->withPersonalTeam()->create();
        $this->sheet = Spreadsheet::create(['uuid' => (string) str()->uuid(), 'owner_id' => $this->owner->id, 'team_id' => $this->owner->currentTeam->id, 'name' => 'Allowed']);
        $this->tab = $this->sheet->sheetTabs()->firstOrFail();
        $this->actingAs($this->owner);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function ask(array $body): TestResponse
    {
        return $this->postJson("/spreadsheets/{$this->sheet->id}/tabs/{$this->tab->id}/allowed", ['version' => StructureVersion::current($this->sheet->id), ...$body]);
    }

    private function type(int $row, int $col, string $value): TestResponse
    {
        return $this->postJson("/spreadsheets/{$this->sheet->id}/tabs/{$this->tab->id}/cells", [
            'version' => StructureVersion::current($this->sheet->id),
            'edits' => [['row' => $row, 'col' => $col, 'value' => $value]],
        ])->assertOk();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function stored(): array
    {
        return $this->tab->fresh()->layout['validation_rules'] ?? [];
    }

    public function test_a_rule_covers_its_range_and_nothing_past_it(): void
    {
        $this->ask(['add' => ['row' => 1, 'col' => 1, 'row2' => 3, 'col2' => 2, 'type' => 'number']])->assertOk();

        $this->type(1, 1, 'words')->assertJsonPath('refused.0.message', 'Only numbers go here.');
        $this->type(3, 2, 'words')->assertJsonCount(1, 'refused');
        $this->type(0, 1, 'words')->assertJsonPath('refused', []);
        $this->type(4, 1, 'words')->assertJsonPath('refused', []);
        $this->type(1, 3, 'words')->assertJsonPath('refused', []);
        $this->type(2, 2, '5')->assertJsonPath('refused', []);
    }

    public function test_the_classic_editor_keeps_to_a_range_rule_too(): void
    {
        $this->ask(['add' => ['row' => 1, 'col' => 1, 'row2' => 3, 'col2' => 1, 'type' => 'number']]);

        Livewire::test(ShowSpreadsheet::class, ['spreadsheet_id' => $this->sheet->id])
            ->set('editingCell', '2_1')->call('saveCell', 'words');

        $this->assertNull(Cell::where('sheet_tab_id', $this->tab->id)->where('row_index', 2)->first());
    }

    public function test_a_rule_from_the_classic_editor_still_covers_its_one_cell(): void
    {
        $rules = [['row' => 2, 'col' => 2, 'type' => 'number']];

        $this->assertNotNull(CellRules::refuse($rules, 2, 2, 'x'));
        $this->assertNull(CellRules::refuse($rules, 2, 3, 'x'));
        $this->assertNull(CellRules::refuse($rules, 3, 2, 'x'));
    }

    public function test_a_broken_rule_is_passed_over(): void
    {
        $this->assertNull(CellRules::refuse([['type' => 'number'], 'nonsense'], 0, 0, 'x'));
    }

    public function test_numbers_keep_between_their_limits_and_say_which_was_passed(): void
    {
        $this->ask(['add' => ['row' => 0, 'col' => 0, 'type' => 'number', 'min' => '1', 'max' => '5']]);

        $this->type(0, 0, '0')->assertJsonPath('refused.0.message', 'That is too low: the least allowed here is 1.');
        $this->type(0, 0, '6')->assertJsonPath('refused.0.message', 'That is too high: the most allowed here is 5.');
        $this->type(0, 0, '5')->assertJsonPath('refused', []);
    }

    public function test_text_keeps_to_its_length(): void
    {
        $this->ask(['add' => ['row' => 0, 'col' => 0, 'type' => 'text_length', 'min' => '2', 'max' => '4']]);

        $this->type(0, 0, 'a')->assertJsonPath('refused.0.message', 'That is too short: text here needs at least 2 characters.');
        $this->type(0, 0, 'abcde')->assertJsonPath('refused.0.message', 'That is too long: text here runs to 4 characters at most.');
        $this->type(0, 0, 'abc')->assertJsonPath('refused', []);
    }

    public function test_a_list_is_tidied_and_says_what_it_allows(): void
    {
        $this->ask(['add' => ['row' => 0, 'col' => 0, 'type' => 'list', 'items' => ' Paid ,, Late,']])->assertOk();

        $this->assertSame('Paid, Late', $this->stored()[0]['items']);
        $this->type(0, 0, 'Maybe')->assertJsonPath('refused.0.message', 'Choose from the list: Paid, Late.');
        $this->type(0, 0, 'Late')->assertJsonPath('refused', []);
    }

    public function test_zero_can_be_on_a_list(): void
    {
        $this->assertSame(['0', '1'], CellRules::items(['items' => '0, 1']));
        $this->assertNull(CellRules::refuse([['row' => 0, 'col' => 0, 'type' => 'list', 'items' => '0, 1']], 0, 0, '0'));
    }

    public function test_clearing_a_cell_is_always_allowed(): void
    {
        $this->type(0, 0, 'words');
        $this->ask(['add' => ['row' => 0, 'col' => 0, 'type' => 'number']]);

        $this->type(0, 0, '')->assertJsonPath('refused', []);
    }

    public function test_values_already_in_the_cells_stay(): void
    {
        $this->type(0, 0, 'words');

        $this->ask(['add' => ['row' => 0, 'col' => 0, 'type' => 'number']])->assertOk();

        $this->assertSame('words', Cell::where('sheet_tab_id', $this->tab->id)->value('raw_value'));
    }

    public function test_removing_a_rule_answers_with_it_and_undo_restores_the_range_and_id(): void
    {
        $this->ask(['add' => ['row' => 1, 'col' => 1, 'row2' => 3, 'col2' => 1, 'type' => 'list', 'items' => 'a, b']]);
        $rule = $this->stored()[0];

        $undo = $this->ask(['remove' => $rule['id']])->assertOk()->json('undo');
        $this->assertSame([], $this->stored());

        $this->ask($undo)->assertOk();
        $this->assertEquals([$rule], $this->stored());
    }

    public function test_only_sensible_rules_are_taken(): void
    {
        foreach ([
            ['type' => 'regex'],
            ['type' => 'number', 'min' => 'low'],
            ['type' => 'number', 'min' => '9', 'max' => '1'],
            ['type' => 'text_length'],
            ['type' => 'text_length', 'min' => '-1'],
            ['type' => 'list'],
            ['type' => 'list', 'items' => ' , ,'],
            ['type' => 'number', 'row2' => SheetStructureService::GRID_ROWS],
            ['type' => 'number', 'col2' => SheetStructureService::GRID_COLS],
            ['type' => 'number', 'row' => 5, 'row2' => 2],
        ] as $bad) {
            $this->ask(['add' => ['row' => 0, 'col' => 0, ...$bad]])->assertStatus(422);
        }

        $this->assertSame([], $this->stored());
    }

    public function test_only_what_a_rule_is_made_of_is_stored(): void
    {
        $this->ask(['add' => ['row' => 0, 'col' => 0, 'type' => 'number', 'items' => 'ignored', 'script' => 'x']])->assertOk();

        $rule = $this->stored()[0];
        $this->assertArrayNotHasKey('script', $rule);
        $this->assertArrayNotHasKey('items', $rule);
    }

    public function test_a_range_moves_grows_and_shrinks_with_rows_and_columns(): void
    {
        config(['sheet.structure_ops_enabled' => true]);
        $structure = app(SheetStructureService::class);
        $this->ask(['add' => ['row' => 2, 'col' => 1, 'row2' => 4, 'col2' => 2, 'type' => 'number']]);

        $structure->insertRow($this->sheet->id, $this->tab->id, 0);
        $this->assertSame([3, 5], [$this->stored()[0]['row'], $this->stored()[0]['row2']]);

        $structure->insertRow($this->sheet->id, $this->tab->id, 4);
        $this->assertSame([3, 6], [$this->stored()[0]['row'], $this->stored()[0]['row2']]);

        $structure->deleteRow($this->sheet->id, $this->tab->id, 3);
        $this->assertSame([3, 5], [$this->stored()[0]['row'], $this->stored()[0]['row2']]);

        $structure->insertColumn($this->sheet->id, $this->tab->id, 2);
        $this->assertSame([1, 3], [$this->stored()[0]['col'], $this->stored()[0]['col2']]);
    }

    public function test_a_range_with_nothing_left_goes(): void
    {
        config(['sheet.structure_ops_enabled' => true]);
        $this->ask(['add' => ['row' => 2, 'col' => 0, 'row2' => 2, 'col2' => 3, 'type' => 'number']]);

        app(SheetStructureService::class)->deleteRow($this->sheet->id, $this->tab->id, 2);

        $this->assertSame([], $this->stored());
    }

    public function test_a_sheet_holds_a_limited_number_of_rules(): void
    {
        $this->tab->forceFill(['layout' => ['validation_rules' => array_fill(0, 500, ['id' => 'x', 'row' => 900, 'col' => 0, 'type' => 'number'])]])->save();

        $this->ask(['add' => ['row' => 0, 'col' => 0, 'type' => 'number']])->assertStatus(422);
    }

    public function test_a_stale_browser_changes_nothing(): void
    {
        $this->ask(['add' => ['row' => 0, 'col' => 0, 'type' => 'number'], 'version' => 999])->assertStatus(409);

        $this->assertSame([], $this->stored());
    }

    public function test_only_people_who_can_edit_may_change_rules(): void
    {
        $viewer = User::factory()->withPersonalTeam()->create();
        $this->sheet->sharedUsers()->attach($viewer->id, ['permission' => 'view']);
        $this->actingAs($viewer);

        $this->ask(['add' => ['row' => 0, 'col' => 0, 'type' => 'number']])->assertForbidden();
    }
}
