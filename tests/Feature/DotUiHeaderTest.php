<?php

namespace Tests\Feature;

use App\Models\Spreadsheet;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request as OutgoingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The signed-in bar carries the shared Dot UI (dot-ui): the find pill (a trigger for the existing Cmd/Ctrl+K
 * palette, which did not change) and the platform switcher. The dashboard's list filter is the shared compact filter.
 * The switcher only ever shows what InfoDot says the CURRENT company holds, linking through InfoDot's /go.
 */
class DotUiHeaderTest extends TestCase
{
    use RefreshDatabase;

    private const ACME = '11111111-1111-4111-8111-111111111111';

    private const GLOBEX = '22222222-2222-4222-8222-222222222222';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ecosystem.infodot' => [
                'redeem_url' => 'https://infodot.test/api/ecosystem/redeem', 'platform' => 'sheet', 'secret' => 'a-test-secret', 'timeout' => 2,
                'provision' => true, 'entitlement_check' => true, 'companies_url' => null, 'entitlement_timeout' => 2,
            ],
            'ecosystem.platforms.infodot.url' => 'https://infodot.test',
        ]);
    }

    private static function url(string $uuid): string
    {
        return 'https://infodot.test/api/ecosystem/companies/'.$uuid.'/platforms*';
    }

    private static function body(string $uuid, array $held = ['sheet', 'files', 'infodot']): array
    {
        $rows = [];
        foreach (['sheet' => 'Dot.Sheet', 'files' => 'Dot.Files', 'finance' => 'Dot.Finance', 'hr' => 'Dot.HR', 'infodot' => 'InfoDot', 'press' => 'Dot.Press'] as $key => $name) {
            $rows[] = ['key' => $key, 'name' => $name, 'status' => 'active', 'entitled' => in_array($key, $held, true), 'is_core' => false, 'access_mode' => 'everyone'];
        }

        return ['platform' => 'sheet', 'company' => ['uuid' => $uuid, 'personal' => false], 'platforms' => $rows];
    }

    private function fakeInfoDot(array $stubs): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake($stubs);
    }

    private function memberOf(string $uuid, string $name, ?string $workbook = null): User
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = Team::forceCreate(['user_id' => $user->id, 'name' => $name, 'personal_team' => false, 'infodot_team_uuid' => $uuid]);
        $user->switchTeam($team);
        if ($workbook) {
            Spreadsheet::create(['uuid' => (string) Str::uuid(), 'owner_id' => $user->id, 'team_id' => $team->id, 'name' => $workbook]);
        }

        return $user->fresh();
    }

    private function switcherHtml(string $html): string
    {
        $this->assertSame(1, preg_match('/<div class="dsw dsw--header".*?<p class="dsw-sr" data-dsw-live[^>]*><\/p>\s*<\/div>\s*<\/div>\s*<\/div>/s', $html, $m), 'the bar carries the switcher');

        return $m[0];
    }

    // ── The find pill: a trigger for the palette ─────────────────────────────

    public function test_the_find_pill_is_the_shared_pill_and_opens_the_unchanged_palette(): void
    {
        $this->fakeInfoDot([self::url(self::ACME) => Http::response(self::body(self::ACME))]);
        $html = $this->actingAs($this->memberOf(self::ACME, 'Acme Ltd'))->get(route('dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString('class="dsr-input"', $html, 'the shared pill');
        $this->assertMatchesRegularExpression('/<input[^>]*\breadonly\b[^>]*>/', $html, 'the pill reads nothing: it only opens the palette');
        $this->assertStringContainsString('data-find-trigger', $html);
        $this->assertStringContainsString('placeholder="Find anything"', $html);
        $this->assertStringContainsString('Ctrl K', $html);

        // The palette, its command list and its trigger id are exactly what shell.js binds to.
        $this->assertStringContainsString('<dialog id="palette"', $html);
        $this->assertStringContainsString('id="open-palette"', $html);
        $this->assertStringContainsString('id="commands"', $html);
        $this->assertStringContainsString('"label":"New spreadsheet"', $html);
        $this->assertStringContainsString('"group":"Go to"', $html);
        $this->assertSame(1, substr_count($html, 'id="open-palette"'));
        $this->assertSame(1, substr_count($html, 'id="found"'));
    }

    public function test_the_shared_search_script_is_not_loaded_so_the_palette_keeps_ctrl_k_to_itself(): void
    {
        $this->fakeInfoDot([self::url(self::ACME) => Http::response(self::body(self::ACME))]);
        $html = $this->actingAs($this->memberOf(self::ACME, 'Acme Ltd'))->get(route('dashboard'))->getContent();

        $this->assertStringNotContainsString('dot-search.js', $html);
    }

    public function test_a_guest_sees_no_pill_and_no_switcher(): void
    {
        $this->get(route('login'))->assertOk()->assertDontSee('dsr-input', false)->assertDontSee('data-dsw', false);
    }

    // ── The dashboard filter ────────────────────────────────────────────────

    public function test_the_dashboard_filter_is_the_shared_compact_filter_with_the_id_the_page_script_reads(): void
    {
        $this->fakeInfoDot([self::url(self::ACME) => Http::response(self::body(self::ACME))]);
        $html = $this->actingAs($this->memberOf(self::ACME, 'Acme Ltd', 'Payroll'))->get(route('dashboard'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<input[^>]*\bid="filter"[^>]*\bclass="dsr-filter"[^>]*>|<input[^>]*\bclass="dsr-filter"[^>]*\bid="filter"[^>]*>/', $html);
        $this->assertStringContainsString('placeholder="Filter this list"', $html);
        $this->assertStringContainsString('aria-label="Filter the list by name"', $html);
        $this->assertStringNotContainsString('<label class="line">', $html);
        $this->assertStringContainsString('data-sort="name"', $html, 'the sortable table is untouched');
        $this->assertStringContainsString('Payroll', $html);
    }

    public function test_the_dashboard_lists_only_the_current_workspaces_workbooks(): void
    {
        $this->fakeInfoDot([
            self::url(self::ACME) => Http::response(self::body(self::ACME)),
            self::url(self::GLOBEX) => Http::response(self::body(self::GLOBEX)),
        ]);
        $acme = $this->memberOf(self::ACME, 'Acme Ltd', 'Acme payroll');
        $globex = $this->memberOf(self::GLOBEX, 'Globex Ltd', 'Globex secrets');

        $this->actingAs($acme)->get(route('dashboard'))->assertOk()->assertSee('Acme payroll')->assertDontSee('Globex secrets');
        $this->actingAs($globex)->get(route('dashboard'))->assertOk()->assertSee('Globex secrets')->assertDontSee('Acme payroll');
    }

    // ── The switcher ────────────────────────────────────────────────────────

    public function test_a_member_sees_what_their_company_holds_and_the_current_platform_marked(): void
    {
        $this->fakeInfoDot([self::url(self::ACME) => Http::response(self::body(self::ACME))]);
        $html = $this->actingAs($this->memberOf(self::ACME, 'Acme Ltd'))->get(route('dashboard'))->assertOk()->getContent();
        $panel = $this->switcherHtml($html);

        $this->assertStringContainsString('data-state="ok"', $panel);
        $this->assertStringContainsString('data-platform="sheet"', $panel);
        $this->assertStringContainsString('Acme Ltd', $panel);
        $this->assertSame(1, preg_match('/<div class="dsw-bay is-current" aria-current="page">.*?You are here/s', $panel, $here));
        $this->assertStringContainsString('Dot.Sheet', $here[0]);

        $this->assertStringContainsString('href="https://infodot.test/go/files?company='.self::ACME.'"', $panel);
        $this->assertStringContainsString('href="https://infodot.test/home"', $panel);
        $this->assertStringNotContainsString('/go/hr', $panel);
        $this->assertStringNotContainsString('/go/press', $panel);
        $this->assertStringContainsString('href="https://infodot.test/platforms/hr"', $panel);

        $this->assertStringNotContainsString('token', strtolower($panel));
        $this->assertStringNotContainsString('a-test-secret', $html);
        preg_match_all('/href="([^"]+)"/', $panel, $hrefs);
        foreach ($hrefs[1] as $href) {
            $this->assertStringStartsWith('https://infodot.test/', $href, 'every way out of the switcher goes through InfoDot');
        }
    }

    public function test_the_switcher_asks_infodot_about_the_current_company_only_with_the_service_secret(): void
    {
        $this->fakeInfoDot([self::url(self::ACME) => Http::response(self::body(self::ACME))]);

        $this->actingAs($this->memberOf(self::ACME, 'Acme Ltd'))->get(route('dashboard'))->assertOk();

        Http::assertSent(fn (OutgoingRequest $request) => str_contains($request->url(), '/companies/'.self::ACME.'/platforms')
            && $request->hasHeader('Authorization', 'Bearer a-test-secret')
            && $request->hasHeader('X-Dot-Platform', 'sheet'));
        Http::assertNotSent(fn (OutgoingRequest $request) => str_contains($request->url(), self::GLOBEX));
    }

    public function test_two_companies_never_see_each_others_switcher(): void
    {
        $this->fakeInfoDot([
            self::url(self::ACME) => Http::response(self::body(self::ACME, ['sheet', 'infodot', 'press'])),
            self::url(self::GLOBEX) => Http::response(self::body(self::GLOBEX, ['sheet', 'infodot', 'hr'])),
        ]);

        $acme = $this->switcherHtml($this->actingAs($this->memberOf(self::ACME, 'Acme Ltd'))->get(route('dashboard'))->getContent());
        $globex = $this->switcherHtml($this->actingAs($this->memberOf(self::GLOBEX, 'Globex Ltd'))->get(route('dashboard'))->getContent());

        $this->assertStringContainsString('/go/press?company='.self::ACME, $acme);
        $this->assertStringNotContainsString('/go/hr', $acme);
        $this->assertStringNotContainsString(self::GLOBEX, $acme);
        $this->assertStringContainsString('/go/hr?company='.self::GLOBEX, $globex);
        $this->assertStringNotContainsString('/go/press', $globex);
        $this->assertStringNotContainsString(self::ACME, $globex);
    }

    public function test_when_infodot_cannot_be_reached_the_switcher_says_so_and_never_shows_active(): void
    {
        $this->fakeInfoDot([self::url(self::ACME) => Http::response('down', 503)]);

        $panel = $this->switcherHtml($this->actingAs($this->memberOf(self::ACME, 'Acme Ltd'))->get(route('dashboard'))->assertOk()->getContent());

        $this->assertStringContainsString('data-state="degraded"', $panel);
        $this->assertStringContainsString('Could not check your platforms just now', $panel);
        $this->assertStringNotContainsString('Active', $panel);
        preg_match_all('/href="([^"]+)"/', $panel, $hrefs);
        foreach ($hrefs[1] as $href) {
            $this->assertStringStartsWith('https://infodot.test/', $href);
        }
    }

    public function test_a_connection_failure_never_breaks_the_page(): void
    {
        $this->fakeInfoDot([self::url(self::ACME) => fn () => throw new ConnectionException('refused')]);

        $html = $this->actingAs($this->memberOf(self::ACME, 'Acme Ltd'))->get(route('dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString('data-state="degraded"', $html);
    }

    public function test_a_personal_workspace_gets_the_honest_sentence_and_infodot_is_not_asked(): void
    {
        $this->fakeInfoDot([]);

        $panel = $this->switcherHtml($this->actingAs(User::factory()->withPersonalTeam()->create())->get(route('dashboard'))->assertOk()->getContent());

        $this->assertStringContainsString('data-state="personal"', $panel);
        $this->assertStringNotContainsString('dsw-bay', $panel);
        $this->assertStringContainsString('href="https://infodot.test/platforms"', $panel);
        Http::assertNothingSent();
    }

    public function test_the_switcher_sits_on_the_other_signed_in_pages_too(): void
    {
        $this->fakeInfoDot([self::url(self::ACME) => Http::response(self::body(self::ACME))]);
        $user = $this->memberOf(self::ACME, 'Acme Ltd');

        foreach ([route('activity'), route('profile.show')] as $page) {
            $this->actingAs($user)->get($page)->assertOk()->assertSee('data-dsw-trigger', false)->assertSee('data-find-trigger', false);
        }
    }

    // ── CSP: self-hosted, nothing inline, nothing external ──────────────────

    public function test_the_shared_assets_are_served_from_this_site_and_resolve(): void
    {
        $this->fakeInfoDot([self::url(self::ACME) => Http::response(self::body(self::ACME))]);
        $response = $this->actingAs($this->memberOf(self::ACME, 'Acme Ltd'))->get(route('dashboard'))->assertOk();
        $html = $response->getContent();

        foreach (['css/dot-fonts.css', 'css/dot-tokens.css', 'css/dot-search.css', 'css/dot-switcher-fonts.css', 'css/dot-switcher.css', 'js/dot-switcher.js'] as $asset) {
            $this->assertStringContainsString('/dot-ui/'.$asset, $html, $asset.' is linked from this site');
            $this->assertFileExists(public_path('dot-ui/'.$asset));
        }

        foreach (glob(public_path('dot-ui/css/*.css')) as $css) {
            preg_match_all('/url\(\s*[\'"]?([^\'")]+)[\'"]?\s*\)/', (string) file_get_contents($css), $urls);
            foreach ($urls[1] as $url) {
                $this->assertDoesNotMatchRegularExpression('#^(https?:)?//#', $url, basename($css).' reaches outside this site');
                $this->assertFileExists(dirname($css).'/'.$url, basename($css).' -> '.$url);
            }
        }

        preg_match_all('/<img src="([^"]+)" alt="Dot\./', $this->switcherHtml($html), $imgs);
        $this->assertNotEmpty($imgs[1]);
        foreach ($imgs[1] as $src) {
            $this->assertStringStartsWith('/dot-ui/img/platforms/', $src);
            $this->assertFileExists(public_path($src));
        }

        // The page's policy is untouched by this change (nothing was added to it, so none of it names dot-ui).
        $this->assertStringNotContainsString('dot-ui', (string) $response->headers->get('Content-Security-Policy'));
    }

    public function test_the_shared_markup_has_no_inline_style_or_script(): void
    {
        $this->fakeInfoDot([self::url(self::ACME) => Http::response(self::body(self::ACME))]);
        $html = $this->actingAs($this->memberOf(self::ACME, 'Acme Ltd'))->get(route('dashboard'))->getContent();

        $this->assertSame(1, preg_match('/<div class="dsr dsr--nohint?[^"]*"|<div class="dsr[^"]*"[^>]*>.*?<\/form>/s', $html, $pill));
        foreach ([$pill[0], $this->switcherHtml($html)] as $fragment) {
            $this->assertStringNotContainsString(' style=', $fragment);
            $this->assertStringNotContainsString('<style', $fragment);
            $this->assertStringNotContainsString('<script', $fragment);
            $this->assertDoesNotMatchRegularExpression('/\son[a-z]+=/', $fragment, 'no inline event handlers');
        }
    }

    public function test_the_back_links_to_infodot_are_kept_in_the_arrival_band(): void
    {
        $this->fakeInfoDot([self::url(self::ACME) => Http::response(self::body(self::ACME))]);
        $html = $this->actingAs($this->memberOf(self::ACME, 'Acme Ltd', 'Payroll'))->get(route('dashboard'))->getContent();

        $this->assertStringContainsString('aria-label="Dot ecosystem"', $html);
    }
}
