<?php

namespace Tests\Feature;

use App\Livewire\SpreadsheetSharingPanel;
use App\Models\Spreadsheet;
use App\Models\SpreadsheetInvitation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Livewire\Livewire;
use Tests\TestCase;

class GridSharingTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $person;

    private Spreadsheet $sheet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->withPersonalTeam()->create(['name' => 'Olwethu Owner']);
        $this->person = User::factory()->withPersonalTeam()->create(['name' => 'Thandi Person', 'email' => 'thandi@example.test']);
        $this->sheet = Spreadsheet::create(['uuid' => (string) str()->uuid(), 'owner_id' => $this->owner->id, 'team_id' => $this->owner->currentTeam->id, 'name' => 'Shared']);
        $this->actingAs($this->owner);
    }

    private function url(string $path = ''): string
    {
        return "/spreadsheets/{$this->sheet->id}/sharing".($path === '' ? '' : "/{$path}");
    }

    private function permissionOf(User $user): ?string
    {
        return $this->sheet->sharedUsers()->whereKey($user->id)->first()?->getRelationValue('pivot')->permission;
    }

    private function admin(): User
    {
        $admin = User::factory()->withPersonalTeam()->create(['name' => 'Ayanda Admin']);
        $this->sheet->sharedUsers()->attach($admin->id, ['permission' => 'admin']);

        return $admin;
    }

    public function test_someone_with_a_proven_address_is_shared_with_at_once(): void
    {
        $this->postJson($this->url('people'), ['email' => 'THANDI@example.test', 'permission' => 'edit', 'days' => 7])->assertOk()
            ->assertJsonPath('told', 'Shared with THANDI@example.test.')
            ->assertJsonPath('people.0.name', 'Thandi Person')
            ->assertJsonPath('people.0.permission', 'edit')
            ->assertJsonPath('waiting', []);

        $this->assertSame('edit', $this->permissionOf($this->person));
    }

    public function test_an_address_with_no_account_waits_on_a_link_and_the_panel_says_nothing_was_sent(): void
    {
        $answer = $this->postJson($this->url('people'), ['email' => 'new@example.test', 'permission' => 'comment', 'days' => 3])->assertOk()->json();

        $this->assertStringContainsString('Copy their link', $answer['told']);
        $invite = SpreadsheetInvitation::where('email', 'new@example.test')->firstOrFail();
        $this->assertSame('comment', $invite->permission);
        $this->assertTrue($invite->expires_at->between(now()->addDays(3)->subMinute(), now()->addDays(3)->addMinute()));
        $this->assertSame(route('spreadsheets.invitations.accept', $invite->token), $answer['waiting'][0]['link']);
        $this->assertFalse($answer['waiting'][0]['expired']);
    }

    public function test_an_account_that_has_not_proven_its_address_gets_an_invitation(): void
    {
        $unproven = User::factory()->withPersonalTeam()->unverified()->create(['email' => 'unproven@example.test']);

        $this->postJson($this->url('people'), ['email' => 'unproven@example.test', 'permission' => 'edit', 'days' => 7])->assertOk();

        $this->assertNull($this->permissionOf($unproven));
    }

    public function test_the_address_of_the_owner_or_the_person_asking_is_refused(): void
    {
        $this->postJson($this->url('people'), ['email' => strtoupper($this->owner->email), 'permission' => 'edit', 'days' => 7])->assertUnprocessable()
            ->assertJsonPath('message', 'Cannot share with the owner account.');

        $admin = $this->admin();
        $this->actingAs($admin);
        $this->postJson($this->url('people'), ['email' => $admin->email, 'permission' => 'view', 'days' => 7])->assertUnprocessable()
            ->assertJsonPath('message', 'You cannot change your own access. Ask the owner.');
        $this->assertSame('admin', $this->permissionOf($admin));
    }

    public function test_an_invite_must_make_sense(): void
    {
        $this->postJson($this->url('people'), ['email' => 'nonsense', 'permission' => 'view', 'days' => 7])->assertUnprocessable()
            ->assertJsonPath('message', 'That does not look like an email address.');
        $this->postJson($this->url('people'), ['email' => 'a@example.test', 'permission' => 'owner', 'days' => 7])->assertUnprocessable();
        $this->postJson($this->url('people'), ['email' => 'a@example.test', 'permission' => 'view', 'days' => 91])->assertUnprocessable();
    }

    public function test_what_someone_can_do_changes_and_they_can_be_removed(): void
    {
        $this->sheet->sharedUsers()->attach($this->person->id, ['permission' => 'view']);

        $this->patchJson($this->url("people/{$this->person->id}"), ['permission' => 'admin'])->assertOk()->assertJsonPath('people.0.permission', 'admin');
        $this->assertSame('admin', $this->permissionOf($this->person));

        $this->deleteJson($this->url("people/{$this->person->id}"))->assertOk()->assertJsonPath('people', [])->assertJsonPath('told', 'Removed Thandi Person.');
        $this->assertNull($this->permissionOf($this->person));
    }

    public function test_someone_not_shared_with_cannot_be_changed_or_removed(): void
    {
        $this->patchJson($this->url("people/{$this->person->id}"), ['permission' => 'edit'])->assertNotFound();
        $this->deleteJson($this->url("people/{$this->person->id}"))->assertNotFound();
    }

    public function test_nobody_changes_their_own_access(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);

        $this->patchJson($this->url("people/{$admin->id}"), ['permission' => 'view'])->assertUnprocessable();
        $this->deleteJson($this->url("people/{$admin->id}"))->assertUnprocessable();

        $this->assertSame('admin', $this->permissionOf($admin));
    }

    public function test_an_invitation_can_be_cancelled_only_on_its_own_sheet_and_only_while_it_waits(): void
    {
        $other = Spreadsheet::create(['uuid' => (string) str()->uuid(), 'owner_id' => $this->owner->id, 'name' => 'Other']);
        $theirs = SpreadsheetInvitation::create(['spreadsheet_id' => $other->id, 'invited_by' => $this->owner->id, 'email' => 'x@example.test', 'permission' => 'view', 'token' => 'a']);
        $accepted = SpreadsheetInvitation::create(['spreadsheet_id' => $this->sheet->id, 'invited_by' => $this->owner->id, 'email' => 'y@example.test', 'permission' => 'view', 'token' => 'b', 'accepted_at' => now()]);
        $waiting = SpreadsheetInvitation::create(['spreadsheet_id' => $this->sheet->id, 'invited_by' => $this->owner->id, 'email' => 'z@example.test', 'permission' => 'view', 'token' => 'c']);

        $this->deleteJson($this->url("invitations/{$theirs->id}"))->assertNotFound();
        $this->deleteJson($this->url("invitations/{$accepted->id}"))->assertNotFound();
        $this->deleteJson($this->url("invitations/{$waiting->id}"))->assertOk()->assertJsonPath('waiting', []);

        $this->assertNull($waiting->fresh());
        $this->assertNotNull($theirs->fresh());
    }

    public function test_the_panel_says_who_has_access_and_which_is_you(): void
    {
        $member = User::factory()->withPersonalTeam()->create();
        $this->owner->currentTeam->users()->attach($member, ['role' => 'editor']);
        $admin = $this->admin();
        $this->actingAs($admin);

        $this->getJson($this->url())->assertOk()
            ->assertJsonPath('owner.name', 'Olwethu Owner')
            ->assertJsonPath('owner.you', false)
            ->assertJsonPath('team.members', 1)
            ->assertJsonPath('people.0.you', true);
    }

    public function test_a_team_with_nobody_else_in_it_is_not_mentioned(): void
    {
        $this->getJson($this->url())->assertOk()->assertJsonPath('team', null)->assertJsonPath('owner.you', true);
    }

    public function test_the_link_goes_on_with_an_address_and_off_without_giving_it_out(): void
    {
        $answer = $this->postJson($this->url('link'), ['enabled' => true, 'expires_at' => null])->assertOk()->json();
        $token = $this->sheet->fresh()->public_token;
        $this->assertNotEmpty($token);
        $this->assertSame(route('spreadsheets.public', $token), $answer['link']['url']);
        $this->assertTrue($answer['link']['active']);
        $this->get($answer['link']['url'])->assertOk();

        $off = $this->postJson($this->url('link'), ['enabled' => false, 'expires_at' => null])->assertOk()->json();
        $this->assertNull($off['link']['url']);
        $this->assertFalse($off['link']['active']);
        $this->get($answer['link']['url'])->assertNotFound();

        $this->postJson($this->url('link'), ['enabled' => true, 'expires_at' => null])->assertOk();
        $this->assertSame($token, $this->sheet->fresh()->public_token, 'turning it back on brings the same link');
    }

    public function test_a_new_link_retires_the_old_one(): void
    {
        $this->postJson($this->url('link'), ['enabled' => true, 'expires_at' => null])->assertOk();
        $old = $this->sheet->fresh()->public_token;

        $this->postJson($this->url('link/new'))->assertOk();

        $this->assertNotSame($old, $this->sheet->fresh()->public_token);
        $this->get(route('spreadsheets.public', $old))->assertNotFound();
    }

    public function test_an_end_time_keeps_its_moment_whatever_clock_it_came_from(): void
    {
        Date::setTestNow('2026-11-04 10:00:00');

        $answer = $this->postJson($this->url('link'), ['enabled' => true, 'expires_at' => '2026-11-05T12:00:00+02:00'])->assertOk()->json();

        $this->assertTrue(Date::parse('2026-11-05T10:00:00Z')->equalTo($this->sheet->fresh()->public_expires_at));
        $this->assertTrue(Date::parse('2026-11-05T10:00:00Z')->equalTo(Date::parse($answer['link']['expires_at'])));
    }

    public function test_an_end_time_must_be_a_time_still_to_come(): void
    {
        $this->postJson($this->url('link'), ['enabled' => true, 'expires_at' => now()->subHour()->toIso8601String()])->assertUnprocessable()
            ->assertJsonPath('message', 'Choose a time that has not passed.');
        $this->postJson($this->url('link'), ['enabled' => true, 'expires_at' => 'whenever'])->assertUnprocessable()
            ->assertJsonPath('message', 'That is not a date.');
    }

    public function test_an_expired_link_says_so(): void
    {
        $this->sheet->update(['public_enabled' => true, 'public_token' => 'tok', 'public_expires_at' => now()->subDay()]);

        $this->getJson($this->url())->assertOk()->assertJsonPath('link.enabled', true)->assertJsonPath('link.active', false);
    }

    public function test_only_people_who_may_manage_sharing_get_in(): void
    {
        $this->sheet->sharedUsers()->attach($this->person->id, ['permission' => 'view']);
        $invite = SpreadsheetInvitation::create(['spreadsheet_id' => $this->sheet->id, 'invited_by' => $this->owner->id, 'email' => 'x@example.test', 'permission' => 'view', 'token' => 'kept']);

        foreach (['view', 'comment', 'edit'] as $permission) {
            $user = User::factory()->withPersonalTeam()->create();
            $this->sheet->sharedUsers()->attach($user->id, ['permission' => $permission]);
            $this->actingAs($user);

            $this->getJson($this->url())->assertForbidden();
            $this->postJson($this->url('people'), ['email' => 'a@example.test', 'permission' => 'admin', 'days' => 7])->assertForbidden();
            $this->postJson($this->url('link'), ['enabled' => true, 'expires_at' => null])->assertForbidden();
            $this->postJson($this->url('link/new'))->assertForbidden();
            $this->patchJson($this->url("people/{$user->id}"), ['permission' => 'admin'])->assertForbidden();
            $this->deleteJson($this->url("people/{$this->person->id}"))->assertForbidden();
            $this->deleteJson($this->url("invitations/{$invite->id}"))->assertForbidden();
        }

        $this->actingAs(User::factory()->withPersonalTeam()->create());
        $this->getJson($this->url())->assertForbidden();
        $this->assertFalse($this->sheet->fresh()->public_enabled);
        $this->assertSame('view', $this->permissionOf($this->person));
        $this->assertNotNull($invite->fresh());
    }

    public function test_someone_shared_as_admin_may_manage_sharing(): void
    {
        $this->actingAs($this->admin());

        $this->postJson($this->url('people'), ['email' => 'thandi@example.test', 'permission' => 'view', 'days' => 7])->assertOk();
    }

    public function test_the_page_says_who_may_share_and_passes_on_what_accepting_said(): void
    {
        $this->get("/spreadsheets/{$this->sheet->id}")->assertOk()->assertSee('id="sharing"', false)->assertViewHas('boot', fn (array $boot) => $boot['canShare'] === true);

        $invite = SpreadsheetInvitation::create(['spreadsheet_id' => $this->sheet->id, 'invited_by' => $this->owner->id, 'email' => $this->person->email, 'permission' => 'view', 'token' => 'accept-me']);
        $this->actingAs($this->person);
        $this->followingRedirects()->get(route('spreadsheets.invitations.accept', $invite->token))->assertOk()
            ->assertDontSee('id="sharing"', false)
            ->assertViewHas('boot', fn (array $boot) => $boot['canShare'] === false && str_contains((string) $boot['told'], 'Invitation accepted'));
    }

    public function test_the_classic_panel_uses_the_same_rules(): void
    {
        $this->sheet->sharedUsers()->attach($this->person->id, ['permission' => 'view']);
        $admin = $this->admin();

        $panel = Livewire::actingAs($admin)->test(SpreadsheetSharingPanel::class, ['spreadsheet_id' => $this->sheet->id]);
        $panel->call('updatePermission', $admin->id, 'view')->assertSet('statusType', 'error');
        $panel->call('revokeUser', $admin->id)->assertSet('statusType', 'error');
        $this->assertSame('admin', $this->permissionOf($admin));

        $panel->call('updatePermission', $this->person->id, 'edit')->assertSet('statusType', 'success');
        $this->assertSame('edit', $this->permissionOf($this->person));

        $panel->set('inviteEmail', 'new@example.test')->set('invitePermission', 'view')->set('inviteExpiryDays', 7)->call('inviteByEmail')
            ->assertSet('statusMessage', 'new@example.test has no account here yet. Copy their link below and send it to them.');

        $panel->set('publicExpiresAt', 'whenever')->call('savePublicLinkSettings')->assertHasErrors('publicExpiresAt');
    }
}
