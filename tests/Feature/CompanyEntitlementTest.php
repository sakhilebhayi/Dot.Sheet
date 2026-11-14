<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureCompanyEntitled;
use App\Models\NamedRange;
use App\Models\Spreadsheet;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * R-50: InfoDot decides whether a company holds Dot.Sheet, and this app honours it: at sign-in (only companies marked
 * entitled are admitted and synced) and during a session (the current company is asked about, cached, and a clear
 * "no" shows a plain page instead of the company's workbooks). Anything short of a clear "no" lets people carry on.
 * The bodies below are the shapes InfoDot's own tests assert (EcosystemRedeemController, CompanyPlatformsController).
 */
class CompanyEntitlementTest extends TestCase
{
    use RefreshDatabase;

    private const REDEEM = 'https://infodot.test/api/ecosystem/redeem';

    private const COMPANIES = 'https://infodot.test/api/ecosystem/companies/';

    private const ACME = '11111111-1111-4111-8111-111111111111';

    private const GLOBEX = '22222222-2222-4222-8222-222222222222';

    private string $bearer;

    /** How many times InfoDot was asked about a company (counted here: a refused connection is not recorded by the fake). */
    private int $asked = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bearer = Str::random(40);

        config(['ecosystem.infodot' => [
            'redeem_url' => self::REDEEM, 'platform' => 'sheet', 'secret' => $this->bearer, 'timeout' => 2, 'provision' => true,
            'entitlement_check' => true, 'companies_url' => null, 'entitlement_timeout' => 2,
        ]]);

        // The bar's platform switcher (dot-ui) reads the same InfoDot endpoint. These tests count the ENTITLEMENT check's
        // calls, so the switcher is answered from a fixed cache entry here; what it shows and asks is tested in DotUiHeaderTest.
        config(['ecosystem.infodot.switcher_cache_key' => 'test:switcher-answer']);
        Cache::put('test:switcher-answer', ['ok' => true, 'personal' => false, 'rows' => [
            ['key' => 'sheet', 'name' => 'Dot.Sheet', 'entitled' => true, 'plain' => false, 'status' => 'active'],
        ]], 3600);
    }

    // ---- helpers ----------------------------------------------------------------------------------------------

    private function company(string $uuid, string $name, string $role = 'owner', ?bool $entitled = null, ?string $access = null, bool $personal = false): array
    {
        $team = ['id' => random_int(1, 999), 'uuid' => $uuid, 'name' => $name, 'personal' => $personal, 'role' => $role, 'role_name' => ucfirst($role)];

        // An InfoDot from before R-50 sends neither key.
        return $entitled === null ? $team : $team + ['entitled' => $entitled, 'access' => $access];
    }

    private function signIn(string $email, array $teams, ?string $current = null)
    {
        Http::swap(new Factory);
        Http::fake([self::REDEEM => Http::response([
            'platform' => 'sheet',
            'user' => ['id' => 1, 'uuid' => (string) Str::uuid(), 'name' => ucfirst(strstr($email, '@', true)), 'email' => $email, 'email_verified' => true],
            'teams' => $teams,
            'current_team_id' => 1,
            'current_team_uuid' => $current,
        ])]);

        auth()->logout();

        return $this->post(route('auth.ecosystem'), ['token' => 'one-time'], ['Origin' => 'https://infodot.test']);
    }

    /** What InfoDot answers for a company, as this platform sees it (its own row carries access_mode). */
    private function platforms(string $uuid, bool $entitled, string $status, bool $personal = false): array
    {
        return [
            'platform' => 'sheet',
            'company' => ['uuid' => $uuid, 'personal' => $personal],
            'platforms' => [
                ['key' => 'finance', 'name' => 'Dot.Finance', 'status' => 'included', 'entitled' => true, 'is_core' => true],
                ['key' => 'hr', 'name' => 'Dot.HR', 'status' => 'free_while_pricing_finalised', 'entitled' => false, 'is_core' => false],
                ['key' => 'sheet', 'name' => 'Dot.Sheet', 'status' => $status, 'entitled' => $entitled, 'is_core' => false, 'access_mode' => 'everyone'],
            ],
        ];
    }

    /** @param array<string, mixed> $answers uuid => response (or a closure) */
    private function infodotAnswers(array $answers): void
    {
        Http::swap(new Factory);
        Http::fake(collect($answers)->mapWithKeys(fn ($answer, $uuid) => [self::COMPANIES.$uuid.'/platforms*' => function (Request $request) use ($answer) {
            $this->asked++;

            return $answer instanceof \Closure ? $answer($request) : $answer;
        }])->all());
    }

    /** A person whose current workspace is a company linked to InfoDot, with one workbook in it. */
    private function memberOfLinkedCompany(string $uuid = self::ACME, string $name = 'Acme Ltd'): array
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = Team::forceCreate(['user_id' => $user->id, 'name' => $name, 'personal_team' => false, 'infodot_team_uuid' => $uuid]);
        $user->switchTeam($team);
        $sheet = Spreadsheet::create(['uuid' => (string) Str::uuid(), 'owner_id' => $user->id, 'team_id' => $team->id, 'name' => 'Acme payroll 2026']);

        return [$user->fresh(), $team, $sheet];
    }

    private function platformCalls(): int
    {
        return $this->asked;
    }

    // ---- sign-in (redeem) -------------------------------------------------------------------------------------

    public function test_a_company_marked_entitled_is_admitted_and_synced(): void
    {
        $this->signIn('ann@acme.test', [$this->company(self::ACME, 'Acme Ltd', 'owner', true)], self::ACME)->assertRedirect(route('dashboard'));

        $team = Team::where('infodot_team_uuid', self::ACME)->firstOrFail();
        $this->assertAuthenticated();
        $this->assertSame($team->id, User::where('email', 'ann@acme.test')->firstOrFail()->current_team_id);
    }

    public function test_a_company_marked_not_entitled_is_not_created_and_cannot_become_the_current_company(): void
    {
        $this->signIn('ann@acme.test', [
            $this->company(self::ACME, 'Acme Ltd', 'owner', true),
            $this->company(self::GLOBEX, 'Globex', 'owner', false, 'not_activated'),
        ], self::GLOBEX)->assertRedirect(route('dashboard'));

        $this->assertNull(Team::where('infodot_team_uuid', self::GLOBEX)->first());
        $ann = User::where('email', 'ann@acme.test')->firstOrFail();
        $this->assertTrue($ann->belongsToTeam(Team::where('infodot_team_uuid', self::ACME)->firstOrFail()));
    }

    public function test_a_company_that_stops_being_entitled_is_left_as_it_is_and_the_person_is_moved_off_it(): void
    {
        $this->signIn('ann@acme.test', [$this->company(self::ACME, 'Acme Ltd', 'owner', true), $this->company(self::GLOBEX, 'Globex', 'owner', true)], self::ACME);
        $this->signIn('bob@acme.test', [$this->company(self::ACME, 'Acme Ltd', 'editor', true), $this->company(self::GLOBEX, 'Globex', 'editor', true)], self::ACME);

        $acme = Team::where('infodot_team_uuid', self::ACME)->firstOrFail();
        $globex = Team::where('infodot_team_uuid', self::GLOBEX)->firstOrFail();
        $bob = User::where('email', 'bob@acme.test')->firstOrFail();
        $this->assertSame($acme->id, $bob->current_team_id);

        // Acme lapses; InfoDot still says Acme is the active company, with a new name and a new role.
        $this->signIn('bob@acme.test', [
            $this->company(self::ACME, 'Acme Renamed', 'admin', false, 'expired'),
            $this->company(self::GLOBEX, 'Globex', 'editor', true),
        ], self::ACME)->assertRedirect(route('dashboard'));

        $bob = $bob->fresh();
        $this->assertSame($globex->id, $bob->current_team_id, 'moved to the company that holds the platform');
        $this->assertSame('Acme Ltd', $acme->fresh()->name, 'a company that is not entitled is not updated');
        $this->assertTrue($bob->belongsToTeam($acme->fresh()), 'membership is kept: only the use stops');
        $this->assertSame('editor', $bob->teamRole($acme->fresh())->key);
    }

    public function test_a_response_without_the_mark_behaves_as_before(): void
    {
        $this->signIn('ann@acme.test', [$this->company(self::ACME, 'Acme Ltd', 'owner'), $this->company(self::GLOBEX, 'Globex', 'owner')], self::GLOBEX)
            ->assertRedirect(route('dashboard'));

        $this->assertNotNull(Team::where('infodot_team_uuid', self::ACME)->first());
        $globex = Team::where('infodot_team_uuid', self::GLOBEX)->firstOrFail();
        $this->assertSame($globex->id, User::where('email', 'ann@acme.test')->firstOrFail()->current_team_id);
    }

    public function test_when_no_company_is_entitled_the_person_gets_the_plain_page_and_nothing_is_created(): void
    {
        $response = $this->signIn('ann@acme.test', [
            $this->company('33333333-3333-4333-8333-333333333333', 'Ann Team', 'owner', false, 'no_personal_access', personal: true),
            $this->company(self::ACME, 'Acme Ltd', 'owner', false, 'not_activated'),
        ], self::ACME);

        $response->assertStatus(403)
            ->assertSee('Dot.Sheet is not switched on for your company')
            ->assertSee('Open Platforms in InfoDot')
            ->assertSee('href="https://infodot.co.za/platforms/sheet"', false)
            ->assertDontSee('one-time');

        $this->assertGuest();
        $this->assertSame(0, User::count());
        $this->assertSame(0, Team::count());
    }

    public function test_the_sign_in_page_is_worded_from_the_reason(): void
    {
        $this->signIn('ann@acme.test', [$this->company(self::ACME, 'Acme Ltd', 'owner', false, 'suspended')], self::ACME)
            ->assertStatus(403)->assertSee('Dot.Sheet is suspended for your company');
    }

    public function test_a_personal_workspace_follows_what_infodot_said(): void
    {
        // InfoDot says the personal workspace holds the platform and the company does not: the person is admitted.
        $this->signIn('ann@acme.test', [
            $this->company('33333333-3333-4333-8333-333333333333', 'Ann Team', 'owner', true, personal: true),
            $this->company(self::ACME, 'Acme Ltd', 'owner', false, 'not_activated'),
        ], self::ACME)->assertRedirect(route('dashboard'));

        $ann = User::where('email', 'ann@acme.test')->firstOrFail();
        $this->assertTrue($ann->currentTeam->personal_team);
        $this->assertNull(Team::where('infodot_team_uuid', self::ACME)->first());

        // A personal workspace is not an InfoDot company here, so nothing is asked about it during the session.
        $this->get('/dashboard')->assertOk();
        $this->assertSame(0, $this->platformCalls());
    }

    public function test_the_personal_answer_is_remembered_at_sign_in(): void
    {
        $personal = fn (?bool $entitled) => $this->company('33333333-3333-4333-8333-333333333333', 'Ann Team', 'owner', $entitled, $entitled === false ? 'no_personal_access' : null, personal: true);
        $acme = $this->company(self::ACME, 'Acme Ltd', 'owner', true);

        $this->signIn('ann@acme.test', [$personal(false), $acme], self::ACME)->assertRedirect(route('dashboard'));
        $this->assertFalse(session(EnsureCompanyEntitled::PERSONAL));

        $this->signIn('ann@acme.test', [$personal(true), $acme], self::ACME);
        $this->assertTrue(session(EnsureCompanyEntitled::PERSONAL));

        // An InfoDot from before R-50 sends no mark: nothing is remembered, and an earlier answer is dropped.
        $this->signIn('ann@acme.test', [$personal(null), $acme], self::ACME);
        $this->assertNull(session(EnsureCompanyEntitled::PERSONAL));
    }

    public function test_a_personal_workspace_refused_at_sign_in_is_not_a_way_round_the_check(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $personal = $user->currentTeam;
        $sheet = Spreadsheet::create(['uuid' => (string) Str::uuid(), 'owner_id' => $user->id, 'team_id' => $personal->id, 'name' => 'My own sums']);
        $acme = Team::forceCreate(['user_id' => $user->id, 'name' => 'Acme Ltd', 'personal_team' => false, 'infodot_team_uuid' => self::ACME]);
        $unlinked = Team::forceCreate(['user_id' => $user->id, 'name' => 'Side project', 'personal_team' => false]);
        $this->infodotAnswers([self::ACME => Http::response($this->platforms(self::ACME, true, 'active'))]);

        foreach ([$personal, $unlinked] as $team) {
            $user->switchTeam($team);
            $this->actingAs($user->fresh())->withSession([EnsureCompanyEntitled::PERSONAL => false])
                ->get('/dashboard')
                ->assertStatus(403)
                ->assertSee('Dot.Sheet needs a company that has switched it on')
                ->assertSee('href="https://infodot.co.za/platforms/sheet"', false)
                ->assertSee('Acme Ltd')
                ->assertDontSee('My own sums')
                ->assertDontSee('Side project');
        }

        $this->getJson("/spreadsheets/{$sheet->id}/tabs/{$sheet->sheetTabs()->firstOrFail()->id}/cells")->assertStatus(403);
        $this->assertSame(0, $this->platformCalls(), 'a team with no InfoDot company is never asked about');

        // Nothing was deleted or altered, and the person can move to the entitled company.
        $this->assertSame('My own sums', $sheet->fresh()->name);
        $this->put('/current-team', ['team_id' => $acme->id])->assertRedirect();
        $this->get('/dashboard')->assertOk();
        $this->assertSame(1, $this->platformCalls());
    }

    public function test_a_personal_workspace_that_holds_the_core_or_was_never_answered_carries_on(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $sheet = Spreadsheet::create(['uuid' => (string) Str::uuid(), 'owner_id' => $user->id, 'team_id' => $user->currentTeam->id, 'name' => 'My own sums']);
        $this->infodotAnswers([]);

        // true: the free core. Absent: an older InfoDot, or a session that began before this release.
        $this->actingAs($user)->withSession([EnsureCompanyEntitled::PERSONAL => true])->get('/dashboard')->assertOk()->assertSee('My own sums');
        $this->flushSession();
        $this->actingAs($user)->get('/dashboard')->assertOk()->assertSee('My own sums');
        $this->assertSame(0, $this->platformCalls());
    }

    public function test_the_remembered_personal_answer_does_not_touch_an_entitled_company(): void
    {
        [$user] = $this->memberOfLinkedCompany();
        $this->infodotAnswers([self::ACME => Http::response($this->platforms(self::ACME, true, 'active'))]);

        $this->actingAs($user)->withSession([EnsureCompanyEntitled::PERSONAL => false])->get('/dashboard')->assertOk()->assertSee('Acme payroll 2026');
    }

    // ---- during a session -------------------------------------------------------------------------------------

    public function test_an_entitled_company_carries_on_and_names_itself_to_infodot(): void
    {
        [$user] = $this->memberOfLinkedCompany();
        $this->infodotAnswers([self::ACME => Http::response($this->platforms(self::ACME, true, 'active'))]);

        $this->actingAs($user)->get('/dashboard')->assertOk()->assertSee('Acme payroll 2026');

        Http::assertSent(fn (Request $request) => $request->method() === 'GET'
            && str_starts_with($request->url(), self::COMPANIES.self::ACME.'/platforms')
            && ($request->data()['platform'] ?? null) === 'sheet'
            && $request->hasHeader('X-Dot-Platform', 'sheet')
            && $request->hasHeader('Authorization', 'Bearer '.$this->bearer));
    }

    #[DataProvider('refusedStates')]
    public function test_a_company_that_does_not_hold_the_platform_gets_the_plain_page_and_no_data(string $status, string $heading): void
    {
        [$user, $team, $sheet] = $this->memberOfLinkedCompany();
        $this->infodotAnswers([self::ACME => Http::response($this->platforms(self::ACME, false, $status))]);

        $this->actingAs($user)->get('/dashboard')
            ->assertStatus(403)
            ->assertSee($heading)
            ->assertSee('href="https://infodot.co.za/platforms/sheet"', false)
            ->assertDontSee('Acme payroll 2026');

        $this->get("/spreadsheets/{$sheet->id}")->assertStatus(403)->assertDontSee('Acme payroll 2026');
        $this->getJson("/spreadsheets/{$sheet->id}/tabs/{$sheet->sheetTabs()->firstOrFail()->id}/cells")
            ->assertStatus(403)->assertJsonPath('reason', 'platform_not_entitled')->assertJsonMissingPath('cells');

        // Nothing is changed either, and the data is untouched.
        $this->delete("/spreadsheets/{$sheet->id}")->assertStatus(403);
        $this->post('/spreadsheets', ['name' => 'Should not exist'])->assertStatus(403);
        $this->assertSame(['Acme payroll 2026'], Spreadsheet::pluck('name')->all());
        $this->assertSame($team->id, $user->fresh()->current_team_id);
    }

    public static function refusedStates(): array
    {
        return [
            'not switched on' => ['free_while_pricing_finalised', 'Dot.Sheet is not switched on for your company'],
            'suspended' => ['suspended', 'Dot.Sheet is suspended for your company'],
            'payment needed' => ['payment_required', 'Dot.Sheet needs a payment for your company'],
            'ended' => ['subscription_expired', 'Dot.Sheet has ended for your company'],
        ];
    }

    public function test_a_livewire_panel_stops_too(): void
    {
        [$user, , $sheet] = $this->memberOfLinkedCompany();
        $this->assertContains(EnsureCompanyEntitled::class, Livewire::getPersistentMiddleware());
        $this->infodotAnswers([self::ACME => Http::response($this->platforms(self::ACME, false, 'suspended'))]);

        $this->actingAs($user)->get("/spreadsheets/{$sheet->id}/classic")->assertStatus(403);
    }

    #[DataProvider('failures')]
    public function test_any_failure_to_get_an_answer_lets_the_person_carry_on(\Closure $answer): void
    {
        [$user] = $this->memberOfLinkedCompany();
        $this->infodotAnswers([self::ACME => $answer(self::ACME)]);
        Log::spy();

        $this->actingAs($user)->get('/dashboard')->assertOk()->assertSee('Acme payroll 2026');
        $this->get('/dashboard')->assertOk();

        // Remembered for a minute: asked once, logged once.
        $this->assertSame(1, $this->platformCalls());
        Log::shouldHaveReceived('warning')->withArgs(fn ($message) => str_contains($message, 'entitlement check could not be answered'))->once();

        // A failure is forgotten after a minute, long before a real answer would be.
        $this->travel(61)->seconds();
        $this->get('/dashboard')->assertOk();
        $this->assertSame(2, $this->platformCalls());
    }

    public static function failures(): array
    {
        $row = fn (array $sheet) => fn (string $uuid) => Http::response(['platform' => 'sheet', 'company' => ['uuid' => $uuid, 'personal' => false], 'platforms' => [$sheet]]);

        return [
            'connection error or timeout' => [fn () => fn () => throw new ConnectionException('timed out')],
            '401 wrong secret' => [fn () => Http::response(['error' => 'invalid_request'], 401)],
            '403 platform switched off' => [fn () => Http::response(['error' => 'invalid_request'], 403)],
            '404 unknown company' => [fn () => Http::response(['error' => 'not_found'], 404)],
            '429 throttled' => [fn () => Http::response('', 429)],
            '500' => [fn () => Http::response('boom', 500)],
            '503' => [fn () => Http::response('', 503)],
            'not json' => [fn () => Http::response('<html>maintenance</html>', 200)],
            'no platforms list' => [fn (string $uuid) => Http::response(['platform' => 'sheet', 'company' => ['uuid' => $uuid]])],
            'another company in the answer' => [fn () => Http::response(['platform' => 'sheet', 'company' => ['uuid' => self::GLOBEX, 'personal' => false], 'platforms' => [['key' => 'sheet', 'entitled' => false, 'status' => 'suspended']]])],
            'own row missing' => [$row(['key' => 'hr', 'name' => 'Dot.HR', 'status' => 'suspended', 'entitled' => false, 'is_core' => false])],
            'entitled is not a boolean' => [$row(['key' => 'sheet', 'name' => 'Dot.Sheet', 'status' => 'suspended', 'entitled' => 'false', 'is_core' => false])],
            'entitled missing from own row' => [$row(['key' => 'sheet', 'name' => 'Dot.Sheet', 'status' => 'suspended'])],
        ];
    }

    public function test_no_service_secret_lets_the_person_carry_on_without_a_call(): void
    {
        [$user] = $this->memberOfLinkedCompany();
        config(['ecosystem.infodot.secret' => null]);
        $this->infodotAnswers([self::ACME => Http::response($this->platforms(self::ACME, false, 'suspended'))]);

        $this->actingAs($user)->get('/dashboard')->assertOk()->assertSee('Acme payroll 2026');
        $this->assertSame(0, $this->platformCalls());
    }

    public function test_the_answer_is_cached_for_ten_minutes_per_company(): void
    {
        [$user] = $this->memberOfLinkedCompany();
        $this->infodotAnswers([self::ACME => Http::response($this->platforms(self::ACME, true, 'active'))]);

        $this->actingAs($user)->get('/dashboard')->assertOk();
        $this->get('/dashboard')->assertOk();
        $this->travel(9)->minutes();
        $this->get('/activity')->assertOk();
        $this->assertSame(1, $this->platformCalls());

        $this->travel(2)->minutes();
        $this->get('/dashboard')->assertOk();
        $this->assertSame(2, $this->platformCalls());
    }

    public function test_the_flag_off_makes_no_call(): void
    {
        [$user] = $this->memberOfLinkedCompany();
        config(['ecosystem.infodot.entitlement_check' => false]);
        $this->infodotAnswers([self::ACME => Http::response($this->platforms(self::ACME, false, 'suspended'))]);

        $this->actingAs($user)->get('/dashboard')->assertOk()->assertSee('Acme payroll 2026');
        $this->assertSame(0, $this->platformCalls());
    }

    public function test_the_check_is_on_unless_switched_off(): void
    {
        $this->assertTrue((require config_path('ecosystem.php'))['infodot']['entitlement_check']);
        $this->assertStringContainsString("\nSHEET_ENTITLEMENT_CHECK=true\n", file_get_contents(base_path('.env.example')));
    }

    public function test_exempt_routes_make_no_call(): void
    {
        [$user, $team, $sheet] = $this->memberOfLinkedCompany();
        $sheet->forceFill(['public_enabled' => true, 'public_token' => Str::random(40)])->save();
        $this->infodotAnswers([self::ACME => Http::response($this->platforms(self::ACME, false, 'suspended'))]);

        // Signed out: public pages, sign-in, health checks, the public share link, the machine health report.
        foreach (['/', '/login', '/up', '/ready', '/up/scheduler', '/cookies', '/guardian/health', '/auth/ecosystem', '/shared/s/'.$sheet->public_token] as $path) {
            $this->assertNotSame(403, $this->get($path)->getStatusCode(), $path);
        }
        $this->get('/dashboard')->assertRedirect('/login');

        // Signed in, in a company that does not hold the platform: the account pages, the API and signing out.
        $this->actingAs($user);
        $this->get('/user/profile')->assertOk();
        $this->getJson('/api/user')->assertOk();
        $this->get('/')->assertOk();
        $this->post('/logout')->assertRedirect();

        $this->assertSame(0, $this->platformCalls());
    }

    public function test_a_livewire_action_that_worked_is_stopped_for_real_when_access_ends(): void
    {
        [$user, , $sheet] = $this->memberOfLinkedCompany();
        $tab = $sheet->sheetTabs()->firstOrFail();
        $answers = [self::ACME => Http::response($this->platforms(self::ACME, true, 'active'))];
        $this->infodotAnswers($answers);

        // The classic editor renders the named-ranges panel; take its snapshot from the page, as the browser would.
        $html = $this->actingAs($user)->get("/spreadsheets/{$sheet->id}/classic")->assertOk()->getContent();
        $snapshots = [];
        preg_match_all('/wire:snapshot="([^"]*)"/', $html, $all);
        foreach ($all[1] as $encoded) {
            $snapshot = json_decode(html_entity_decode($encoded), true);
            if (($snapshot['memo']['name'] ?? null) === 'named-ranges-panel') {
                $snapshots[] = $snapshot;
            }
        }
        $this->assertNotEmpty($snapshots, 'the panel is on the page');
        $snapshot = $snapshots[0];

        $call = fn (string $name) => $this->withHeaders(['X-Livewire' => 'true'])->postJson(route('default.livewire.update'), ['components' => [[
            'snapshot' => json_encode($snapshot),
            'updates' => ['name' => $name, 'rangeInput' => 'A1:B2'],
            'calls' => [['path' => '', 'method' => 'createRange', 'params' => []]],
        ]], '_token' => csrf_token()]);

        // While the company holds the platform the action runs and writes.
        $call('Takings')->assertOk();
        $this->assertSame(['Takings'], NamedRange::where('spreadsheet_id', $sheet->id)->pluck('name')->all());

        // The cached answer expires and InfoDot now says no.
        $this->travel(11)->minutes();
        $this->infodotAnswers([self::ACME => Http::response($this->platforms(self::ACME, false, 'suspended'))]);

        $call('Costs')->assertStatus(403)->assertJsonMissingPath('components');
        $this->assertStringNotContainsString('components', (string) $call('Costs')->getContent());
        $this->assertSame(['Takings'], NamedRange::where('spreadsheet_id', $sheet->id)->pluck('name')->all(), 'the action did not run');
        $this->assertSame($tab->id, $snapshot['data']['sheet_tab_id']);
    }

    public function test_the_person_can_switch_to_another_company_that_is_entitled(): void
    {
        [$user, $acme] = $this->memberOfLinkedCompany();
        $globex = Team::forceCreate(['user_id' => $user->id, 'name' => 'Globex', 'personal_team' => false, 'infodot_team_uuid' => self::GLOBEX]);
        Spreadsheet::create(['uuid' => (string) Str::uuid(), 'owner_id' => $user->id, 'team_id' => $globex->id, 'name' => 'Globex stock count']);
        $this->infodotAnswers([
            self::ACME => Http::response($this->platforms(self::ACME, false, 'payment_required')),
            self::GLOBEX => Http::response($this->platforms(self::GLOBEX, true, 'active')),
        ]);

        // The page offers the person's own other workspaces by name, and nothing of the other company's data.
        $this->actingAs($user)->get('/dashboard')
            ->assertStatus(403)
            ->assertSee('Globex')
            ->assertSee(route('current-team.update'), false)
            ->assertDontSee('Globex stock count');

        $this->put('/current-team', ['team_id' => $globex->id])->assertRedirect();
        $this->assertSame($globex->id, $user->fresh()->current_team_id);

        $this->get('/dashboard')->assertOk()->assertSee('Globex stock count');

        // And back: Acme is still refused, from the cache.
        $this->put('/current-team', ['team_id' => $acme->id])->assertRedirect();
        $this->get('/dashboard')->assertStatus(403);
        $this->assertSame(2, $this->platformCalls());
    }
}
