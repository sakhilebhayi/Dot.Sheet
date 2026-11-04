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

class GridFilterTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Spreadsheet $sheet;

    private SheetTab $tab;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->withPersonalTeam()->create();
        $this->sheet = Spreadsheet::create(['uuid' => (string) str()->uuid(), 'owner_id' => $this->owner->id, 'team_id' => $this->owner->currentTeam->id, 'name' => 'Filtered']);
        $this->tab = $this->sheet->sheetTabs()->firstOrFail();
        $this->actingAs($this->owner);

        $edits = [];
        foreach ([['Item', 'Price'], ['Pear', '3'], ['Apple', '9'], ['Fig', '1']] as $r => $row) {
            foreach ($row as $c => $value) {
                $edits[] = ['row' => $r, 'col' => $c, 'value' => $value];
            }
        }
        app(CellWriter::class)->write($this->sheet->id, $this->tab->id, $edits, $this->owner->id);
    }

    /**
     * @param  list<array<string, mixed>>  $rules
     */
    private function filter(array $rules, ?int $version = null): TestResponse
    {
        return $this->postJson("/spreadsheets/{$this->sheet->id}/tabs/{$this->tab->id}/filter", ['version' => $version ?? StructureVersion::current($this->sheet->id), 'rules' => $rules]);
    }

    /**
     * @return list<int>
     */
    private function hidden(): array
    {
        $rows = $this->tab->fresh()->layout['hidden_rows'] ?? [];
        sort($rows);

        return $rows;
    }

    public function test_a_filter_hides_rows_that_do_not_match_and_keeps_the_heading(): void
    {
        $this->filter([['col' => 0, 'type' => 'contains', 'value' => 'PEA']])->assertOk()->assertJsonPath('hidden', [2, 3]);

        $this->assertSame([2, 3], $this->hidden());
    }

    public function test_frozen_rows_stay_whatever_the_filter_says(): void
    {
        $this->tab->forceFill(['layout' => ['frozen_rows' => 2]])->save();

        $this->filter([['col' => 0, 'type' => 'contains', 'value' => 'Fig']])->assertOk();

        $this->assertSame([2], $this->hidden());
    }

    public function test_numbers_compare_and_between_keeps_its_ends(): void
    {
        $this->filter([['col' => 1, 'type' => 'condition', 'operator' => '>', 'value' => '2']])->assertOk();
        $this->assertSame([3], $this->hidden());

        $this->filter([['col' => 1, 'type' => 'number_between', 'value' => '1', 'value2' => '3']])->assertOk();
        $this->assertSame([2], $this->hidden());
    }

    public function test_every_filter_has_to_pass(): void
    {
        $this->filter([
            ['col' => 1, 'type' => 'condition', 'operator' => '>', 'value' => '2'],
            ['col' => 0, 'type' => 'contains', 'value' => 'App'],
        ])->assertOk();

        $this->assertSame([1, 3], $this->hidden());
    }

    public function test_rows_hidden_by_hand_outlast_a_filter(): void
    {
        $this->tab->forceFill(['layout' => ['hidden_rows' => [7]]])->save();

        $this->filter([['col' => 0, 'type' => 'contains', 'value' => 'Pear']])->assertOk();
        $this->assertSame([2, 3, 7], $this->hidden());

        $this->filter([])->assertOk();
        $this->assertSame([7], $this->hidden());
    }

    public function test_the_classic_editor_keeps_rows_hidden_by_hand_too(): void
    {
        $this->tab->forceFill(['layout' => ['hidden_rows' => [7]]])->save();
        $editor = Livewire::test(ShowSpreadsheet::class, ['spreadsheet_id' => $this->sheet->id]);

        $editor->call('addFilterRule', ['column' => 'A', 'type' => 'contains', 'value' => 'Pear']);
        $this->assertSame([2, 3, 7], $this->hidden());
        $this->assertCount(3, $editor->get('hiddenRows'));

        $editor->call('clearFilterRules');
        $this->assertSame([7], $this->hidden());
    }

    public function test_a_filter_set_in_the_classic_editor_is_cleared_here(): void
    {
        Livewire::test(ShowSpreadsheet::class, ['spreadsheet_id' => $this->sheet->id])
            ->call('addFilterRule', ['column' => 'A', 'type' => 'contains', 'value' => 'Pear']);

        $this->filter([])->assertOk();

        $this->assertSame([], $this->hidden());
    }

    public function test_changing_a_value_hides_nothing_until_it_is_applied_again(): void
    {
        $rules = [['col' => 0, 'type' => 'contains', 'value' => 'Pear']];
        $kept = $this->filter($rules)->json('rules');

        app(CellWriter::class)->write($this->sheet->id, $this->tab->id, [['row' => 1, 'col' => 0, 'value' => 'Plum']], $this->owner->id);
        $this->assertSame([2, 3], $this->hidden());

        $this->filter($kept)->assertOk();
        $this->assertSame([1, 2, 3], $this->hidden());
    }

    public function test_undo_puts_the_filters_back(): void
    {
        $first = $this->filter([['col' => 0, 'type' => 'contains', 'value' => 'Pear']])->json();
        $this->assertSame(['rules' => []], $first['undo']);

        $second = $this->filter([])->json();
        $this->filter($second['undo']['rules'])->assertOk();

        $this->assertSame([2, 3], $this->hidden());
        $this->assertSame($first['rules'][0]['id'], $this->tab->fresh()->layout['filter_rules'][0]['id']);
    }

    public function test_only_sensible_filters_are_taken(): void
    {
        foreach ([
            ['col' => 0, 'type' => 'regex', 'value' => 'x'],
            ['col' => SheetStructureService::GRID_COLS, 'type' => 'contains', 'value' => 'x'],
            ['col' => 0, 'type' => 'condition', 'operator' => 'x', 'value' => 'x'],
            ['col' => 0, 'type' => 'condition', 'operator' => '>', 'value' => 'lots'],
            ['col' => 0, 'type' => 'number_between', 'value' => '1'],
            ['col' => 0, 'type' => 'color', 'color' => 'red'],
        ] as $bad) {
            $this->filter([$bad])->assertStatus(422);
        }

        $this->assertSame([], $this->hidden());
    }

    public function test_a_stale_browser_changes_nothing(): void
    {
        $this->filter([['col' => 0, 'type' => 'contains', 'value' => 'Pear']], 999)->assertStatus(409);

        $this->assertSame([], $this->hidden());
    }

    public function test_someone_who_can_only_view_cannot_filter(): void
    {
        $viewer = User::factory()->withPersonalTeam()->create();
        $this->sheet->sharedUsers()->attach($viewer->id, ['permission' => 'view']);
        $this->actingAs($viewer);

        $this->filter([])->assertForbidden();
    }

    public function test_filtered_rows_follow_rows_going_in(): void
    {
        config(['sheet.structure_ops_enabled' => true]);
        $this->filter([['col' => 0, 'type' => 'contains', 'value' => 'Pear']]);

        app(SheetStructureService::class)->insertRow($this->sheet->id, $this->tab->id, 0);

        $this->assertSame([3, 4], $this->tab->fresh()->layout['filtered_rows']);
        $this->assertSame([3, 4], $this->hidden());
    }
}
