<?php

namespace Tests\Feature;

use App\Models\Cell;
use App\Models\SheetTab;
use App\Models\Spreadsheet;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
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

    // ---- HTTP write paths ---------------------------------------------------------------------

    #[DataProvider('roles')]
    public function test_updating_the_sheet_over_http(string $role, bool $allowed): void
    {
        $this->actingAs($this->members[$role]);

        // There is no separate edit page: an allowed person is sent to the grid, anyone else is refused.
        $edit = $this->get(route('spreadsheets.edit', $this->sheet));
        $allowed ? $edit->assertRedirect(route('spreadsheets.show', $this->sheet)) : $edit->assertForbidden();

        $response = $this->put(route('spreadsheets.update', $this->sheet), ['name' => 'Renamed by '.$role, 'settings' => ['col_widths' => [0 => 90]]]);

        if ($allowed) {
            $response->assertRedirect();
            $this->assertSame('Renamed by '.$role, $this->sheet->fresh()->name);
        } else {
            $response->assertForbidden();
            $this->assertSame('Team sheet', $this->sheet->fresh()->name);
            $this->assertArrayNotHasKey('col_widths', $this->sheet->defaultSheetTab()->layout ?? []);
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

    // ---- Livewire: the side panels ------------------------------------------------------------

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
