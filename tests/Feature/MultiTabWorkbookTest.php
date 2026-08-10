<?php

namespace Tests\Feature;

use App\Livewire\ShowSpreadsheet;
use App\Models\Cell;
use App\Models\ChartConfig;
use App\Models\Comment;
use App\Models\SheetTab;
use App\Models\Spreadsheet;
use App\Models\SpreadsheetVersion;
use App\Models\User;
use App\Services\FormulaEvaluatorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class MultiTabWorkbookTest extends TestCase
{
    use RefreshDatabase;

    private function ownedSpreadsheet(User $user, string $name = 'Workbook'): Spreadsheet
    {
        return Spreadsheet::create([
            'uuid' => (string) str()->uuid(),
            'owner_id' => $user->id,
            'team_id' => null,
            'name' => $name,
        ]);
    }

    public function test_a_new_spreadsheet_gets_one_default_tab(): void
    {
        $user = User::factory()->create();
        $spreadsheet = $this->ownedSpreadsheet($user);

        $this->assertSame(1, SheetTab::where('spreadsheet_id', $spreadsheet->id)->count());
        $this->assertSame('Sheet1', $spreadsheet->defaultSheetTab()->name);
    }

    public function test_two_tabs_in_the_same_spreadsheet_have_fully_independent_cell_grids(): void
    {
        $user = User::factory()->create();
        $spreadsheet = $this->ownedSpreadsheet($user);
        $tabOne = $spreadsheet->defaultSheetTab();
        $tabTwo = SheetTab::create(['spreadsheet_id' => $spreadsheet->id, 'name' => 'Sheet2', 'sort_order' => 1]);

        $component = Livewire::actingAs($user)->test(ShowSpreadsheet::class, ['spreadsheet_id' => $spreadsheet->id]);
        $this->assertSame($tabOne->id, $component->get('activeSheetTabId'));

        $component->set('editingCell', '0_0')->call('saveCell', 'tab-one-value');

        $this->assertSame(1, Cell::where('sheet_tab_id', $tabOne->id)->count());
        $this->assertSame(0, Cell::where('sheet_tab_id', $tabTwo->id)->count());

        $component->call('switchTab', $tabTwo->id);
        $this->assertSame($tabTwo->id, $component->get('activeSheetTabId'));

        // Same row/col coordinate as tab one -- must not collide.
        $component->set('editingCell', '0_0')->call('saveCell', 'tab-two-value');

        $this->assertSame(1, Cell::where('sheet_tab_id', $tabOne->id)->count());
        $this->assertSame(1, Cell::where('sheet_tab_id', $tabTwo->id)->count());

        $tabOneCell = Cell::where('sheet_tab_id', $tabOne->id)->first();
        $tabTwoCell = Cell::where('sheet_tab_id', $tabTwo->id)->first();
        $this->assertSame('tab-one-value', $tabOneCell->raw_value);
        $this->assertSame('tab-two-value', $tabTwoCell->raw_value);
    }

    public function test_creating_a_tab_switches_to_it_and_it_starts_empty(): void
    {
        $user = User::factory()->create();
        $spreadsheet = $this->ownedSpreadsheet($user);
        $originalTab = $spreadsheet->defaultSheetTab();

        $component = Livewire::actingAs($user)->test(ShowSpreadsheet::class, ['spreadsheet_id' => $spreadsheet->id]);
        $component->set('editingCell', '0_0')->call('saveCell', 'first tab data');

        $component->call('createTab', 'Budget');

        $this->assertSame(2, SheetTab::where('spreadsheet_id', $spreadsheet->id)->count());
        $newTabId = SheetTab::where('spreadsheet_id', $spreadsheet->id)->where('name', 'Budget')->value('id');
        $this->assertNotSame($originalTab->id, $newTabId);
        $this->assertSame($newTabId, $component->get('activeSheetTabId'));
        $this->assertEmpty($component->get('cellData'));
    }

    public function test_deleting_the_active_tab_falls_back_to_a_remaining_tab(): void
    {
        $user = User::factory()->create();
        $spreadsheet = $this->ownedSpreadsheet($user);
        $tabOne = $spreadsheet->defaultSheetTab();
        $tabTwo = SheetTab::create(['spreadsheet_id' => $spreadsheet->id, 'name' => 'Sheet2', 'sort_order' => 1]);

        $component = Livewire::actingAs($user)->test(ShowSpreadsheet::class, [
            'spreadsheet_id' => $spreadsheet->id,
            'sheet_tab_id' => $tabTwo->id,
        ]);
        $this->assertSame($tabTwo->id, $component->get('activeSheetTabId'));

        $component->call('deleteTab', $tabTwo->id);

        $this->assertSame($tabOne->id, $component->get('activeSheetTabId'));
        $this->assertSame(1, SheetTab::where('spreadsheet_id', $spreadsheet->id)->count());
    }

    public function test_the_last_remaining_tab_cannot_be_deleted(): void
    {
        $user = User::factory()->create();
        $spreadsheet = $this->ownedSpreadsheet($user);
        $onlyTab = $spreadsheet->defaultSheetTab();

        $component = Livewire::actingAs($user)->test(ShowSpreadsheet::class, ['spreadsheet_id' => $spreadsheet->id]);
        $component->call('deleteTab', $onlyTab->id);

        $this->assertSame(1, SheetTab::where('spreadsheet_id', $spreadsheet->id)->count());
        $this->assertSame($onlyTab->id, $component->get('activeSheetTabId'));
    }

    public function test_deleting_a_tab_deletes_its_charts_comments_and_versions_but_not_the_other_tabs(): void
    {
        $user = User::factory()->create();
        $spreadsheet = $this->ownedSpreadsheet($user);
        $tabOne = $spreadsheet->defaultSheetTab();
        $tabTwo = SheetTab::create(['spreadsheet_id' => $spreadsheet->id, 'name' => 'Sheet2', 'sort_order' => 1]);

        ChartConfig::create(['spreadsheet_id' => $spreadsheet->id, 'sheet_tab_id' => $tabOne->id, 'title' => 'Keep', 'type' => 'bar', 'data_range' => 'A1:A2']);
        ChartConfig::create(['spreadsheet_id' => $spreadsheet->id, 'sheet_tab_id' => $tabTwo->id, 'title' => 'Gone', 'type' => 'bar', 'data_range' => 'A1:A2']);
        Comment::create(['spreadsheet_id' => $spreadsheet->id, 'sheet_tab_id' => $tabTwo->id, 'user_id' => $user->id, 'row_index' => 0, 'col_index' => 0, 'content' => 'bye']);
        SpreadsheetVersion::create(['spreadsheet_id' => $spreadsheet->id, 'sheet_tab_id' => $tabTwo->id, 'user_id' => $user->id, 'label' => 'v1', 'cells_snapshot' => []]);

        $component = Livewire::actingAs($user)->test(ShowSpreadsheet::class, ['spreadsheet_id' => $spreadsheet->id]);
        $component->call('deleteTab', $tabTwo->id);

        $this->assertSame(1, ChartConfig::where('spreadsheet_id', $spreadsheet->id)->count());
        $this->assertSame('Keep', ChartConfig::where('spreadsheet_id', $spreadsheet->id)->first()->title);
        $this->assertSame(0, Comment::where('spreadsheet_id', $spreadsheet->id)->count());
        $this->assertSame(0, SpreadsheetVersion::where('spreadsheet_id', $spreadsheet->id)->count());
    }

    public function test_formula_evaluation_only_sees_cells_on_the_same_tab(): void
    {
        $user = User::factory()->create();
        $spreadsheet = $this->ownedSpreadsheet($user);
        $tabOne = $spreadsheet->defaultSheetTab();
        $tabTwo = SheetTab::create(['spreadsheet_id' => $spreadsheet->id, 'name' => 'Sheet2', 'sort_order' => 1]);

        // A1 on tab two holds 999 -- must not leak into tab one's SUM.
        Cell::create(['spreadsheet_id' => $spreadsheet->id, 'sheet_tab_id' => $tabTwo->id, 'row_index' => 0, 'col_index' => 0, 'raw_value' => '999', 'computed_value' => '999', 'updated_at' => now()]);
        Cell::create(['spreadsheet_id' => $spreadsheet->id, 'sheet_tab_id' => $tabOne->id, 'row_index' => 0, 'col_index' => 0, 'raw_value' => '2', 'computed_value' => '2', 'updated_at' => now()]);
        Cell::create(['spreadsheet_id' => $spreadsheet->id, 'sheet_tab_id' => $tabOne->id, 'row_index' => 0, 'col_index' => 1, 'raw_value' => '3', 'computed_value' => '3', 'updated_at' => now()]);

        $service = new FormulaEvaluatorService;
        $result = $service->evaluate('=SUM(A1,B1)', $spreadsheet->id, $tabOne->id);

        $this->assertFalse($result['error']);
        $this->assertSame(5.0, (float) $result['value']);
    }
}
