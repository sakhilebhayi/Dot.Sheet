<?php

namespace Tests\Feature;

use App\Livewire\AiAnalysisPanel;
use App\Livewire\CellCommentsPanel;
use App\Livewire\ChartBuilder;
use App\Livewire\NamedRangesPanel;
use App\Livewire\PivotTableBuilder;
use App\Livewire\ScriptsEditor;
use App\Livewire\ShowSpreadsheet;
use App\Livewire\SpreadsheetSharingPanel;
use App\Livewire\SpreadsheetToolbar;
use App\Livewire\VersionHistoryModal;
use App\Models\Cell;
use App\Models\ChartConfig;
use App\Models\Comment;
use App\Models\NamedRange;
use App\Models\PivotTable;
use App\Models\SheetTab;
use App\Models\Spreadsheet;
use App\Models\SpreadsheetVersion;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;
use Laravel\Jetstream\Http\Livewire\TeamMemberManager;
use Laravel\Jetstream\Jetstream;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Jetstream role "viewer" (permissions: read only) is genuinely read-only. One sheet belongs to a
 * team with an admin, an editor and a viewer; every family of write paths must answer the viewer
 * with a 403 / authorization error and keep working for the admin and the editor, and every read
 * (the page, the exports, the comments) must keep working for the viewer.
 */
class ViewerRoleTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Team $team;

    private Spreadsheet $sheet;

    private SheetTab $tab;

    /** @var array<string, User> */
    private array $members = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['sheet.structure_ops_enabled' => true]);

        $this->owner = User::factory()->withPersonalTeam()->create();
        $this->team = Team::forceCreate(['user_id' => $this->owner->id, 'name' => 'Acme', 'personal_team' => false]);

        foreach (['admin', 'editor', 'viewer'] as $role) {
            $this->members[$role] = User::factory()->withPersonalTeam()->create();
            $this->team->users()->attach($this->members[$role]->id, ['role' => $role]);
        }

        $this->sheet = Spreadsheet::create(['uuid' => (string) str()->uuid(), 'owner_id' => $this->owner->id, 'team_id' => $this->team->id, 'name' => 'Team sheet']);
        $this->tab = $this->sheet->defaultSheetTab();
        $this->cell(0, 0, '5');
        $this->cell(1, 0, '3');
    }

    private function cell(int $row, int $col, string $raw): Cell
    {
        return Cell::create(['spreadsheet_id' => $this->sheet->id, 'sheet_tab_id' => $this->tab->id, 'row_index' => $row, 'col_index' => $col, 'raw_value' => $raw, 'computed_value' => $raw, 'updated_at' => now()]);
    }

    private function raw(int $row, int $col): ?string
    {
        return Cell::where('sheet_tab_id', $this->tab->id)->where('row_index', $row)->where('col_index', $col)->value('raw_value');
    }

    /** @return array<string, array{0: string, 1: bool}> role => may write */
    public static function roles(): array
    {
        return ['admin' => ['admin', true], 'editor' => ['editor', true], 'viewer' => ['viewer', false]];
    }

    /** Run the test body for one role: Livewire calls answer with the authorization error or go through. */
    private function as(string $role, string $class, array $params = []): Testable
    {
        return Livewire::actingAs($this->members[$role])->test($class, $params);
    }

    /** Fresh component per call: a Livewire test component cannot be reused after a 403. */
    private function attempt(string $role, string $class, array $params, bool $allowed, string $method, array $args = [], array $props = []): Testable
    {
        $t = $this->as($role, $class, $params);
        foreach ($props as $key => $value) {
            $t->set($key, $value);
        }
        $t->call($method, ...$args);
        $allowed ? $t->assertOk() : $t->assertForbidden();

        return $t;
    }

    private function sheetParams(): array
    {
        return ['spreadsheet_id' => $this->sheet->id];
    }

    private function tabParams(): array
    {
        return ['spreadsheet_id' => $this->sheet->id, 'sheet_tab_id' => $this->tab->id];
    }

    // ---- the role itself ---------------------------------------------------------------------

    public function test_viewer_is_a_jetstream_role_with_read_only_and_the_others_are_unchanged(): void
    {
        $viewer = Jetstream::findRole('viewer');

        $this->assertNotNull($viewer);
        $this->assertSame('Viewer', $viewer->name);
        $this->assertSame(['read'], $viewer->permissions);
        $this->assertSame(['create', 'read', 'update', 'delete'], Jetstream::findRole('admin')->permissions);
        $this->assertSame(['read', 'create', 'update'], Jetstream::findRole('editor')->permissions);
        $this->assertSame(['admin', 'editor', 'viewer'], collect(Jetstream::$roles)->pluck('key')->sort()->values()->all());
    }

    public function test_the_policy_gives_the_viewer_read_but_not_write_comment_or_share(): void
    {
        $viewer = $this->members['viewer'];

        $this->assertTrue($viewer->can('view', $this->sheet));
        $this->assertFalse($viewer->can('update', $this->sheet));
        $this->assertFalse($viewer->can('comment', $this->sheet));
        $this->assertFalse($viewer->can('manageSharing', $this->sheet));
        $this->assertFalse($viewer->can('delete', $this->sheet));
        $this->assertFalse($viewer->can('createSpreadsheet', $this->team));

        foreach (['admin', 'editor'] as $role) {
            $this->assertTrue($this->members[$role]->can('view', $this->sheet));
            $this->assertTrue($this->members[$role]->can('update', $this->sheet));
            $this->assertTrue($this->members[$role]->can('comment', $this->sheet));
            $this->assertTrue($this->members[$role]->can('createSpreadsheet', $this->team));
            $this->assertFalse($this->members[$role]->can('delete', $this->sheet), 'deleting a sheet stays with its owner');
        }
    }

    public function test_a_member_with_a_missing_or_unknown_role_gets_no_write_access(): void
    {
        foreach ([null, 'auditor'] as $i => $role) {
            $user = User::factory()->create();
            $this->team->users()->attach($user->id, ['role' => $role]);

            $this->assertTrue($user->can('view', $this->sheet), "case {$i}");
            $this->assertFalse($user->can('update', $this->sheet), "case {$i}");
        }
    }

    public function test_an_explicit_sheet_level_grant_to_a_viewer_still_works(): void
    {
        $viewer = $this->members['viewer'];
        $this->assertFalse($viewer->can('update', $this->sheet));

        $this->sheet->sharedUsers()->attach($viewer->id, ['permission' => 'edit']);

        $this->assertTrue($viewer->fresh()->can('update', $this->sheet));
    }

    // ---- reading keeps working ---------------------------------------------------------------

    public function test_a_viewer_can_open_the_sheet_and_export_it(): void
    {
        $this->actingAs($this->members['viewer']);

        $page = $this->get(route('spreadsheets.show', $this->sheet))->assertOk();
        $page->assertSee('Export CSV');
        $page->assertDontSee('Import CSV', false);

        $this->get(route('spreadsheets.export.csv', $this->sheet))->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $this->get(route('spreadsheets.export.excel', $this->sheet))->assertOk();
        $this->get(route('spreadsheets.index'))->assertOk()->assertSee('Team sheet');
        $this->get(route('dashboard'))->assertOk();
    }

    public function test_the_editor_page_hides_write_controls_from_a_viewer_but_not_from_an_editor(): void
    {
        $viewerHtml = $this->actingAs($this->members['viewer'])->get(route('spreadsheets.show', $this->sheet))->getContent();
        $editorHtml = $this->actingAs($this->members['editor'])->get(route('spreadsheets.show', $this->sheet))->getContent();

        $this->assertStringNotContainsString('title="Add sheet"', $viewerHtml);
        $this->assertStringNotContainsString('title="Import CSV"', $viewerHtml);
        $this->assertStringContainsString('title="Add sheet"', $editorHtml);
        $this->assertStringContainsString('title="Import CSV"', $editorHtml);
    }

    public function test_a_viewer_sees_cells_comments_charts_and_versions_but_cannot_start_editing(): void
    {
        Comment::create(['spreadsheet_id' => $this->sheet->id, 'sheet_tab_id' => $this->tab->id, 'user_id' => $this->owner->id, 'row_index' => 0, 'col_index' => 0, 'content' => 'Check this', 'resolved' => false]);
        ChartConfig::create(['spreadsheet_id' => $this->sheet->id, 'sheet_tab_id' => $this->tab->id, 'title' => 'Sales', 'type' => 'bar', 'data_range' => 'A1:A2', 'options' => []]);
        SpreadsheetVersion::create(['spreadsheet_id' => $this->sheet->id, 'sheet_tab_id' => $this->tab->id, 'user_id' => $this->owner->id, 'label' => 'Before', 'cells_snapshot' => []]);

        $this->as('viewer', ShowSpreadsheet::class, $this->sheetParams())
            ->assertOk()
            ->call('startEditing', 0, 0)
            ->assertSet('editingCell', null)
            ->assertSet('selectedRow', 0);

        $this->as('viewer', CellCommentsPanel::class, $this->tabParams())->call('onCellSelected', 0, 0)->assertSee('Check this');
        $this->as('viewer', ChartBuilder::class, $this->tabParams())->assertSee('Sales')->assertDontSee('wire:click="openModal"', false);
        $this->as('viewer', VersionHistoryModal::class, $this->tabParams())->call('openModal')->assertSee('Before')->assertDontSee('restoreVersion', false);
        $this->as('viewer', PivotTableBuilder::class, $this->tabParams())->assertOk();
        $this->as('viewer', NamedRangesPanel::class, $this->tabParams())->assertOk();
        $this->as('editor', ChartBuilder::class, $this->tabParams())->assertSee('wire:click="openModal"', false);
    }

    // ---- HTTP write paths ---------------------------------------------------------------------

    #[DataProvider('roles')]
    public function test_updating_the_sheet_over_http(string $role, bool $allowed): void
    {
        $this->actingAs($this->members[$role]);

        // (the edit page itself has no view in this app yet: an allowed person gets past the 403 and meets a 500)
        $edit = $this->get(route('spreadsheets.edit', $this->sheet));
        $allowed ? $this->assertNotSame(403, $edit->getStatusCode()) : $edit->assertForbidden();

        $response = $this->put(route('spreadsheets.update', $this->sheet), ['name' => 'Renamed by '.$role, 'settings' => ['col_widths' => [0 => 90]]]);

        if ($allowed) {
            $response->assertRedirect();
            $this->assertSame('Renamed by '.$role, $this->sheet->fresh()->name);
        } else {
            $response->assertForbidden();
            $this->assertSame('Team sheet', $this->sheet->fresh()->name);
            $this->assertArrayNotHasKey('col_widths', $this->sheet->fresh()->settings ?? []);
        }
    }

    #[DataProvider('roles')]
    public function test_importing_a_file_and_polling_the_import_over_http(string $role, bool $allowed): void
    {
        $this->actingAs($this->members[$role]);
        $file = UploadedFile::fake()->createWithContent('data.csv', "Name,Qty\nWidget,3\n");

        $this->post(route('spreadsheets.import', $this->sheet), ['file' => $file])->assertStatus($allowed ? 200 : 403);
        $this->assertSame($allowed, Cell::where('spreadsheet_id', $this->sheet->id)->where('raw_value', 'Widget')->exists());

        $this->get(route('spreadsheets.import.batch-status', [$this->sheet, 'nobatch']))->assertStatus($allowed ? 404 : 403);
    }

    #[DataProvider('roles')]
    public function test_creating_a_sheet_inside_the_team(string $role, bool $allowed): void
    {
        $this->actingAs($this->members[$role]);

        $this->post(route('spreadsheets.store'), ['name' => 'Fresh', 'team_id' => $this->team->id])->assertStatus($allowed ? 302 : 403);

        $this->assertSame($allowed, Spreadsheet::where('team_id', $this->team->id)->where('name', 'Fresh')->exists());
    }

    public function test_a_viewer_can_still_create_a_personal_sheet_of_their_own(): void
    {
        $this->actingAs($this->members['viewer']);

        $this->post(route('spreadsheets.store'), ['name' => 'Mine'])->assertRedirect();

        $this->assertTrue(Spreadsheet::where('owner_id', $this->members['viewer']->id)->whereNull('team_id')->where('name', 'Mine')->exists());
    }

    #[DataProvider('roles')]
    public function test_deleting_the_sheet_stays_with_its_owner_for_every_member(string $role, bool $allowed): void
    {
        $this->actingAs($this->members[$role])->delete(route('spreadsheets.destroy', $this->sheet))->assertForbidden();
        $this->assertNotNull(Spreadsheet::find($this->sheet->id));
    }

    // ---- Livewire: the grid -------------------------------------------------------------------

    #[DataProvider('roles')]
    public function test_cell_edits_paste_and_undo(string $role, bool $allowed): void
    {
        $grid = [ShowSpreadsheet::class, $this->sheetParams()];

        $this->attempt($role, ...$grid, allowed: $allowed, method: 'saveCell', args: ['99'], props: ['editingCell' => '0_0']);
        $this->assertSame($allowed ? '99' : '5', $this->raw(0, 0));

        $t = $this->as($role, ...$grid)->call('selectCell', 1, 0)->call('pasteIntoSelectedCell', 'pasted');
        $allowed ? $t->assertOk() : $t->assertForbidden();
        $this->assertSame($allowed ? 'pasted' : '3', $this->raw(1, 0));

        $this->attempt($role, ...$grid, allowed: $allowed, method: 'saveFromFormulaBar', args: ['x']);
        $this->attempt($role, ...$grid, allowed: $allowed, method: 'undoAction');
        $this->attempt($role, ...$grid, allowed: $allowed, method: 'redoAction');
        $this->attempt($role, ...$grid, allowed: $allowed, method: 'insertTextAtSelectedCell', args: [['text' => 'ocr']]);
    }

    #[DataProvider('roles')]
    public function test_bulk_paste_and_script_or_macro_actions(string $role, bool $allowed): void
    {
        Bus::fake();
        $grid = [ShowSpreadsheet::class, $this->sheetParams()];

        $this->attempt($role, ...$grid, allowed: $allowed, method: 'bulkPasteFromText', args: ["a\tb\nc\td"]);
        $this->attempt($role, ...$grid, allowed: $allowed, method: 'applyScriptActions', args: [['actions' => [['type' => 'set', 'cell' => 'C1', 'value' => 'hacked']]]]);
        $this->assertSame($allowed ? 1 : 0, Cell::where('spreadsheet_id', $this->sheet->id)->where('raw_value', 'hacked')->count());
    }

    #[DataProvider('roles')]
    public function test_layout_changes_hide_resize_validation_conditional_filter_and_sort(string $role, bool $allowed): void
    {
        $calls = [
            ['toggleHideRowSelected', []],
            ['toggleHideColumnSelected', []],
            ['resizeRowSelected', [['row' => 0, 'height' => 40]]],
            ['resizeColumnSelected', [['col' => 0, 'width' => 120]]],
            ['addValidationRuleSelected', [['type' => 'number', 'min' => 1, 'max' => 9]]],
            ['addConditionalRuleSelected', [['operator' => '>', 'value' => '1', 'color' => '#ff0000']]],
            ['addFilterRule', [['column' => 'A', 'type' => 'contains', 'value' => '5']]],
            ['clearFilterRules', []],
            ['sortSelectedColumn', [['direction' => 'desc']]],
        ];

        foreach ($calls as [$method, $args]) {
            $this->attempt($role, ShowSpreadsheet::class, $this->sheetParams(), $allowed, $method, $args);
        }

        $settings = $this->sheet->fresh()->settings ?? [];
        foreach (['hidden_rows', 'hidden_cols', 'row_heights', 'col_widths', 'validation_rules', 'conditional_formatting_rules'] as $key) {
            $allowed ? $this->assertArrayHasKey($key, $settings, $key) : $this->assertArrayNotHasKey($key, $settings, $key);
        }
        // Sorting is the only call that moves cells: a viewer's request leaves the order alone.
        if (! $allowed) {
            $this->assertSame('5', $this->raw(0, 0));
            $this->assertSame('3', $this->raw(1, 0));
        }
    }

    #[DataProvider('roles')]
    public function test_inserting_and_deleting_rows_and_columns(string $role, bool $allowed): void
    {
        foreach (['insertRowSelected', 'deleteColumnSelected', 'insertColumnSelected', 'deleteRowSelected'] as $method) {
            $this->attempt($role, ShowSpreadsheet::class, $this->sheetParams(), $allowed, $method);
        }

        if (! $allowed) {
            $this->assertSame('5', $this->raw(0, 0));
            $this->assertSame('3', $this->raw(1, 0));
        }
    }

    #[DataProvider('roles')]
    public function test_creating_renaming_and_deleting_tabs(string $role, bool $allowed): void
    {
        $extra = SheetTab::create(['spreadsheet_id' => $this->sheet->id, 'name' => 'Extra', 'position' => 2]);
        $grid = [ShowSpreadsheet::class, $this->sheetParams()];

        $this->attempt($role, ...$grid, allowed: $allowed, method: 'createTab', args: ['Made']);
        $this->assertSame($allowed, SheetTab::where('spreadsheet_id', $this->sheet->id)->where('name', 'Made')->exists());

        $this->attempt($role, ...$grid, allowed: $allowed, method: 'renameTab', args: [$extra->id, 'Renamed']);
        $this->assertSame($allowed ? 'Renamed' : 'Extra', $extra->fresh()->name);

        $this->attempt($role, ...$grid, allowed: $allowed, method: 'deleteTab', args: [$extra->id]);
        $this->assertSame($allowed ? 0 : 1, SheetTab::whereKey($extra->id)->count());

        // Switching to another tab is a read.
        $this->as($role, ...$grid)->call('switchTab', $this->tab->id)->assertOk();
    }

    // ---- Livewire: the side panels ------------------------------------------------------------

    #[DataProvider('roles')]
    public function test_cell_formatting(string $role, bool $allowed): void
    {
        $bar = [SpreadsheetToolbar::class, $this->sheetParams()];

        $this->attempt($role, ...$bar, allowed: $allowed, method: 'applyFormatting', props: ['bold' => true]);
        $this->assertSame($allowed, Cell::where('spreadsheet_id', $this->sheet->id)->whereNotNull('formatting')->exists());

        $this->attempt($role, ...$bar, allowed: $allowed, method: 'clearFormatting');

        $t = $this->as($role, ...$bar)->call('captureFormatPainter')->call('applyFormatPainter');
        $allowed ? $t->assertOk() : $t->assertForbidden();
    }

    #[DataProvider('roles')]
    public function test_charts(string $role, bool $allowed): void
    {
        $panel = [ChartBuilder::class, $this->tabParams()];

        $this->attempt($role, ...$panel, allowed: $allowed, method: 'saveChart', props: ['title' => 'Mine', 'type' => 'bar', 'data_range' => 'A1:A2']);
        $this->assertSame($allowed ? 1 : 0, ChartConfig::where('spreadsheet_id', $this->sheet->id)->count());

        $chart = ChartConfig::create(['spreadsheet_id' => $this->sheet->id, 'sheet_tab_id' => $this->tab->id, 'title' => 'Old', 'type' => 'bar', 'data_range' => 'A1:A2', 'options' => []]);
        $this->attempt($role, ...$panel, allowed: $allowed, method: 'deleteChart', args: [$chart->id]);
        $this->assertSame($allowed ? 0 : 1, ChartConfig::whereKey($chart->id)->count());
    }

    #[DataProvider('roles')]
    public function test_pivot_tables(string $role, bool $allowed): void
    {
        $panel = [PivotTableBuilder::class, $this->tabParams()];

        $this->attempt($role, ...$panel, allowed: $allowed, method: 'savePivot', props: ['title' => 'Pivot', 'source_range' => 'A1:B2', 'row_field' => 'A', 'value_field' => 'B']);
        $this->assertSame($allowed ? 1 : 0, PivotTable::where('spreadsheet_id', $this->sheet->id)->count());

        $pivot = PivotTable::create(['spreadsheet_id' => $this->sheet->id, 'sheet_tab_id' => $this->tab->id, 'title' => 'P', 'source_range' => 'A1:B2', 'row_field' => 'A', 'value_field' => 'B', 'aggregation' => 'sum']);
        $this->attempt($role, ...$panel, allowed: $allowed, method: 'deletePivot', args: [$pivot->id]);
        $this->assertSame($allowed ? 0 : 1, PivotTable::whereKey($pivot->id)->count());
    }

    #[DataProvider('roles')]
    public function test_named_ranges(string $role, bool $allowed): void
    {
        $panel = [NamedRangesPanel::class, $this->tabParams()];

        $this->attempt($role, ...$panel, allowed: $allowed, method: 'createRange', props: ['name' => 'Revenue', 'rangeInput' => 'A1:A2']);
        $this->assertSame($allowed ? 1 : 0, NamedRange::where('spreadsheet_id', $this->sheet->id)->count());

        $range = NamedRange::create(['spreadsheet_id' => $this->sheet->id, 'sheet_tab_id' => $this->tab->id, 'name' => 'Other', 'start_row' => 0, 'start_col' => 0, 'end_row' => 0, 'end_col' => 0]);
        $this->attempt($role, ...$panel, allowed: $allowed, method: 'deleteRange', args: [$range->id]);
        $this->assertSame($allowed ? 0 : 1, NamedRange::whereKey($range->id)->count());
    }

    #[DataProvider('roles')]
    public function test_comments(string $role, bool $allowed): void
    {
        $thread = Comment::create(['spreadsheet_id' => $this->sheet->id, 'sheet_tab_id' => $this->tab->id, 'user_id' => $this->owner->id, 'row_index' => 0, 'col_index' => 0, 'content' => 'Thread', 'resolved' => false]);
        $panel = [CellCommentsPanel::class, $this->tabParams()];

        $this->attempt($role, ...$panel, allowed: $allowed, method: 'addComment', props: ['newComment' => 'Hello there']);
        $this->assertSame($allowed, Comment::where('content', 'Hello there')->exists());

        $this->attempt($role, ...$panel, allowed: $allowed, method: 'addReply', args: [$thread->id], props: ['replyDrafts' => [$thread->id => 'A reply']]);
        $this->assertSame($allowed, Comment::where('content', 'A reply')->exists());

        $this->attempt($role, ...$panel, allowed: $allowed, method: 'toggleResolved', args: [$thread->id]);
        $this->assertSame($allowed, (bool) $thread->fresh()->resolved);

        // Someone's own earlier comment can no longer be deleted by a person who may not comment.
        $own = Comment::create(['spreadsheet_id' => $this->sheet->id, 'sheet_tab_id' => $this->tab->id, 'user_id' => $this->members[$role]->id, 'row_index' => 0, 'col_index' => 0, 'content' => 'Mine', 'resolved' => false]);
        $this->attempt($role, ...$panel, allowed: $allowed, method: 'deleteComment', args: [$own->id]);
        $this->assertSame($allowed ? 0 : 1, Comment::whereKey($own->id)->count());
    }

    #[DataProvider('roles')]
    public function test_version_snapshots_and_restore(string $role, bool $allowed): void
    {
        $snapshot = SpreadsheetVersion::create(['spreadsheet_id' => $this->sheet->id, 'sheet_tab_id' => $this->tab->id, 'user_id' => $this->owner->id, 'label' => 'Old', 'cells_snapshot' => []]);
        $panel = [VersionHistoryModal::class, $this->tabParams()];

        $this->attempt($role, ...$panel, allowed: $allowed, method: 'createSnapshot', props: ['snapshotLabel' => 'Mine']);
        $this->assertSame($allowed, SpreadsheetVersion::where('label', 'Mine')->exists());

        $this->attempt($role, ...$panel, allowed: $allowed, method: 'restoreVersion', args: [$snapshot->id]);
        $this->assertSame($allowed ? 0 : 2, Cell::where('spreadsheet_id', $this->sheet->id)->count());
    }

    #[DataProvider('roles')]
    public function test_ai_panel_data_cleaning_and_workflow_rules(string $role, bool $allowed): void
    {
        $panel = [AiAnalysisPanel::class, $this->sheetParams()];

        $this->attempt($role, ...$panel, allowed: $allowed, method: 'addWorkflowRule', props: ['workflow_name' => 'Low stock']);
        $this->assertSame($allowed, ! empty($this->sheet->fresh()->settings['ai_workflows'] ?? []));

        $this->sheet->update(['settings' => ['ai_workflows' => [['id' => 'rule-1', 'name' => 'x', 'column' => 'A', 'operator' => '<', 'value' => '1', 'action' => 'highlight_cell', 'enabled' => true]]]]);
        $this->attempt($role, ...$panel, allowed: $allowed, method: 'deleteWorkflowRule', args: ['rule-1']);
        $this->assertSame($allowed ? 0 : 1, count($this->sheet->fresh()->settings['ai_workflows'] ?? []));

        $this->attempt($role, ...$panel, allowed: $allowed, method: 'cleanData', props: ['selected_range' => 'A1:A2']);
    }

    public function test_the_scripts_editor_and_the_sharing_panel_cannot_be_opened_by_a_viewer(): void
    {
        $this->as('viewer', ScriptsEditor::class, $this->sheetParams())->assertForbidden();
        $this->as('editor', ScriptsEditor::class, $this->sheetParams())->assertOk();

        foreach (['viewer', 'editor', 'admin'] as $role) {
            $this->as($role, SpreadsheetSharingPanel::class, $this->sheetParams())->assertForbidden();
        }
        Livewire::actingAs($this->owner)->test(SpreadsheetSharingPanel::class, $this->sheetParams())->assertOk();
    }

    public function test_a_component_opened_while_editor_stops_working_after_a_demotion(): void
    {
        $editor = $this->members['editor'];
        $panel = Livewire::actingAs($editor)->test(ScriptsEditor::class, $this->sheetParams())->assertOk();
        $grid = Livewire::actingAs($editor)->test(ShowSpreadsheet::class, $this->sheetParams())->set('editingCell', '0_0');

        // InfoDot sync moves the person to viewer while both components stay open in the browser.
        $this->team->users()->updateExistingPivot($editor->id, ['role' => 'viewer']);

        $panel->call('newScript')->assertForbidden();
        $grid->call('saveCell', 'late')->assertForbidden();
        $this->assertSame('5', $this->raw(0, 0));
    }

    // ---- team management ----------------------------------------------------------------------

    public function test_the_role_picker_offers_the_viewer_role_to_the_team_owner(): void
    {
        Mail::fake();

        $this->actingAs($this->owner);
        Livewire::test(TeamMemberManager::class, ['team' => $this->team])
            ->assertSee('Viewer')
            ->assertSee('Editor')
            ->assertSee('Administrator')
            ->set('addTeamMemberForm', ['email' => 'new@example.com', 'role' => 'viewer'])
            ->call('addTeamMember')
            ->assertHasNoErrors();

        $this->assertSame('viewer', $this->team->fresh()->teamInvitations()->where('email', 'new@example.com')->value('role'));
    }

    public function test_the_owner_can_make_a_member_a_viewer(): void
    {
        $this->actingAs($this->owner);

        Livewire::test(TeamMemberManager::class, ['team' => $this->team])
            ->set('managingRoleFor', $this->members['editor'])
            ->set('currentRole', 'viewer')
            ->call('updateRole');

        $this->assertTrue($this->members['editor']->fresh()->hasTeamRole($this->team->fresh(), 'viewer'));
        $this->assertFalse($this->members['editor']->fresh()->can('update', $this->sheet));
    }

    #[DataProvider('roles')]
    public function test_only_the_team_owner_manages_members_whatever_the_members_role(string $role, bool $allowed): void
    {
        Mail::fake();
        $other = $this->members['editor'];
        $this->actingAs($this->members[$role]);

        $manager = fn () => Livewire::test(TeamMemberManager::class, ['team' => $this->team]);

        $manager()->set('addTeamMemberForm', ['email' => 'new@example.com', 'role' => 'editor'])->call('addTeamMember')->assertForbidden();
        $manager()->set('managingRoleFor', $other)->set('currentRole', 'viewer')->call('updateRole')->assertForbidden();
        $this->assertTrue($other->fresh()->hasTeamRole($this->team->fresh(), 'editor'));

        if ($role !== 'editor') {
            $manager()->set('teamMemberIdBeingRemoved', $other->id)->call('removeTeamMember')->assertForbidden();
            $this->assertTrue($other->fresh()->belongsToTeam($this->team->fresh()));
        }
        $this->assertSame(0, $this->team->teamInvitations()->count());
    }

    public function test_a_viewer_can_leave_the_team_themselves(): void
    {
        $viewer = $this->members['viewer'];
        $this->actingAs($viewer);

        Livewire::test(TeamMemberManager::class, ['team' => $this->team])->call('leaveTeam');

        $this->assertFalse($viewer->fresh()->belongsToTeam($this->team->fresh()));
    }
}
