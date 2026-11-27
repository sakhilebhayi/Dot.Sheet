<?php

namespace Tests\Feature;

use App\Livewire\Profile\LogoutOtherBrowserSessionsForm;
use App\Models\Cell;
use App\Models\CellHistory;
use App\Models\Comment;
use App\Models\Spreadsheet;
use App\Models\User;
use App\Support\CentralReconfirm;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * EA-1 (S-02, F-03, F-04): deleting an account keeps what belongs to others, and people who sign in through
 * InfoDot (no password here) can delete their account and end their other sessions after a recent InfoDot sign-in.
 */
class AccountDeletionTest extends TestCase
{
    use RefreshDatabase;

    private function workbook(User $owner, ?int $teamId, string $name): Spreadsheet
    {
        return Spreadsheet::create(['uuid' => (string) Str::uuid(), 'owner_id' => $owner->id, 'team_id' => $teamId, 'name' => $name]);
    }

    private function signedInThroughInfoDot(User $user, int $minutesAgo): void
    {
        $this->actingAs($user);
        $this->app['request']->setLaravelSession($this->app['session.store']);
        session(['dotsso.session' => ['sub' => 'sub-'.$user->id, 'at_ms' => (int) floor(microtime(true) * 1000) - $minutesAgo * 60_000, 'id_token' => 'x']]);
    }

    public function test_deleting_a_member_keeps_the_company_workbooks_and_their_shared_comments_and_history(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $team = $owner->currentTeam;
        $member = User::factory()->withPersonalTeam()->create();
        $team->users()->attach($member, ['role' => 'editor']);

        $companyBook = $this->workbook($member, $team->id, 'Company plan');
        $privateBook = $this->workbook($member, null, 'Private notes');
        $colleagueBook = $this->workbook($owner, $team->id, 'Colleague sheet');
        $tab = $colleagueBook->sheetTabs()->first();
        $comment = Comment::create(['spreadsheet_id' => $colleagueBook->id, 'sheet_tab_id' => $tab->id, 'user_id' => $member->id, 'row_index' => 0, 'col_index' => 0, 'content' => 'Checked', 'resolved' => false]);
        $cell = Cell::create(['spreadsheet_id' => $colleagueBook->id, 'sheet_tab_id' => $tab->id, 'row_index' => 0, 'col_index' => 0, 'raw_value' => '5', 'updated_at' => now()]);
        $history = CellHistory::create(['cell_id' => $cell->id, 'user_id' => $member->id, 'old_value' => null, 'new_value' => '5', 'created_at' => now()]);

        $this->actingAs($member);
        Livewire::test('profile.delete-user-form')->set('password', 'password')->call('deleteUser');

        $this->assertNull($member->fresh());
        $this->assertSame($owner->id, $companyBook->fresh()?->owner_id, 'the company keeps the workbook');
        $this->assertNull($privateBook->fresh(), 'their own private workbook goes with the account');
        $this->assertNotNull($comment->fresh());
        $this->assertNull($comment->fresh()->user_id);
        $this->assertNotNull($history->fresh());
        $this->assertNull($history->fresh()->user_id);
    }

    public function test_a_company_owner_deleting_their_account_deletes_the_company_and_its_workbooks(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $book = $this->workbook($owner, $owner->currentTeam->id, 'Owned');

        $this->actingAs($owner);
        Livewire::test('profile.delete-user-form')->set('password', 'password')->call('deleteUser');

        $this->assertNull($book->fresh());
    }

    public function test_an_infodot_user_deletes_with_a_recent_sign_in_and_their_email(): void
    {
        $user = User::factory()->withPersonalTeam()->create(['email' => 'pat@acme.test']);
        $this->signedInThroughInfoDot($user, 2);

        Livewire::test('profile.delete-user-form')->set('confirmation', ' PAT@acme.test ')->call('deleteUser')->assertHasNoErrors();

        $this->assertNull($user->fresh());
    }

    public function test_an_infodot_user_must_type_their_own_email(): void
    {
        $user = User::factory()->withPersonalTeam()->create(['email' => 'pat@acme.test']);
        $this->signedInThroughInfoDot($user, 2);

        Livewire::test('profile.delete-user-form')->set('confirmation', 'someone@else.test')->call('deleteUser')->assertHasErrors(['confirmation']);

        $this->assertNotNull($user->fresh());
    }

    public function test_an_old_infodot_sign_in_is_not_enough(): void
    {
        $user = User::factory()->withPersonalTeam()->create(['email' => 'pat@acme.test']);
        $this->signedInThroughInfoDot($user, CentralReconfirm::WINDOW_MINUTES + 1);

        Livewire::test('profile.delete-user-form')
            ->assertSee('Sign in again through InfoDot')
            ->set('confirmation', 'pat@acme.test')->call('deleteUser')->assertHasErrors(['confirmation']);

        $this->assertNotNull($user->fresh());
    }

    public function test_a_password_is_still_required_for_a_local_sign_in(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $this->actingAs($user);

        Livewire::test('profile.delete-user-form')->set('password', 'wrong')->call('deleteUser')->assertHasErrors(['password']);
        Livewire::test('profile.delete-user-form')->set('confirmation', $user->email)->call('deleteUser')->assertHasErrors(['password']);

        $this->assertNotNull($user->fresh());
    }

    public function test_sign_in_again_ends_the_session_and_returns_to_the_profile_through_infodot(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $this->signedInThroughInfoDot($user, 30);

        $this->post(route('profile.reconfirm'))->assertRedirect(route('dotsso.login'));

        $this->assertGuest();
        $this->assertSame(route('profile.show', absolute: false), session('url.intended'));
    }

    public function test_an_old_infodot_sign_in_cannot_end_other_sessions(): void
    {
        config(['session.driver' => 'database']);
        $user = User::factory()->withPersonalTeam()->create();
        $this->signedInThroughInfoDot($user, CentralReconfirm::WINDOW_MINUTES + 1);

        $this->expectException(ValidationException::class);
        app(LogoutOtherBrowserSessionsForm::class)->logoutOtherBrowserSessions(auth()->guard('web'));
    }

    public function test_a_recent_infodot_sign_in_ends_other_sessions_without_a_password(): void
    {
        config(['session.driver' => 'database']);
        $user = User::factory()->withPersonalTeam()->create();
        \DB::table('sessions')->insert(['id' => 'other-session', 'user_id' => $user->id, 'ip_address' => '127.0.0.1', 'user_agent' => 'x', 'payload' => '', 'last_activity' => time()]);
        $this->signedInThroughInfoDot($user, 1);

        // Called directly: Jetstream's sessions list needs a request session that Livewire's test requests lack.
        app(LogoutOtherBrowserSessionsForm::class)->logoutOtherBrowserSessions(auth()->guard('web'));

        $this->assertFalse(\DB::table('sessions')->where('id', 'other-session')->exists());
    }

    public function test_the_profile_points_infodot_users_to_infodot_for_password_and_two_step_sign_in(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        $this->actingAs($user)->withSession(['dotsso.session' => ['sub' => 's', 'at_ms' => (int) floor(microtime(true) * 1000), 'id_token' => 'x']])
            ->get(route('profile.show'))->assertOk()
            ->assertSee('managed in your InfoDot account')
            ->assertDontSee('current_password');

        session()->forget('dotsso.session');
        $this->actingAs($user)->get(route('profile.show'))->assertOk()->assertDontSee('managed in your InfoDot account');
    }
}
