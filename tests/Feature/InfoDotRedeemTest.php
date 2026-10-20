<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\PersonalAccessToken;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Production gives every Dot app its own database, so InfoDot's handoff token is
 * not in ours. The app asks InfoDot to prove it, then matches or creates the user.
 */
class InfoDotRedeemTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://infodot.test/api/ecosystem/redeem';

    protected function setUp(): void
    {
        parent::setUp();

        config(['ecosystem.infodot' => [
            'redeem_url' => self::URL,
            'platform' => 'sheet',
            'secret' => 'service-secret-for-tests',
            'timeout' => 2,
            'provision' => true,
        ]]);
    }

    private function identity(array $user = [], array $extra = []): array
    {
        return array_merge([
            'user' => array_merge(['id' => 7, 'name' => 'Amara Dlamini', 'email' => 'Amara@Example.test', 'email_verified' => true], $user),
            'teams' => [['id' => 3, 'uuid' => '33333333-3333-4333-8333-333333333333', 'name' => 'Amara Team', 'personal' => true, 'role' => 'owner']],
            'current_team_id' => 3,
            'current_team_uuid' => null,
        ], $extra);
    }

    /** A token in this app's own database, the way InfoDot mints it when the database is shared. */
    private function localToken(User $user, array $abilities = ['ecosystem:read'], $expiresAt = null): string
    {
        // Inserted directly: this app's token table may not have the expires_at column
        // that createToken() always writes.
        $plain = bin2hex(random_bytes(20));
        $attributes = [
            'tokenable_type' => User::class,
            'tokenable_id' => $user->id,
            'name' => 'handoff',
            'token' => hash('sha256', $plain),
            'abilities' => $abilities,
        ];

        if ($expiresAt && Schema::hasColumn('personal_access_tokens', 'expires_at')) {
            $attributes['expires_at'] = $expiresAt;
        }

        return PersonalAccessToken::forceCreate($attributes)->id.'|'.$plain;
    }

    public function test_a_person_vouched_for_by_infodot_gets_an_account_and_is_signed_in(): void
    {
        Http::fake([self::URL => Http::response($this->identity())]);

        $this->get(route('auth.ecosystem', ['token' => 'one-time-token']))->assertRedirect(route('dashboard'));

        $this->assertAuthenticated();
        $user = User::where('email', 'amara@example.test')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertSame('Amara Dlamini', $user->name);
        $this->assertNotNull($user->email_verified_at);
        $this->assertCount(1, $user->ownedTeams);
        $this->assertTrue($user->ownedTeams->first()->personal_team);
        $this->assertSame($user->ownedTeams->first()->id, $user->current_team_id);
    }

    public function test_the_provisioned_password_is_random_and_unusable(): void
    {
        Http::fake([self::URL => Http::response($this->identity())]);

        $this->get(route('auth.ecosystem', ['token' => 'one-time-token']));

        $user = User::where('email', 'amara@example.test')->firstOrFail();
        $this->assertNotEmpty($user->password);
        $this->assertFalse(Hash::check('', $user->password));
        $this->assertFalse(Hash::check('password', $user->password));
    }

    public function test_the_secret_and_platform_go_in_the_request_and_the_token_never_in_the_url(): void
    {
        Http::fake([self::URL => Http::response($this->identity())]);

        $this->get(route('auth.ecosystem', ['token' => 'one-time-token']));

        Http::assertSent(function (Request $request) {
            return $request->url() === self::URL
                && $request->method() === 'POST'
                && $request->hasHeader('Authorization', 'Bearer service-secret-for-tests')
                && $request['token'] === 'one-time-token'
                && $request['platform'] === 'sheet';
        });
    }

    public function test_an_existing_account_is_matched_by_email_without_a_duplicate(): void
    {
        $existing = User::factory()->withPersonalTeam()->create(['email' => 'amara@example.test', 'name' => 'Local Name']);
        Http::fake([self::URL => Http::response($this->identity())]);

        $this->get(route('auth.ecosystem', ['token' => 't']));

        $this->assertAuthenticatedAs($existing);
        $this->assertSame(1, User::where('email', 'amara@example.test')->count());
        $this->assertSame('Local Name', $existing->fresh()->name);
    }

    public function test_an_unverified_local_account_is_adopted_and_its_password_replaced(): void
    {
        $squatter = User::factory()->create([
            'email' => 'amara@example.test',
            'email_verified_at' => null,
            'password' => Hash::make('attacker-knows-this'),
        ]);
        Http::fake([self::URL => Http::response($this->identity())]);

        $this->get(route('auth.ecosystem', ['token' => 't']));

        $this->assertAuthenticatedAs($squatter);
        $this->assertFalse(Hash::check('attacker-knows-this', $squatter->fresh()->password));
        $this->assertNotNull($squatter->fresh()->email_verified_at);
    }

    public function test_a_token_infodot_refuses_signs_nobody_in(): void
    {
        Http::fake([self::URL => Http::response(['error' => 'invalid_request'], 403)]);

        $this->get(route('auth.ecosystem', ['token' => 'bad']))->assertStatus(303)->assertRedirect('/login');

        $this->assertGuest();
        $this->assertSame(0, User::count());
    }

    public function test_a_wrong_service_secret_or_platform_is_refused_not_signed_in(): void
    {
        // 401: this app's secret is not the one InfoDot holds. 422: InfoDot does not serve this platform.
        foreach ([401, 422] as $status) {
            Http::swap(new Factory);
            Http::fake([self::URL => Http::response(['error' => 'refused'], $status)]);

            $this->get(route('auth.ecosystem', ['token' => 't']))->assertStatus(303)->assertRedirect('/login');
        }

        $this->assertGuest();
        $this->assertSame(0, User::count());
    }

    public function test_an_identity_infodot_does_not_vouch_for_is_refused(): void
    {
        foreach ([false, null, 'true', 1] as $verified) {
            Http::swap(new Factory);
            $user = ['email_verified' => $verified];
            Http::fake([self::URL => Http::response($this->identity($user))]);

            $this->get(route('auth.ecosystem', ['token' => 't']))->assertStatus(303)->assertRedirect('/login');
        }

        $this->assertGuest();
        $this->assertSame(0, User::count());
    }

    public function test_an_unverified_identity_does_not_adopt_an_existing_account(): void
    {
        $existing = User::factory()->create(['email' => 'amara@example.test', 'email_verified_at' => null]);
        $before = $existing->password;
        Http::fake([self::URL => Http::response($this->identity(['email_verified' => false]))]);

        $this->get(route('auth.ecosystem', ['token' => 't']))->assertStatus(303)->assertRedirect('/login');

        $this->assertGuest();
        $this->assertNull($existing->fresh()->email_verified_at);
        $this->assertSame($before, $existing->fresh()->password);
    }

    public function test_a_response_without_a_usable_email_is_refused(): void
    {
        Http::fake([self::URL => Http::response($this->identity(['email' => 'not-an-email']))]);

        $this->get(route('auth.ecosystem', ['token' => 't']))->assertStatus(303)->assertRedirect('/login');

        $this->assertGuest();
        $this->assertSame(0, User::count());
    }

    public function test_when_infodot_is_down_the_person_is_told_to_retry_not_that_they_are_forbidden(): void
    {
        Http::fake([self::URL => Http::response('boom', 500)]);
        $this->get(route('auth.ecosystem', ['token' => 't']))->assertStatus(303)->assertRedirect('/login')->assertSessionHas('status', fn (string $m) => str_contains($m, 'unavailable'));

        Http::swap(new Factory);
        Http::fake([self::URL => Http::response('slow down', 429)]);
        $this->get(route('auth.ecosystem', ['token' => 't']))->assertStatus(303)->assertRedirect('/login')->assertSessionHas('status', fn (string $m) => str_contains($m, 'unavailable'));

        Http::swap(new Factory);
        Http::fake(fn () => throw new ConnectionException('timeout'));
        $this->get(route('auth.ecosystem', ['token' => 't']))->assertStatus(303)->assertRedirect('/login')->assertSessionHas('status', fn (string $m) => str_contains($m, 'unavailable'));

        $this->assertGuest();
        $this->assertSame(0, User::count());
    }

    public function test_without_a_secret_the_old_local_only_behaviour_remains_and_infodot_is_never_called(): void
    {
        config(['ecosystem.infodot.secret' => null]);
        Http::fake();

        $this->get(route('auth.ecosystem', ['token' => 'unknown']))->assertStatus(303)->assertRedirect('/login');

        Http::assertNothingSent();
    }

    public function test_without_a_secret_a_local_token_still_signs_in(): void
    {
        config(['ecosystem.infodot.secret' => '']);
        $user = User::factory()->withPersonalTeam()->create();
        Http::fake();

        $this->get(route('auth.ecosystem', ['token' => $this->localToken($user)]))->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
        Http::assertNothingSent();
    }

    public function test_a_token_in_our_own_database_still_works_and_is_spent(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $token = $this->localToken($user);
        Http::fake();

        $this->get(route('auth.ecosystem', ['token' => $token]))->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
        Http::assertNothingSent();

        auth()->logout();
        // Spent locally, so the second use is passed to InfoDot, which does not know it either.
        Http::fake([self::URL => Http::response(['error' => 'invalid_request'], 403)]);
        $this->get(route('auth.ecosystem', ['token' => $token]))->assertStatus(303)->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_a_local_token_without_the_ecosystem_ability_is_not_accepted(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        config(['ecosystem.infodot.secret' => null]);

        $this->get(route('auth.ecosystem', ['token' => $this->localToken($user, ['read'])]))->assertStatus(303)->assertRedirect('/login');

        $this->assertGuest();
    }

    public function test_provisioning_can_be_switched_off(): void
    {
        config(['ecosystem.infodot.provision' => false]);
        Http::fake([self::URL => Http::response($this->identity())]);

        $this->get(route('auth.ecosystem', ['token' => 't']))->assertStatus(303)->assertRedirect('/login');

        $this->assertSame(0, User::count());
    }

    public function test_provisioning_off_still_signs_in_an_account_that_already_exists(): void
    {
        config(['ecosystem.infodot.provision' => false]);
        $existing = User::factory()->withPersonalTeam()->create(['email' => 'amara@example.test']);
        Http::fake([self::URL => Http::response($this->identity())]);

        $this->get(route('auth.ecosystem', ['token' => 't']))->assertRedirect();

        $this->assertAuthenticatedAs($existing);
    }

    public function test_a_missing_or_non_string_token_is_forbidden_without_calling_infodot(): void
    {
        Http::fake();

        $this->get(route('auth.ecosystem'))->assertStatus(303)->assertRedirect('/login');
        $this->get('/auth/ecosystem?token[]=x')->assertStatus(303)->assertRedirect('/login');
        $this->get('/auth/ecosystem?token=')->assertStatus(303)->assertRedirect('/login');

        Http::assertNothingSent();
        $this->assertGuest();
    }

    public function test_a_safe_in_app_path_is_honoured(): void
    {
        Http::fake([self::URL => Http::response($this->identity())]);

        $this->get(route('auth.ecosystem', ['token' => 't', 'redirect' => '/dashboard?tab=1']))->assertRedirect(url('/dashboard?tab=1'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unsafeRedirects(): array
    {
        return [
            'absolute url' => ['https://evil.test/phish'],
            'scheme relative' => ['//evil.test/phish'],
            'backslash trick' => ['/\\evil.test'],
            'encoded backslash trick' => ['/%5Cevil.test'],
            'encoded double slash' => ['/%2Fevil.test'],
            'traversal' => ['/a/../../etc/passwd'],
            'encoded traversal' => ['/a/%2e%2e/b'],
            'javascript' => ['javascript:alert(1)'],
            'no leading slash' => ['evil.test'],
            'embedded url' => ['/go?next=https://evil.test'],
            'control character' => ["/ok\r\nLocation: https://evil.test"],
            'whitespace' => ['/ evil.test'],
        ];
    }

    #[DataProvider('unsafeRedirects')]
    public function test_an_open_redirect_attempt_falls_back_to_the_dashboard(string $redirect): void
    {
        Http::fake([self::URL => Http::response($this->identity())]);

        $this->get(route('auth.ecosystem', ['token' => 't', 'redirect' => $redirect]))
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticated();
    }

    public function test_a_non_string_redirect_falls_back_to_the_dashboard(): void
    {
        Http::fake([self::URL => Http::response($this->identity())]);

        $this->get('/auth/ecosystem?token=t&redirect[]=/x')->assertRedirect(route('dashboard'));
    }

    public function test_signing_in_replaces_the_session_id(): void
    {
        Http::fake([self::URL => Http::response($this->identity())]);
        $this->startSession();
        $before = session()->getId();

        $this->get(route('auth.ecosystem', ['token' => 't']));

        $this->assertNotSame($before, session()->getId());
    }
}
