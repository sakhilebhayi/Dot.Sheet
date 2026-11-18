<?php

namespace Tests\Feature\Sso;

use App\Actions\Ecosystem\ProvisionUserFromInfoDot;
use App\Mail\SsoClaimCodeMail;
use App\Models\Spreadsheet;
use App\Models\SpreadsheetInvitation;
use App\Models\Team;
use App\Models\User;
use App\Sso\SheetProvisioner;
use Dot\SsoClient\Tests\Support\FakeIdp;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** R-54 phase 2 rollout: Dot.Sheet behind DOTSSO_ENABLED. Off, nothing changes; on, every entry goes to InfoDot. */
class CentralSignInRolloutTest extends TestCase
{
    use RefreshDatabase;

    private FakeIdp $idp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->idp = (new FakeIdp)->install();
        config([
            'dotsso.provisioner' => SheetProvisioner::class,
            'dotsso.enabled' => false,
        ]);
        Mail::fake();
    }

    private function on(): void
    {
        config(['dotsso.enabled' => true]);
    }

    private function signInVia(array $claims = [], array $session = [])
    {
        $response = $this->withSession($session)->get('/sso/login')->assertRedirect();
        $authorize = FakeIdp::authorizeParams($response->headers->get('Location'));
        // The fake provider's redirect URI is the one this test app uses.
        $code = $this->idp->approve($authorize, $claims);

        return $this->get('/sso/callback?'.http_build_query(['code' => $code, 'state' => $authorize['state']]));
    }

    public function test_the_users_table_has_a_nullable_unique_infodot_user_uuid(): void
    {
        $a = User::factory()->create();
        $this->assertNull($a->infodot_user_uuid);
        User::factory()->create(); // two NULLs are allowed

        $a->forceFill(['infodot_user_uuid' => 'x'])->save();
        $this->expectException(QueryException::class);
        User::factory()->create()->forceFill(['infodot_user_uuid' => 'x'])->save();
    }

    public function test_with_the_flag_off_nothing_changes(): void
    {
        $this->get('/login')->assertOk()->assertSee('Keep me signed in');
        $this->get('/sso/login')->assertNotFound();
        $this->get('/')->assertOk()->assertSee(route('login'), false)->assertDontSee('/sso/login', false);
    }

    public function test_with_the_flag_on_the_local_pages_go_to_infodot_sign_in(): void
    {
        $this->on();

        $this->get('/login')->assertRedirect(route('dotsso.login'));
        $this->get('/register')->assertRedirect(route('dotsso.login', ['screen' => 'register']));
        $this->get('/forgot-password')->assertRedirect(route('dotsso.login'));
        $this->post('/login', ['email' => 'a@b.test', 'password' => 'x'])->assertRedirect(route('dotsso.login'));
    }

    public function test_the_signed_out_pages_link_straight_to_the_central_sign_in(): void
    {
        $this->on();

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString(route('dotsso.login'), $html);
        $this->assertStringContainsString(route('dotsso.login', ['screen' => 'register']), $html);
    }

    public function test_the_break_glass_switch_brings_the_local_login_back(): void
    {
        $this->on();
        config(['dotsso.allow_local_login' => true]);

        $this->get('/login')->assertOk()->assertSee('Keep me signed in');
    }

    public function test_a_signed_out_deep_link_survives_the_round_trip(): void
    {
        $this->on();
        $this->get('/activity')->assertRedirect('/login');
        $this->get('/login')->assertRedirect(route('dotsso.login'));

        $this->signInVia()->assertRedirect('/activity');
        $this->assertAuthenticated();
    }

    public function test_a_first_sign_in_creates_the_account_with_the_infodot_id_and_a_personal_team(): void
    {
        $this->on();

        $this->signInVia(['sub' => 'infodot-uuid-9', 'email' => 'new@example.test', 'name' => 'Nia New'])->assertRedirect('/dashboard');

        $user = User::where('email', 'new@example.test')->firstOrFail();
        $this->assertSame('infodot-uuid-9', $user->infodot_user_uuid);
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue(Team::where('user_id', $user->id)->where('personal_team', true)->exists());
        $this->assertAuthenticatedAs($user);
    }

    public function test_an_unverified_local_user_claims_with_a_code_and_keeps_data_and_password(): void
    {
        $this->on();
        $user = User::factory()->withPersonalTeam()->create(['email' => 'ada@example.test', 'email_verified_at' => null, 'password' => Hash::make('old-password-1')]);
        $team = $user->ownedTeams()->first();
        $hash = $user->password;

        $this->signInVia(['sub' => 'infodot-uuid-5'])->assertRedirect(route('dotsso.claim'));
        $this->assertGuest();

        $sent = null;
        Mail::assertSent(SsoClaimCodeMail::class, function ($m) use (&$sent) {
            $sent = $m->code;

            return $m->hasTo('ada@example.test');
        });

        $this->get('/sso/claim')->assertOk()->assertSee('Confirm it is you')->assertSee('a**@example.test', false)->assertDontSee($sent);
        $this->post('/sso/claim', ['code' => $sent])->assertRedirect('/dashboard');

        $user->refresh();
        $this->assertSame('infodot-uuid-5', $user->infodot_user_uuid);
        $this->assertSame($hash, $user->password);
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue($team->fresh()->is($user->ownedTeams()->first()), 'team membership untouched');
        $this->assertAuthenticatedAs($user);
    }

    public function test_the_claim_page_is_one_form_with_labelled_code_field_and_real_buttons(): void
    {
        $this->on();
        User::factory()->create(['email' => 'ada@example.test', 'email_verified_at' => null]);
        $this->signInVia()->assertRedirect(route('dotsso.claim'));

        $html = $this->get('/sso/claim')->assertOk()->getContent();

        $this->assertStringContainsString('autocomplete="one-time-code"', $html);
        $this->assertStringContainsString('inputmode="numeric"', $html);
        $this->assertMatchesRegularExpression('/<label[^>]+for="code"/', $html);
        $this->assertStringContainsString('role="alert"', $html);
        $this->assertSame(1, substr_count($html, '<h1'));
    }

    public function test_after_an_infodot_sign_out_a_real_auth_sanctum_page_ends_the_session_instead_of_a_500(): void
    {
        $this->on();
        $this->signInVia(['sub' => 'infodot-uuid-3', 'email' => 'out@example.test'])->assertRedirect('/dashboard');
        $this->get('/dashboard')->assertOk();

        $this->travel(1)->seconds();
        DB::table('dotsso_revocations')->insert(['sub' => 'infodot-uuid-3', 'revoked_at_ms' => (int) floor(microtime(true) * 1000), 'created_at' => now(), 'updated_at' => now()]);

        $this->get('/dashboard')->assertRedirect('/');
        $this->assertGuest('web');
    }

    public function test_sanctum_api_access_is_untouched_while_sso_is_on(): void
    {
        $this->on();
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        $this->getJson('/api/user')->assertOk()->assertJsonPath('email', $user->email);
    }

    public function test_a_real_sanctum_bearer_token_works_while_sso_is_on(): void
    {
        $this->on();
        $user = User::factory()->create();
        $token = $user->createToken('t')->plainTextToken;

        $this->getJson('/api/user')->assertUnauthorized();
        $this->withHeader('Authorization', 'Bearer '.$token)->getJson('/api/user')->assertOk();
    }

    public function test_the_health_and_fallback_receiver_routes_are_unaffected(): void
    {
        $this->on();

        $this->get('/up')->assertOk();
        $this->get('/auth/ecosystem')->assertRedirect('/login');
    }

    public function test_the_claim_flow_keeps_team_memberships_and_spreadsheet_shares(): void
    {
        $this->on();
        $owner = User::factory()->withPersonalTeam()->create();
        $shared = Team::forceCreate(['user_id' => $owner->id, 'name' => 'Finance team', 'personal_team' => false]);
        $sheet = Spreadsheet::create(['uuid' => (string) Str::uuid(), 'owner_id' => $owner->id, 'team_id' => $shared->id, 'name' => 'Budget']);
        $person = User::factory()->withPersonalTeam()->create(['email' => 'ada@example.test', 'email_verified_at' => null]);
        $shared->users()->attach($person, ['role' => 'editor']);
        $sheet->sharedUsers()->attach($person->id, ['permission' => 'edit']);
        $person->switchTeam($shared);

        $this->signInVia(['sub' => 'infodot-uuid-5'])->assertRedirect(route('dotsso.claim'));
        $code = null;
        Mail::assertSent(SsoClaimCodeMail::class, function ($m) use (&$code) {
            $code = $m->code;

            return true;
        });
        $this->post('/sso/claim', ['code' => $code])->assertRedirect();

        $person->refresh();
        $this->assertTrue($person->belongsToTeam($shared));
        $this->assertSame($shared->id, $person->current_team_id);
        $this->assertTrue($sheet->sharedUsers()->where('users.id', $person->id)->exists());
        $this->assertAuthenticatedAs($person);
    }

    public function test_a_spreadsheet_invitation_link_survives_sign_in_and_the_invited_email_still_has_to_match(): void
    {
        $this->on();
        $owner = User::factory()->withPersonalTeam()->create();
        $sheet = Spreadsheet::create(['uuid' => (string) Str::uuid(), 'owner_id' => $owner->id, 'team_id' => $owner->currentTeam->id, 'name' => 'Shared plan']);
        $invite = SpreadsheetInvitation::create(['spreadsheet_id' => $sheet->id, 'email' => 'guest@example.test', 'permission' => 'edit', 'token' => 'tok-123', 'invited_by' => $owner->id, 'expires_at' => now()->addDay()]);
        $path = '/spreadsheets/invitations/tok-123/accept';

        $this->get($path)->assertRedirect('/login');
        $this->get('/login')->assertRedirect(route('dotsso.login'));
        $this->signInVia(['sub' => 'infodot-uuid-g', 'email' => 'guest@example.test'])->assertRedirect($path);

        $this->get($path)->assertRedirect(route('spreadsheets.show', $sheet));
        $this->assertTrue($sheet->sharedUsers()->where('users.email', 'guest@example.test')->exists());
        $this->assertNotNull($invite->fresh()->accepted_at);
    }

    public function test_an_invitation_opened_by_the_wrong_email_is_refused_in_words_not_accepted(): void
    {
        $this->on();
        $owner = User::factory()->withPersonalTeam()->create();
        $sheet = Spreadsheet::create(['uuid' => (string) Str::uuid(), 'owner_id' => $owner->id, 'team_id' => $owner->currentTeam->id, 'name' => 'Shared plan']);
        $invite = SpreadsheetInvitation::create(['spreadsheet_id' => $sheet->id, 'email' => 'guest@example.test', 'permission' => 'edit', 'token' => 'tok-456', 'invited_by' => $owner->id, 'expires_at' => now()->addDay()]);

        $this->signInVia(['sub' => 'infodot-uuid-x', 'email' => 'someone-else@example.test']);
        $this->get('/spreadsheets/invitations/tok-456/accept')->assertRedirect(route('spreadsheets.index'));

        $this->assertNull($invite->fresh()->accepted_at);
        $this->assertFalse($sheet->sharedUsers()->where('users.email', 'someone-else@example.test')->exists());
    }

    public function test_a_team_invitation_link_is_an_allowed_deep_link(): void
    {
        $this->on();
        $url = '/team-invitations/5?expires=1&signature=abc';

        $this->get($url)->assertRedirect('/login');
        $this->signInVia(['sub' => 'infodot-uuid-t', 'email' => 'team@example.test'])->assertRedirect($url);
    }

    public function test_the_public_share_link_and_the_machine_doors_need_no_sign_in_while_sso_is_on(): void
    {
        $this->on();
        $owner = User::factory()->withPersonalTeam()->create();
        $sheet = Spreadsheet::create(['uuid' => (string) Str::uuid(), 'owner_id' => $owner->id, 'team_id' => $owner->currentTeam->id, 'name' => 'Public one', 'public_enabled' => true, 'public_token' => 'pub-tok']);

        $this->get('/shared/s/pub-tok')->assertOk();
        $this->get('/csp-report')->assertStatus(405);
        $this->get('/up')->assertOk();
        $this->getJson('/api/user')->assertUnauthorized();
    }

    public function test_an_unverified_account_is_never_replaced_by_the_fallback_receiver_either(): void
    {
        $this->assertFalse(method_exists(ProvisionUserFromInfoDot::class, 'adopt'));
        $this->assertStringNotContainsString('->detach(', file_get_contents(app_path('Actions/Ecosystem/ProvisionUserFromInfoDot.php')));
    }
}
