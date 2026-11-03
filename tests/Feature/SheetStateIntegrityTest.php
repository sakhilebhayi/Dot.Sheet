<?php

namespace Tests\Feature;

use App\Actions\Ecosystem\ProvisionUserFromInfoDot;
use App\Livewire\AiAnalysisPanel;
use App\Livewire\AiFormulaModal;
use App\Livewire\AiNaturalLanguageQuery;
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
use App\Models\SheetTab;
use App\Models\Spreadsheet;
use App\Models\SpreadsheetInvitation;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Jetstream\Http\Livewire\UpdateProfileInformationForm;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A sheet component's identity (which sheet, which tab) and its loaded state belong to the server.
 * Each test sends what a browser could send and checks that only this person's own sheet is reached.
 */
class SheetStateIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private User $person;

    private User $other;

    private Spreadsheet $own;

    private Spreadsheet $theirs;

    private SheetTab $ownTab;

    private SheetTab $theirTab;

    protected function setUp(): void
    {
        parent::setUp();

        $this->person = User::factory()->withPersonalTeam()->create();
        $this->other = User::factory()->withPersonalTeam()->create();
        $this->own = $this->sheetFor($this->person, 'Mine');
        $this->theirs = $this->sheetFor($this->other, 'Theirs');
        $this->ownTab = $this->own->defaultSheetTab();
        $this->theirTab = $this->theirs->defaultSheetTab();

        Cell::create(['spreadsheet_id' => $this->theirs->id, 'sheet_tab_id' => $this->theirTab->id, 'row_index' => 0, 'col_index' => 0, 'raw_value' => 'THEIR_VALUE', 'updated_at' => now()]);
    }

    private function sheetFor(User $user, string $name): Spreadsheet
    {
        return Spreadsheet::create(['uuid' => (string) str()->uuid(), 'owner_id' => $user->id, 'team_id' => $user->currentTeam->id, 'name' => $name]);
    }

    /**
     * @return array<string, array{class-string, array<string, mixed>, array<string, mixed>}>
     */
    private function components(): array
    {
        $sheet = ['spreadsheet_id' => $this->own->id];
        $tabbed = $sheet + ['sheet_tab_id' => $this->ownTab->id];
        $away = ['spreadsheet_id' => $this->theirs->id];
        $awayTab = $away + ['sheet_tab_id' => $this->theirTab->id];

        return [
            'editor' => [ShowSpreadsheet::class, $sheet, $away + ['activeSheetTabId' => $this->theirTab->id]],
            'editor tabs' => [ShowSpreadsheet::class, $sheet, ['sheetTabs' => [['id' => $this->theirTab->id, 'name' => 'x', 'sort_order' => 0]]]],
            'editor history' => [ShowSpreadsheet::class, $sheet, ['undoStack' => [['row' => 0, 'col' => 0, 'old' => 'a', 'new' => 'b']]]],
            'toolbar' => [SpreadsheetToolbar::class, $sheet, $away],
            'toolbar tab' => [SpreadsheetToolbar::class, $sheet, ['sheet_tab_id' => $this->theirTab->id]],
            'ai analysis' => [AiAnalysisPanel::class, $tabbed, $awayTab],
            'ai formula' => [AiFormulaModal::class, $sheet, $away],
            'ai question' => [AiNaturalLanguageQuery::class, $sheet, $away],
            'comments' => [CellCommentsPanel::class, $tabbed, $awayTab],
            'charts' => [ChartBuilder::class, $tabbed, $awayTab],
            'named ranges' => [NamedRangesPanel::class, $tabbed, $awayTab],
            'pivots' => [PivotTableBuilder::class, $tabbed, $awayTab],
            'scripts' => [ScriptsEditor::class, $sheet, $away],
            'sharing' => [SpreadsheetSharingPanel::class, $sheet, $away],
            'versions' => [VersionHistoryModal::class, $tabbed, $awayTab],
        ];
    }

    public function test_no_component_accepts_a_new_sheet_tab_or_loaded_state_from_the_browser(): void
    {
        foreach ($this->components() as $label => [$class, $params, $updates]) {
            $component = Livewire::actingAs($this->person)->test($class, $params);

            foreach ($updates as $property => $value) {
                try {
                    $component->update(updates: [$property => $value]);
                    $this->fail("{$label}: the browser changed {$property}.");
                } catch (CannotUpdateLockedPropertyException) {
                    $this->addToAssertionCount(1);
                }
            }
        }
    }

    public function test_the_fields_people_type_into_still_save(): void
    {
        Livewire::actingAs($this->person)->test(ShowSpreadsheet::class, ['spreadsheet_id' => $this->own->id])
            ->call('startEditing', 0, 0)
            ->set('editingValue', 'typed')
            ->call('saveCell', 'typed');

        $this->assertSame('typed', Cell::where('spreadsheet_id', $this->own->id)->where('row_index', 0)->where('col_index', 0)->value('raw_value'));
    }

    public function test_an_edit_aimed_at_another_sheet_changes_nothing_there(): void
    {
        $editor = Livewire::actingAs($this->person)->test(ShowSpreadsheet::class, ['spreadsheet_id' => $this->own->id]);

        try {
            $editor->update(
                calls: [['method' => 'saveCell', 'params' => ['CHANGED'], 'path' => '']],
                updates: ['spreadsheet_id' => $this->theirs->id, 'activeSheetTabId' => $this->theirTab->id, 'editingCell' => '0_0'],
            );
        } catch (CannotUpdateLockedPropertyException) {
        }

        $this->assertSame('THEIR_VALUE', Cell::where('spreadsheet_id', $this->theirs->id)->value('raw_value'));
        $this->assertStringNotContainsString('THEIR_VALUE', $editor->html());
    }

    public function test_tabs_of_another_sheet_cannot_be_added_or_removed(): void
    {
        $extra = SheetTab::create(['spreadsheet_id' => $this->theirs->id, 'name' => 'Second', 'sort_order' => 5]);
        $editor = Livewire::actingAs($this->person)->test(ShowSpreadsheet::class, ['spreadsheet_id' => $this->own->id]);

        foreach ([
            [[['method' => 'createTab', 'params' => ['Added'], 'path' => '']], ['spreadsheet_id' => $this->theirs->id]],
            [[['method' => 'deleteTab', 'params' => [$this->theirTab->id], 'path' => '']], ['sheetTabs' => [['id' => $this->theirTab->id, 'name' => 'x', 'sort_order' => 0], ['id' => $extra->id, 'name' => 'y', 'sort_order' => 1]]]],
        ] as [$calls, $updates]) {
            try {
                $editor->update(calls: $calls, updates: $updates);
            } catch (CannotUpdateLockedPropertyException) {
            }
        }

        $this->assertSame(['Sheet1', 'Second'], SheetTab::where('spreadsheet_id', $this->theirs->id)->orderBy('sort_order')->pluck('name')->all());
        $this->assertSame(1, Cell::where('spreadsheet_id', $this->theirs->id)->count());
        $this->assertSame(1, SheetTab::where('spreadsheet_id', $this->own->id)->count());
    }

    public function test_access_is_checked_again_on_every_request(): void
    {
        $this->theirs->sharedUsers()->attach($this->person->id, ['permission' => 'view']);
        $editor = Livewire::actingAs($this->person)->test(ShowSpreadsheet::class, ['spreadsheet_id' => $this->theirs->id]);
        $this->assertStringContainsString('THEIR_VALUE', $editor->html());

        $this->theirs->sharedUsers()->detach($this->person->id);

        $editor->call('reloadFromBroadcast')->assertForbidden();
    }

    public function test_a_tab_named_by_an_event_or_argument_must_belong_to_the_sheet(): void
    {
        $toolbar = Livewire::actingAs($this->person)->test(SpreadsheetToolbar::class, ['spreadsheet_id' => $this->own->id]);

        foreach ([['followTab', [$this->theirTab->id]], ['loadCellFormatting', [0, 0, $this->theirTab->id]]] as [$method, $params]) {
            Livewire::actingAs($this->person)->test(SpreadsheetToolbar::class, ['spreadsheet_id' => $this->own->id])
                ->call($method, ...$params)
                ->assertNotFound()
                ->assertSet('sheet_tab_id', null);
        }

        $toolbar->call('followTab', $this->ownTab->id)->assertSet('sheet_tab_id', $this->ownTab->id);
    }

    public function test_a_panel_cannot_be_opened_on_another_sheets_tab(): void
    {
        foreach ([ChartBuilder::class, PivotTableBuilder::class, NamedRangesPanel::class, CellCommentsPanel::class, VersionHistoryModal::class, AiAnalysisPanel::class] as $class) {
            Livewire::actingAs($this->person)
                ->test($class, ['spreadsheet_id' => $this->own->id, 'sheet_tab_id' => $this->theirTab->id])
                ->assertNotFound();
        }
    }

    public function test_a_chart_is_only_found_on_its_own_sheet(): void
    {
        $chart = ChartConfig::forceCreate(['spreadsheet_id' => $this->theirs->id, 'sheet_tab_id' => $this->theirTab->id, 'title' => 'THEIR_CHART', 'type' => 'bar', 'data_range' => 'A1:A1']);

        $this->expectException(ModelNotFoundException::class);
        Livewire::actingAs($this->person)
            ->test(ChartBuilder::class, ['spreadsheet_id' => $this->own->id, 'sheet_tab_id' => $this->ownTab->id])
            ->call('renderChart', $chart->id);
    }

    public function test_a_reply_only_joins_a_thread_of_the_same_tab(): void
    {
        $thread = Comment::create(['spreadsheet_id' => $this->theirs->id, 'sheet_tab_id' => $this->theirTab->id, 'user_id' => $this->other->id, 'row_index' => 3, 'col_index' => 2, 'content' => 'Their thread', 'resolved' => false]);

        Livewire::actingAs($this->person)
            ->test(CellCommentsPanel::class, ['spreadsheet_id' => $this->own->id, 'sheet_tab_id' => $this->ownTab->id])
            ->set("replyDrafts.{$thread->id}", 'Reply')
            ->call('addReply', $thread->id);

        $this->assertSame(0, Comment::where('parent_id', $thread->id)->count());

        $mine = Comment::create(['spreadsheet_id' => $this->own->id, 'sheet_tab_id' => $this->ownTab->id, 'user_id' => $this->person->id, 'row_index' => 3, 'col_index' => 2, 'content' => 'My thread', 'resolved' => false]);
        Livewire::actingAs($this->person)
            ->test(CellCommentsPanel::class, ['spreadsheet_id' => $this->own->id, 'sheet_tab_id' => $this->ownTab->id])
            ->set("replyDrafts.{$mine->id}", 'Reply')
            ->call('addReply', $mine->id);

        $reply = Comment::where('parent_id', $mine->id)->sole();
        $this->assertSame([3, 2], [(int) $reply->row_index, (int) $reply->col_index]);
    }

    public function test_cleaning_a_range_touches_only_the_tab_on_screen(): void
    {
        $second = SheetTab::create(['spreadsheet_id' => $this->own->id, 'name' => 'Second', 'sort_order' => 1]);
        foreach ([$this->ownTab->id, $second->id] as $tabId) {
            Cell::create(['spreadsheet_id' => $this->own->id, 'sheet_tab_id' => $tabId, 'row_index' => 0, 'col_index' => 0, 'raw_value' => '  padded  ', 'updated_at' => now()]);
        }

        Livewire::actingAs($this->person)
            ->test(AiAnalysisPanel::class, ['spreadsheet_id' => $this->own->id, 'sheet_tab_id' => $this->ownTab->id])
            ->set('selected_range', 'A1')
            ->call('cleanData');

        $this->assertSame('padded', Cell::where('sheet_tab_id', $this->ownTab->id)->value('raw_value'));
        $this->assertSame('  padded  ', Cell::where('sheet_tab_id', $second->id)->value('raw_value'));
    }

    public function test_a_changed_email_address_is_not_trusted_until_proven(): void
    {
        Livewire::actingAs($this->person)->test(UpdateProfileInformationForm::class)
            ->set('state', ['name' => $this->person->name, 'email' => 'someone@elsewhere.test'])
            ->call('updateProfileInformation');

        $this->assertNull($this->person->fresh()->email_verified_at);

        // Sharing to that address grants nothing until the address is proven; an invitation waits instead.
        Livewire::actingAs($this->other)
            ->test(SpreadsheetSharingPanel::class, ['spreadsheet_id' => $this->theirs->id])
            ->set('inviteEmail', 'someone@elsewhere.test')
            ->set('invitePermission', 'edit')
            ->set('inviteExpiryDays', 7)
            ->call('inviteByEmail');

        $this->assertFalse($this->theirs->sharedUsers()->whereKey($this->person->id)->exists());
        $this->assertTrue(SpreadsheetInvitation::where('spreadsheet_id', $this->theirs->id)->where('email', 'someone@elsewhere.test')->exists());
    }

    public function test_a_proven_address_is_shared_with_at_once(): void
    {
        Livewire::actingAs($this->other)
            ->test(SpreadsheetSharingPanel::class, ['spreadsheet_id' => $this->theirs->id])
            ->set('inviteEmail', strtoupper($this->person->email))
            ->set('invitePermission', 'view')
            ->set('inviteExpiryDays', 7)
            ->call('inviteByEmail');

        $this->assertTrue($this->theirs->sharedUsers()->whereKey($this->person->id)->exists());
    }

    public function test_an_infodot_sign_in_to_an_unproven_account_leaves_the_teams_it_had_joined(): void
    {
        $team = Team::forceCreate(['user_id' => $this->other->id, 'name' => 'Their business', 'personal_team' => false]);
        $team->users()->attach($this->person->id, ['role' => 'editor']);
        $this->person->switchTeam($team);
        $this->person->forceFill(['email' => 'claimed@business.test', 'email_verified_at' => null])->save();

        $user = app(ProvisionUserFromInfoDot::class)(['user' => ['id' => 9, 'email' => 'claimed@business.test', 'email_verified' => true, 'name' => 'Real Owner']]);

        $this->assertSame($this->person->id, $user->id);
        $this->assertNotNull($user->email_verified_at);
        $this->assertFalse($user->fresh()->belongsToTeam($team));
        $this->assertSame($user->personalTeam()->id, $user->fresh()->current_team_id);
    }

    public function test_a_team_owner_reaches_the_sheets_of_the_team(): void
    {
        $team = Team::forceCreate(['user_id' => $this->other->id, 'name' => 'Business', 'personal_team' => false]);
        $team->users()->attach($this->person->id, ['role' => 'editor']);
        $sheet = Spreadsheet::create(['uuid' => (string) str()->uuid(), 'owner_id' => $this->person->id, 'team_id' => $team->id, 'name' => 'Team sheet']);

        $this->assertTrue($this->other->can('view', $sheet));
        $this->assertTrue($this->other->can('update', $sheet));
        $this->assertTrue($this->other->can('comment', $sheet));
        $this->assertFalse($this->other->can('delete', $sheet));
    }
}
