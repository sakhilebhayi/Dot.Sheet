<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureCompanyEntitled;
use App\Models\SheetTab;
use App\Models\Spreadsheet;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * R-52: Roboto, the shared header on every signed-in page (the editor too), a real header search with a tenant-scoped
 * server half, and the phone treatment. The shared header's own behaviour is proved in dot-ui; these prove this platform
 * wired it and did not weaken what it already guarded.
 */
class R52ShellTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // InfoDot is never reached from these tests: a personal workspace asks nothing, and anything else is refused.
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake();
    }

    private function sheetFor(User $user, string $name, ?Team $team = null): Spreadsheet
    {
        return Spreadsheet::create(['uuid' => (string) Str::uuid(), 'owner_id' => $user->id, 'team_id' => $team?->id ?? $user->currentTeam->id, 'name' => $name]);
    }

    private function tab(Spreadsheet $sheet, string $name, bool $hidden = false): SheetTab
    {
        return SheetTab::create(['spreadsheet_id' => $sheet->id, 'name' => $name, 'sort_order' => 9, 'hidden' => $hidden]);
    }

    // ── Typography ──────────────────────────────────────────────────────────

    public function test_every_layout_declares_roboto_and_serves_it_from_this_origin(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $sheet = $this->sheetFor($user, 'Budget');

        $pages = [
            'dashboard' => $this->actingAs($user)->get(route('dashboard')),
            'editor' => $this->actingAs($user)->get(route('spreadsheets.show', $sheet)),
            'classic' => $this->actingAs($user)->get(route('spreadsheets.classic', $sheet)),
        ];
        $this->flushSession();
        $this->app['auth']->forgetGuards();
        $pages['login'] = $this->get(route('login'));
        $pages['welcome'] = $this->get('/');

        foreach ($pages as $name => $response) {
            $html = $response->assertOk()->getContent();
            $this->assertMatchesRegularExpression('/<html[^>]*class="[^"]*\bdot-roboto\b/', $html, $name.' opts in to Roboto');
            // Signed-in pages link the one bundle (Roboto + type scale + header); the guest, welcome and error pages link the two small files.
            $this->assertTrue(
                str_contains($html, '/dot-ui/css/dot-ui.min.css')
                || (str_contains($html, '/dot-ui/css/dot-roboto.min.css') && str_contains($html, '/dot-ui/css/dot-type.min.css')),
                $name.' links Roboto and the type scale from dot-ui'
            );
            $this->assertStringNotContainsString('fonts.googleapis.com', $html, $name.' loads no Google font');
            $this->assertStringNotContainsString('fonts.gstatic.com', $html, $name);
            foreach (['Fraunces', 'Sora', 'Space Mono', 'Hanken', 'Archivo', 'Big Shoulders', 'Figtree'] as $face) {
                $this->assertStringNotContainsString($face, $html, $name.' still names '.$face);
            }
        }

        $this->assertFileExists(public_path('dot-ui/fonts/roboto/roboto-latin-wght-normal.woff2'));
        $this->assertFileExists(public_path('dot-ui/fonts/roboto/roboto-latin-ext-wght-normal.woff2'));
    }

    public function test_the_app_stylesheets_name_no_other_text_face_and_map_roboto(): void
    {
        $other = ['Hanken', 'Fraunces', 'Sora', 'Space Mono', 'Figtree', 'Archivo', 'Big Shoulders'];
        foreach (glob(resource_path('css/*.css')) as $css) {
            $source = (string) file_get_contents($css);
            foreach ($other as $face) {
                $this->assertStringNotContainsString($face, $source, basename($css).' names '.$face);
            }
        }
        $this->assertFileDoesNotExist(resource_path('fonts/hanken-grotesk.woff2'));

        $chrome = (string) file_get_contents(resource_path('css/dot-chrome.css'));
        $this->assertStringContainsString('var(--dot-font-sans', $chrome, 'the page face is the shared Roboto token');
        $this->assertStringContainsString('--mono: var(--dot-font-mono', $chrome, 'cell references and formulas keep a system monospace');
        $this->assertStringContainsString("family: 'Roboto'", (string) file_get_contents(resource_path('js/grid/charts.js')));
    }

    public function test_the_policy_names_no_font_host_any_more(): void
    {
        $policy = (string) $this->get('/')->headers->get('Content-Security-Policy');

        $this->assertStringNotContainsString('fonts.g', $policy);
        $this->assertMatchesRegularExpression("/font-src 'self' data:;/", $policy);
    }

    public function test_no_stylesheet_or_view_reaches_for_an_external_font(): void
    {
        foreach ([resource_path('views'), resource_path('css'), public_path('dot-ui/css')] as $dir) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if (! preg_match('/\.(php|css)$/', $file->getFilename())) {
                    continue;
                }
                $this->assertDoesNotMatchRegularExpression('#fonts\.(googleapis|gstatic)\.com#', (string) file_get_contents($file->getPathname()), $file->getPathname());
            }
        }
    }

    // ── The shared header on every page type ────────────────────────────────

    public function test_every_signed_in_page_has_the_shared_header_with_search_switcher_and_account(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $sheet = $this->sheetFor($user, 'Budget');
        $this->actingAs($user);

        foreach ([
            'dashboard' => route('dashboard'),
            'create' => route('spreadsheets.create'),
            'activity' => route('activity'),
            'profile' => route('profile.show'),
            'team' => route('teams.show', $user->currentTeam),
            'editor' => route('spreadsheets.show', $sheet),
            'classic' => route('spreadsheets.classic', $sheet),
        ] as $name => $url) {
            $html = $this->get($url)->assertOk()->getContent();
            $this->assertSame(1, substr_count($html, '<header class="dh '), $name.': one header');
            $this->assertStringContainsString('class="dsr-input"', $html, $name.': the search');
            $this->assertStringContainsString('data-dsw-trigger', $html, $name.': the switcher');
            $this->assertStringContainsString('data-dh-account', $html, $name.': the account menu');
            $this->assertStringContainsString('data-dh-menu-button', $html, $name.': the phone menu button');
            $this->assertStringContainsString('class="dh-drawer', $html, $name.': the phone drawer');
            $this->assertStringContainsString('data-dh-search-toggle', $html, $name.': the phone search sheet');
            $this->assertSame(1, substr_count($html, '<h1'), $name.': exactly one h1');
        }
    }

    public function test_the_primary_items_are_few_and_the_rest_sit_in_a_named_group(): void
    {
        $operator = User::factory()->withPersonalTeam()->create(['is_platform_operator' => true]);
        $html = $this->actingAs($operator)->get(route('dashboard'))->assertOk()->getContent();

        preg_match('/<nav class="dh-nav".*?<\/nav>/s', $html, $nav);
        preg_match_all('/<li class="dh-nav__item"[^>]*>\s*<a class="dh-nav__link" href="[^"]+"/', $nav[0], $plain);
        $this->assertLessThanOrEqual(6, count($plain[0]), 'at most six plain items in the bar');
        $this->assertStringContainsString('data-dh-label="Workspace"', $nav[0]);
        $this->assertStringContainsString('aria-current="page"', $nav[0], 'the current page is marked');
        $this->assertStringContainsString('Backups', $nav[0], 'operator items are in the group');

        // No page is listed twice in the bar, and the footer tab strip is gone.
        $this->assertStringNotContainsString('aria-label="Pages"', $html);
        $hrefs = [];
        preg_match_all('/<a class="dh-nav__link" href="([^"]+)"/', $nav[0], $found);
        foreach ($found[1] as $href) {
            $this->assertNotContains($href, $hrefs);
            $hrefs[] = $href;
        }

        $member = User::factory()->withPersonalTeam()->create();
        $quiet = $this->actingAs($member)->get(route('dashboard'))->assertOk()->getContent();
        $this->assertStringNotContainsString('Backups', $quiet, 'a person who is not an operator never sees Backups');
    }

    public function test_the_account_menu_holds_the_profile_the_team_switch_and_sign_out(): void
    {
        $user = User::factory()->withPersonalTeam()->create(['name' => 'Naledi Mokoena']);
        $second = Team::forceCreate(['user_id' => $user->id, 'name' => 'Second Co', 'personal_team' => false]);
        $user->ownedTeams()->save($second);
        $html = $this->actingAs($user->fresh())->get(route('dashboard'))->getContent();

        preg_match('/<div class="dh-account".*?<\/div>\s*<\/div>/s', $html, $menu);
        $this->assertStringContainsString('Naledi Mokoena', $menu[0]);
        $this->assertStringContainsString(route('profile.show'), $menu[0]);
        $this->assertStringContainsString('Switch team', $menu[0]);
        $this->assertStringContainsString('name="team_id"', $menu[0]);
        $this->assertStringContainsString('name="_method" value="PUT"', $menu[0]);
        $this->assertStringContainsString(route('logout'), $menu[0]);
    }

    public function test_the_editor_header_holds_the_name_and_every_page_is_in_the_account_menu(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $sheet = $this->sheetFor($user, 'Fuel log');
        $html = $this->actingAs($user)->get(route('spreadsheets.show', $sheet))->assertOk()->getContent();

        $this->assertStringContainsString('class="dh-status"', $html);
        $this->assertStringContainsString('<h1 class="dh-status__text sheet-name">Fuel log</h1>', $html);
        $this->assertStringContainsString('dh--nonav', $html, 'the name has the room: no primary nav in the editor bar');
        preg_match('/<div class="dh-account".*?<\/div>\s*<\/div>/s', $html, $menu);
        foreach ([route('dashboard'), route('activity'), route('profile.show')] as $href) {
            $this->assertStringContainsString($href, $menu[0], 'reachable from the editor: '.$href);
        }
    }

    public function test_the_editor_tools_row_keeps_every_action_and_the_formula_line(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $sheet = $this->sheetFor($user, 'Fuel log');
        $html = $this->actingAs($user)->get(route('spreadsheets.show', $sheet))->assertOk()->getContent();

        foreach (['id="line"', 'id="ref"', 'id="do-comments"', 'id="do-share"', 'id="open-palette"', 'id="do-explain"', 'id="do-more"', 'id="formatbar"', 'id="more-btn"', 'data-more', 'href="'.route('spreadsheets.classic', $sheet).'"'] as $needle) {
            $this->assertStringContainsString($needle, $html, $needle);
        }
        $this->assertStringContainsString('class="fmt-word">Format<', $html, 'the phone treatment: a labelled Format button opens the toolbar sheet');
        $this->assertStringContainsString('enterkeyhint="done"', $html);
    }

    public function test_no_inline_style_or_script_in_the_shared_header(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $html = $this->actingAs($user)->get(route('dashboard'))->getContent();

        preg_match('/<header class="dh .*?<\/header>/s', $html, $header);
        $this->assertStringNotContainsString(' style=', $header[0]);
        $this->assertStringNotContainsString('<script', $header[0]);
        $this->assertDoesNotMatchRegularExpression('/\son[a-z]+=/', $header[0]);
    }

    public function test_a_failing_switcher_never_prints_nothing(): void
    {
        config(['ecosystem.infodot.redeem_url' => 'not a url', 'ecosystem.platforms.infodot.url' => '']);
        $user = User::factory()->withPersonalTeam()->create();

        $html = $this->actingAs($user)->get(route('dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString('data-dsw-trigger', $html);
    }

    // ── The dashboard on a phone keeps its data and its sorting ─────────────

    public function test_each_ledger_cell_carries_its_label_for_the_card_view_and_the_sort_buttons_stay(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $this->sheetFor($user, 'Fuel log');
        $html = $this->actingAs($user)->get(route('dashboard'))->assertOk()->getContent();

        foreach (['Tabs', 'Cells', 'Formulas', 'Edited', 'Owner'] as $label) {
            $this->assertStringContainsString('data-label="'.$label.'"', $html);
        }
        foreach (['name', 'sheetCount', 'cellCount', 'formulaCount', 'editedAt', 'owner'] as $key) {
            $this->assertStringContainsString('data-sort="'.$key.'"', $html);
        }
    }

    // ── The header search, server half: reach, isolation, limits ───────────

    public function test_search_needs_a_signed_in_person(): void
    {
        $this->getJson(route('search', ['q' => 'fuel']))->assertUnauthorized();
    }

    public function test_search_finds_what_a_person_can_reach_by_name_only(): void
    {
        $me = User::factory()->withPersonalTeam()->create();
        $stranger = User::factory()->withPersonalTeam()->create();
        $mine = $this->sheetFor($me, 'Fuel log');
        $this->sheetFor($stranger, 'Fuel secrets');
        $sharedWithMe = $this->sheetFor($stranger, 'Fuel shared');
        $sharedWithMe->sharedUsers()->attach($me->id, ['permission' => 'view']);

        $json = $this->actingAs($me)->getJson(route('search', ['q' => 'fuel']))->assertOk()->json();

        $names = array_column($json['spreadsheets'], 'label');
        $this->assertEqualsCanonicalizing(['Fuel log', 'Fuel shared'], $names);
        $this->assertNotContains('Fuel secrets', $names);
        $this->assertContains(route('spreadsheets.show', $mine), array_column($json['spreadsheets'], 'href'));
        $this->assertSame(['query', 'spreadsheets', 'tabs'], array_keys($json), 'nothing else leaves: no ids, no owners, no contents');
    }

    public function test_search_never_crosses_companies(): void
    {
        $acme = User::factory()->withPersonalTeam()->create();
        $acmeTeam = Team::forceCreate(['user_id' => $acme->id, 'name' => 'Acme', 'personal_team' => false]);
        $globex = User::factory()->withPersonalTeam()->create();
        $globexTeam = Team::forceCreate(['user_id' => $globex->id, 'name' => 'Globex', 'personal_team' => false]);
        $this->sheetFor($acme, 'Payroll Acme', $acmeTeam);
        $this->sheetFor($globex, 'Payroll Globex', $globexTeam);

        $acmeSees = array_column($this->actingAs($acme->fresh())->getJson(route('search', ['q' => 'payroll']))->json('spreadsheets'), 'label');
        $globexSees = array_column($this->actingAs($globex->fresh())->getJson(route('search', ['q' => 'payroll']))->json('spreadsheets'), 'label');

        $this->assertSame(['Payroll Acme'], $acmeSees);
        $this->assertSame(['Payroll Globex'], $globexSees);

        // A teammate reaches the team's workbook; someone in neither team does not.
        $teammate = User::factory()->withPersonalTeam()->create();
        $acmeTeam->users()->attach($teammate, ['role' => 'editor']);
        $teammate->switchTeam($acmeTeam);
        $this->assertSame(['Payroll Acme'], array_column($this->actingAs($teammate->fresh())->getJson(route('search', ['q' => 'payroll']))->json('spreadsheets'), 'label'));
    }

    public function test_search_finds_sheet_tabs_of_reachable_workbooks_and_skips_hidden_and_foreign_ones(): void
    {
        $me = User::factory()->withPersonalTeam()->create();
        $stranger = User::factory()->withPersonalTeam()->create();
        $book = $this->sheetFor($me, 'Budget');
        $tab = $this->tab($book, 'Diesel costs');
        $this->tab($book, 'Diesel hidden', hidden: true);
        $this->tab($this->sheetFor($stranger, 'Their book'), 'Diesel theirs');

        $json = $this->actingAs($me)->getJson(route('search', ['q' => 'diesel']))->assertOk()->json();

        $this->assertCount(1, $json['tabs']);
        $this->assertSame('Diesel costs', $json['tabs'][0]['label']);
        $this->assertSame('Budget', $json['tabs'][0]['in']);
        $this->assertSame(route('spreadsheets.show', $book).'?tab='.$tab->id, $json['tabs'][0]['href']);
    }

    public function test_search_treats_wildcards_and_quotes_as_text_and_caps_the_answer(): void
    {
        $me = User::factory()->withPersonalTeam()->create();
        foreach (range(1, 9) as $i) {
            $this->sheetFor($me, 'Report '.$i);
        }
        $this->sheetFor($me, "O'Brien 100% done");
        $this->actingAs($me);

        $this->assertSame([], $this->getJson(route('search', ['q' => '%%']))->json('spreadsheets'), 'a percent sign is a character, not "everything"');
        $this->assertSame([], $this->getJson(route('search', ['q' => '__']))->json('spreadsheets'));
        $this->assertSame(["O'Brien 100% done"], array_column($this->getJson(route('search', ['q' => "o'brien 100%"]))->json('spreadsheets'), 'label'));
        $this->assertCount(6, $this->getJson(route('search', ['q' => 'report']))->json('spreadsheets'), 'capped');
        $this->assertSame(['query' => 'a', 'spreadsheets' => [], 'tabs' => []], $this->getJson(route('search', ['q' => 'a']))->json(), 'under two letters asks the database nothing');
        $this->assertSame(80, mb_strlen($this->getJson(route('search', ['q' => str_repeat('x', 500)]))->json('query')), 'the query is capped');
    }

    public function test_search_is_throttled(): void
    {
        $me = User::factory()->withPersonalTeam()->create();
        $this->actingAs($me);
        for ($i = 0; $i < 60; $i++) {
            $this->getJson(route('search', ['q' => 'ab']))->assertOk();
        }

        $this->getJson(route('search', ['q' => 'ab']))->assertStatus(429);
    }

    public function test_search_follows_the_company_entitlement_gate(): void
    {
        $me = User::factory()->withPersonalTeam()->create();
        $route = app('router')->getRoutes()->getByName('search');

        $this->assertContains(EnsureCompanyEntitled::class, $route->gatherMiddleware(), 'a company that no longer holds Dot.Sheet gets no search either');
        $this->assertNotNull($me);
    }
}
