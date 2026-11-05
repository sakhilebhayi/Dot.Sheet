<?php

namespace Tests\Feature;

use App\Livewire\Concerns\RefusesProtectedWorkbook;
use App\Models\Cell;
use App\Models\SheetTab;
use App\Models\Spreadsheet;
use App\Models\SpreadsheetVersion;
use App\Models\User;
use App\Models\WorkbookApproval;
use App\Notifications\ApprovalNeeded;
use App\Services\CellWriter;
use App\Services\ProtectionRefused;
use App\Services\SheetProtections;
use App\Services\StructureVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Route as RouteItem;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class GridApprovalTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $editor;

    private User $admin;

    private Spreadsheet $sheet;

    private SheetTab $tab;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->withPersonalTeam()->create();
        $this->sheet = Spreadsheet::create(['uuid' => (string) Str::uuid(), 'owner_id' => $this->owner->id, 'name' => 'Budget']);
        $this->tab = $this->sheet->sheetTabs()->firstOrFail();
        $this->editor = User::factory()->withPersonalTeam()->create();
        $this->admin = User::factory()->withPersonalTeam()->create();
        $this->sheet->sharedUsers()->attach($this->editor->id, ['permission' => 'edit']);
        $this->sheet->sharedUsers()->attach($this->admin->id, ['permission' => 'admin']);
    }

    private function step(User $user, string $step, array $body = []): TestResponse
    {
        return $this->actingAs($user)->postJson("/spreadsheets/{$this->sheet->id}/approval/{$step}", $body);
    }

    private function approved(): void
    {
        $this->step($this->editor, 'ask')->assertOk();
        $this->step($this->admin, 'decide', ['approve' => true])->assertOk();
    }

    private function edit(User $user): TestResponse
    {
        return $this->actingAs($user)->postJson("/spreadsheets/{$this->sheet->id}/tabs/{$this->tab->id}/cells", [
            'version' => StructureVersion::current($this->sheet->id),
            'edits' => [['row' => 1, 'col' => 1, 'value' => 'changed']],
        ]);
    }

    public function test_asking_tells_every_keeper_but_not_the_person_asking(): void
    {
        Notification::fake();

        $this->step($this->admin, 'ask', ['note' => 'Quarter end'])->assertOk()->assertJsonPath('state', 'waiting')->assertJsonPath('yours', true);

        Notification::assertSentTo($this->owner, ApprovalNeeded::class);
        Notification::assertNotSentTo($this->admin, ApprovalNeeded::class);
        Notification::assertNotSentTo($this->editor, ApprovalNeeded::class);
    }

    public function test_a_person_who_can_only_read_cannot_ask(): void
    {
        $reader = User::factory()->withPersonalTeam()->create();
        $this->sheet->sharedUsers()->attach($reader->id, ['permission' => 'view']);

        $this->step($reader, 'ask')->assertForbidden();
        $this->actingAs($reader)->getJson("/spreadsheets/{$this->sheet->id}/approval")->assertOk()->assertJsonPath('state', null);
    }

    public function test_nobody_decides_their_own_request(): void
    {
        $this->step($this->admin, 'ask')->assertOk();

        $this->step($this->admin, 'decide', ['approve' => true])->assertStatus(422)->assertJsonPath('message', 'Someone else decides on a request you made.');
        $this->assertSame(1, WorkbookApproval::count());
    }

    public function test_an_editor_cannot_decide(): void
    {
        $this->step($this->admin, 'ask')->assertOk();

        $this->step($this->editor, 'decide', ['approve' => true])->assertForbidden();
        $this->step($this->editor, 'reopen')->assertForbidden();
    }

    public function test_an_owner_alone_is_told_nobody_else_can_decide(): void
    {
        $this->sheet->sharedUsers()->detach();

        $this->step($this->owner, 'ask')->assertStatus(422)->assertJsonPath('message', 'Nobody else can decide. Give someone the right to edit and share first.');
        $this->assertSame(0, WorkbookApproval::count());
    }

    public function test_there_is_one_request_at_a_time(): void
    {
        $this->step($this->editor, 'ask')->assertOk();
        $this->step($this->admin, 'ask')->assertStatus(422)->assertJsonPath('message', 'This workbook is already waiting for approval.');

        $this->step($this->admin, 'decide', ['approve' => true])->assertOk();
        $this->step($this->admin, 'ask')->assertStatus(422)->assertJsonPath('message', 'This workbook is already approved. Reopen it first.');
    }

    public function test_a_sent_back_workbook_stays_open_and_can_be_asked_for_again(): void
    {
        $this->step($this->editor, 'ask')->assertOk();
        $this->step($this->admin, 'decide', ['approve' => false, 'note' => 'Fix the total'])->assertOk()->assertJsonPath('state', 'returned')->assertJsonPath('steps.0.note', 'Fix the total')->assertJsonPath('steps.0.who', $this->admin->name);

        $this->edit($this->editor)->assertOk();
        $this->step($this->editor, 'ask')->assertOk()->assertJsonPath('state', 'waiting');
    }

    public function test_an_approved_workbook_keeps_a_copy_of_every_sheet(): void
    {
        $second = $this->sheet->sheetTabs()->create(['name' => 'Second', 'sort_order' => 1]);

        $this->approved();

        foreach ([$this->tab->id, $second->id] as $tabId) {
            $this->assertSame(1, SpreadsheetVersion::where('sheet_tab_id', $tabId)->where('label', 'Approved by '.$this->admin->name)->count());
        }
    }

    public function test_sending_back_keeps_no_copy(): void
    {
        $this->step($this->editor, 'ask')->assertOk();
        $this->step($this->admin, 'decide', ['approve' => false])->assertOk();

        $this->assertSame(0, SpreadsheetVersion::count());
    }

    public function test_an_approved_workbook_is_sealed_for_keepers_too_until_it_is_reopened(): void
    {
        $this->approved();

        foreach ([$this->owner, $this->admin, $this->editor] as $user) {
            $this->edit($user)->assertStatus(422)->assertJsonPath('message', SheetProtections::SEALED_MESSAGE);
        }
        $this->assertSame(0, Cell::where('sheet_tab_id', $this->tab->id)->count());

        $this->step($this->owner, 'reopen')->assertOk()->assertJsonPath('state', 'reopened');
        $this->edit($this->editor)->assertOk();
    }

    public function test_the_sheet_says_it_is_sealed(): void
    {
        $this->approved();

        $this->actingAs($this->editor)->getJson("/spreadsheets/{$this->sheet->id}/tabs/{$this->tab->id}/sheet")->assertOk()->assertJsonPath('protection.sealed', true);
    }

    public function test_every_change_through_a_sheet_is_refused_when_approved_unless_it_is_named(): void
    {
        $allowed = ['grid.comments.', 'grid.copies.store', 'grid.ask', 'grid.protections.'];
        $this->approved();

        $routes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn (RouteItem $route) => str_contains($route->uri(), 'tabs/{sheetTab}/') && array_diff($route->methods(), ['GET', 'HEAD', 'OPTIONS']) !== [])
            ->reject(fn (RouteItem $route) => Str::startsWith((string) $route->getName(), $allowed))
            // Another record in the address would have to exist first.
            ->reject(fn (RouteItem $route) => preg_match('/\{(?!spreadsheet|sheetTab)/', $route->uri()) === 1);
        $this->assertGreaterThan(20, $routes->count());

        foreach ($routes as $route) {
            $uri = str_replace(['{spreadsheet}', '{sheetTab}'], [$this->sheet->id, $this->tab->id], $route->uri());
            $method = array_values(array_diff($route->methods(), ['GET', 'HEAD', 'OPTIONS']))[0];

            $this->actingAs($this->owner)->json($method, '/'.$uri, [])->assertStatus(422)->assertJsonPath('message', SheetProtections::SEALED_MESSAGE);
        }
    }

    public function test_talking_about_an_approved_workbook_and_keeping_a_copy_still_work(): void
    {
        $this->approved();

        $this->actingAs($this->editor)->postJson("/spreadsheets/{$this->sheet->id}/tabs/{$this->tab->id}/comments", ['row' => 1, 'col' => 1, 'text' => 'Looks right'])->assertSuccessful();
        $this->actingAs($this->editor)->postJson("/spreadsheets/{$this->sheet->id}/tabs/{$this->tab->id}/copies", ['label' => 'Mine'])->assertSuccessful();
    }

    public function test_changes_to_the_workbook_itself_are_refused_when_approved(): void
    {
        $this->approved();
        $base = "/spreadsheets/{$this->sheet->id}";

        $this->actingAs($this->owner)->postJson("{$base}/tabs", ['name' => 'New'])->assertStatus(422);
        $this->actingAs($this->owner)->postJson("{$base}/tabs/order", ['order' => [$this->tab->id]])->assertStatus(422);
        $this->actingAs($this->owner)->postJson("{$base}/macros", ['name' => 'M', 'steps' => []])->assertStatus(422);
        $this->actingAs($this->owner)->postJson("{$base}/import", ['file' => UploadedFile::fake()->createWithContent('a.csv', "a,b\n1,2\n")])
            ->assertStatus(422)->assertJsonPath('message', SheetProtections::SEALED_MESSAGE);
        $this->actingAs($this->owner)->putJson($base, ['name' => 'Renamed'])->assertStatus(422);

        $this->assertSame('Budget', $this->sheet->fresh()->name);
        $this->assertSame(1, $this->sheet->sheetTabs()->count());
    }

    public function test_the_commit_itself_refuses_so_a_seal_landing_mid_request_still_holds(): void
    {
        $this->approved();
        $protections = app(SheetProtections::class);

        foreach ([
            fn () => $protections->refuseChange($this->sheet, [], [], $this->owner),
            fn () => $protections->refuseDelete($this->sheet, $this->tab->id, 'row', 0, 1, $this->owner),
        ] as $change) {
            try {
                $change();
                $this->fail('An approved workbook took a change.');
            } catch (ProtectionRefused $refused) {
                $this->assertSame(SheetProtections::SEALED_MESSAGE, $refused->getMessage());
            }
        }
    }

    public function test_the_classic_editor_stays_shut_on_an_approved_workbook_for_a_keeper_too(): void
    {
        $this->approved();

        $this->assertTrue($this->shutOutOfClassic($this->owner));

        $this->step($this->admin, 'reopen')->assertOk();
        $this->assertFalse($this->shutOutOfClassic($this->owner));
    }

    public function test_whoever_asked_can_take_it_back_but_another_editor_cannot(): void
    {
        $other = User::factory()->withPersonalTeam()->create();
        $this->sheet->sharedUsers()->attach($other->id, ['permission' => 'edit']);
        $this->step($this->editor, 'ask')->assertOk();

        $this->step($other, 'withdraw')->assertStatus(422);
        $this->step($this->editor, 'withdraw')->assertOk()->assertJsonPath('state', 'withdrawn');
        $this->step($this->editor, 'ask')->assertOk();
        $this->step($this->admin, 'withdraw')->assertOk();
    }

    public function test_reopening_needs_an_approved_workbook(): void
    {
        $this->step($this->owner, 'reopen')->assertStatus(422);
    }

    public function test_the_steps_stay_on_record_newest_first(): void
    {
        $this->approved();
        $this->step($this->owner, 'reopen', ['note' => 'One more line'])->assertOk()
            ->assertJsonPath('steps.0.state', 'reopened')->assertJsonPath('steps.1.state', 'approved')->assertJsonPath('steps.2.state', 'waiting')
            ->assertJsonPath('steps.2.who', $this->editor->name);
    }

    public function test_a_person_with_no_access_sees_nothing(): void
    {
        $stranger = User::factory()->withPersonalTeam()->create();

        $this->actingAs($stranger)->getJson("/spreadsheets/{$this->sheet->id}/approval")->assertForbidden();
        $this->step($stranger, 'ask')->assertForbidden();
    }

    public function test_the_workbook_can_be_given_to_a_team_and_taken_back(): void
    {
        $team = $this->owner->currentTeam;
        $base = "/spreadsheets/{$this->sheet->id}/sharing";

        $this->actingAs($this->owner)->postJson("{$base}/place", ['team_id' => $team->id])->assertOk()->assertJsonPath('place.team_id', $team->id);
        $this->assertSame($team->id, $this->sheet->fresh()->team_id);

        $this->actingAs($this->owner)->postJson("{$base}/template", ['on' => true])->assertOk()->assertJsonPath('template', true);
        $this->actingAs($this->owner)->postJson("{$base}/place", ['team_id' => null])->assertOk();

        $fresh = $this->sheet->fresh();
        $this->assertNull($fresh->team_id);
        $this->assertFalse($fresh->is_template, 'A template is offered to a team, so a private workbook is not one.');
    }

    public function test_an_admin_on_the_workbook_cannot_move_it(): void
    {
        $this->actingAs($this->admin)->postJson("/spreadsheets/{$this->sheet->id}/sharing/place", ['team_id' => $this->admin->currentTeam->id])
            ->assertStatus(422)->assertJsonPath('message', 'Only the owner can move a workbook.');
    }

    public function test_a_workbook_cannot_go_to_a_team_the_owner_is_not_in(): void
    {
        $this->actingAs($this->owner)->postJson("/spreadsheets/{$this->sheet->id}/sharing/place", ['team_id' => $this->editor->currentTeam->id])
            ->assertStatus(422)->assertJsonPath('message', 'You cannot add a workbook to that team.');
        $this->assertNull($this->sheet->fresh()->team_id);
    }

    public function test_a_viewer_in_a_team_cannot_give_it_a_workbook(): void
    {
        $team = $this->editor->currentTeam;
        $team->users()->attach($this->owner, ['role' => 'viewer']);

        $this->actingAs($this->owner)->postJson("/spreadsheets/{$this->sheet->id}/sharing/place", ['team_id' => $team->id])->assertStatus(422);
    }

    public function test_the_panel_lists_only_teams_this_person_can_add_to(): void
    {
        $viewerOf = $this->editor->currentTeam;
        $viewerOf->users()->attach($this->owner, ['role' => 'viewer']);

        $teams = $this->actingAs($this->owner)->getJson("/spreadsheets/{$this->sheet->id}/sharing")->assertOk()->json('place.teams');

        $this->assertSame([$this->owner->currentTeam->id], array_column($teams, 'id'));
    }

    public function test_a_private_workbook_cannot_be_a_template(): void
    {
        $this->actingAs($this->owner)->postJson("/spreadsheets/{$this->sheet->id}/sharing/template", ['on' => true])
            ->assertStatus(422)->assertJsonPath('message', 'Move this to a team first. A template is offered to a team.');
    }

    public function test_an_editor_cannot_offer_a_template(): void
    {
        $this->actingAs($this->editor)->postJson("/spreadsheets/{$this->sheet->id}/sharing/template", ['on' => true])->assertForbidden();
    }

    public function test_a_team_template_is_offered_to_the_team_and_only_the_team(): void
    {
        $team = $this->owner->currentTeam;
        $this->sheet->update(['team_id' => $team->id, 'is_template' => true]);
        $mate = User::factory()->withPersonalTeam()->create();
        $team->users()->attach($mate, ['role' => 'editor']);

        $this->actingAs($mate)->get('/spreadsheets/create')->assertOk()->assertSee("Start from your team's templates", false)->assertSee('Budget');
        $this->actingAs($this->editor)->get('/spreadsheets/create')->assertOk()->assertDontSee("your team's templates", false);
    }

    public function test_starting_from_a_team_template_copies_the_work_and_leaves_the_template_alone(): void
    {
        $team = $this->owner->currentTeam;
        $this->sheet->update(['team_id' => $team->id, 'is_template' => true]);
        app(CellWriter::class)->write($this->sheet->id, $this->tab->id, [['row' => 1, 'col' => 1, 'value' => 'Item']], $this->owner->id);
        $mate = User::factory()->withPersonalTeam()->create();
        $team->users()->attach($mate, ['role' => 'editor']);

        $this->actingAs($mate)->post('/spreadsheets/from-team-template', ['source' => $this->sheet->id, 'name' => ''])->assertRedirect();

        $made = Spreadsheet::where('owner_id', $mate->id)->firstOrFail();
        $this->assertSame('Budget', $made->name);
        $this->assertSame($team->id, $made->team_id);
        $this->assertFalse($made->is_template);
        $this->assertSame(1, Cell::where('spreadsheet_id', $made->id)->count());
        $this->assertSame(1, Cell::where('spreadsheet_id', $this->sheet->id)->count());
    }

    public function test_a_workbook_that_is_not_offered_cannot_be_started_from_by_this_route(): void
    {
        $team = $this->owner->currentTeam;
        $this->sheet->update(['team_id' => $team->id, 'is_template' => false]);
        $mate = User::factory()->withPersonalTeam()->create();
        $team->users()->attach($mate, ['role' => 'editor']);
        $this->actingAs($mate)->post('/spreadsheets/from-team-template', ['source' => $this->sheet->id])->assertNotFound();

        $this->sheet->update(['is_template' => true]);
        $this->actingAs($this->editor)->post('/spreadsheets/from-team-template', ['source' => $this->sheet->id])->assertNotFound();
    }

    private function shutOutOfClassic(User $user): bool
    {
        $this->actingAs($user);

        return (new class
        {
            use RefusesProtectedWorkbook;
        })::protectedFor($this->sheet);
    }
}
