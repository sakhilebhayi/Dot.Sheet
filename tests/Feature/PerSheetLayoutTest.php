<?php

namespace Tests\Feature;

use App\Livewire\ShowSpreadsheet;
use App\Models\SheetTab;
use App\Models\Spreadsheet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Each sheet of a workbook keeps its own row heights, column widths, hidden rows and columns,
 * validation, conditional formats and filters.
 */
class PerSheetLayoutTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Spreadsheet $spreadsheet;

    private SheetTab $first;

    private SheetTab $second;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->withPersonalTeam()->create();
        $this->spreadsheet = Spreadsheet::create(['uuid' => (string) str()->uuid(), 'owner_id' => $this->user->id, 'team_id' => $this->user->currentTeam->id, 'name' => 'Budget']);
        $this->first = $this->spreadsheet->defaultSheetTab();
        $this->second = SheetTab::create(['spreadsheet_id' => $this->spreadsheet->id, 'name' => 'Second', 'sort_order' => 1]);
    }

    public function test_a_change_on_one_sheet_stays_on_that_sheet(): void
    {
        $editor = Livewire::actingAs($this->user)->test(ShowSpreadsheet::class, ['spreadsheet_id' => $this->spreadsheet->id]);

        $editor->set('selectedRow', 3)->call('toggleHideRowSelected')
            ->call('resizeColumnSelected', ['col' => 1, 'width' => 150])
            ->assertSet('hiddenRows', [3]);

        $editor->call('switchTab', $this->second->id)
            ->assertSet('hiddenRows', [])
            ->assertSet('colWidths', [])
            ->set('selectedRow', 0)->call('addValidationRuleSelected', ['type' => 'number', 'min' => 1, 'max' => 9]);

        $editor->call('switchTab', $this->first->id)
            ->assertSet('hiddenRows', [3])
            ->assertSet('colWidths', [1 => 150])
            ->assertSet('validationRules', []);

        $this->assertSame([3], $this->first->fresh()->layout['hidden_rows']);
        $this->assertArrayNotHasKey('hidden_rows', $this->second->fresh()->layout);
        $this->assertCount(1, $this->second->fresh()->layout['validation_rules']);
        $this->assertSame([], array_intersect_key($this->spreadsheet->fresh()->settings ?? [], array_flip(SheetTab::LAYOUT_KEYS)));
    }

    public function test_the_update_route_sets_the_named_sheet_or_the_first(): void
    {
        $this->actingAs($this->user)->put(route('spreadsheets.update', $this->spreadsheet), [
            'name' => 'Budget', 'sheet_tab_id' => $this->second->id, 'settings' => ['col_widths' => [2 => 80]],
        ])->assertRedirect();
        $this->actingAs($this->user)->put(route('spreadsheets.update', $this->spreadsheet), [
            'name' => 'Budget', 'settings' => ['hidden_cols' => [4]],
        ])->assertRedirect();

        $this->assertSame([2 => 80], $this->second->fresh()->layout['col_widths']);
        $this->assertSame([4], $this->first->fresh()->layout['hidden_cols']);
        $this->assertArrayNotHasKey('hidden_cols', $this->second->fresh()->layout);
    }

    public function test_the_update_route_refuses_a_sheet_of_another_workbook(): void
    {
        $other = Spreadsheet::create(['uuid' => (string) str()->uuid(), 'owner_id' => $this->user->id, 'team_id' => $this->user->currentTeam->id, 'name' => 'Other']);

        $this->actingAs($this->user)->put(route('spreadsheets.update', $this->spreadsheet), [
            'name' => 'Budget', 'sheet_tab_id' => $other->defaultSheetTab()->id, 'settings' => ['col_widths' => [2 => 80]],
        ])->assertSessionHasErrors('sheet_tab_id');

        $this->assertNull($other->defaultSheetTab()->layout);
    }

    public function test_the_move_gives_every_existing_sheet_the_workbooks_layout_and_back(): void
    {
        $migration = require database_path('migrations/2026_11_03_000001_move_layout_settings_to_sheet_tabs.php');
        $migration->down();

        $layout = ['row_heights' => ['3' => 40], 'hidden_cols' => [2], 'filter_rules' => [['id' => 'f', 'col' => 1]]];
        DB::table('spreadsheets')->where('id', $this->spreadsheet->id)->update(['settings' => json_encode($layout + ['scripts' => [['id' => 'kept']]])]);

        $migration->up();

        foreach ([$this->first, $this->second] as $tab) {
            $this->assertEquals($layout, $tab->fresh()->layout);
        }
        $this->assertSame(['scripts' => [['id' => 'kept']]], $this->spreadsheet->fresh()->settings);

        // Back: the workbook takes the first sheet's layout again.
        $this->first->forceFill(['layout' => ['hidden_rows' => [7]]])->save();
        $migration->down();

        $settings = json_decode((string) DB::table('spreadsheets')->where('id', $this->spreadsheet->id)->value('settings'), true);
        $this->assertSame([7], $settings['hidden_rows']);
        $this->assertSame([['id' => 'kept']], $settings['scripts']);

        $migration->up();
    }
}
