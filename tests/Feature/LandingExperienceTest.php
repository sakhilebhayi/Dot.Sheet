<?php

namespace Tests\Feature;

use App\Models\Spreadsheet;
use App\Models\Team;
use App\Models\User;
use App\Services\CellWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The page a person arrives at from InfoDot: the original logo, a welcome from their own record, one dominant action
 * into the real create workflow, real spreadsheets only, and the way back to InfoDot.
 */
class LandingExperienceTest extends TestCase
{
    use RefreshDatabase;

    /** The published logo artwork. If this changes, the logo file was altered or replaced. */
    private const LOGO_SHA256 = 'a6544cdfce7adb09b6871410c29acd889783b36bb1bf5757c8ac96df2a9591b6';

    private function company(User $owner, string $name): Team
    {
        $team = Team::forceCreate(['user_id' => $owner->id, 'name' => $name, 'personal_team' => false]);
        $owner->forceFill(['current_team_id' => $team->id])->save();

        return $team;
    }

    private function member(Team $team, string $name, string $role): User
    {
        $user = User::factory()->create(['name' => $name]);
        $team->users()->attach($user->id, ['role' => $role]);
        $user->forceFill(['current_team_id' => $team->id])->save();

        return $user;
    }

    /** @return list<string> the href of every element that carries the primary-action marker */
    private function primaryActions(TestResponse $response): array
    {
        preg_match_all('/<a\b[^>]*\bdata-primary-action\b[^>]*>/', (string) $response->getContent(), $tags);

        return array_map(function (string $tag) {
            preg_match('/href="([^"]+)"/', $tag, $href);

            return html_entity_decode($href[1] ?? '');
        }, $tags[0]);
    }

    public function test_the_landing_welcomes_each_role_by_first_name_and_company(): void
    {
        $owner = User::factory()->create(['name' => 'Thandi Nkosi']);
        $team = $this->company($owner, 'Khanya Mining Supplies');
        $people = [$owner, $this->member($team, 'Sipho Mokoena', 'admin'), $this->member($team, 'Lerato Dube', 'editor')];

        foreach ($people as $person) {
            $response = $this->actingAs($person)->get('/dashboard')->assertOk();

            $response->assertSee('Welcome, '.Str::before($person->name, ' ').'.');
            $response->assertSee('Dot.Sheet is where Khanya Mining Supplies keeps its figures');
            $response->assertSee('See what every formula reads');
            $this->assertCount(1, $this->primaryActions($response), "{$person->name} should see exactly one primary action");
        }
    }

    public function test_a_personal_team_is_not_named_as_a_company(): void
    {
        $user = User::factory()->withPersonalTeam()->create(['name' => 'Ayanda Zulu']);

        $response = $this->actingAs($user)->get('/dashboard')->assertOk();

        $response->assertSee('Welcome, Ayanda.');
        $response->assertSee('Dot.Sheet is where you keep your figures');
        $response->assertDontSee($user->currentTeam->name);
    }

    public function test_the_one_primary_action_leads_to_the_working_create_page(): void
    {
        $owner = User::factory()->create();
        $team = $this->company($owner, 'Umzi Freight');
        $newcomer = $this->member($team, 'Sipho Mokoena', 'editor');
        Spreadsheet::create(['uuid' => (string) Str::uuid(), 'owner_id' => $owner->id, 'team_id' => $team->id, 'name' => 'Fleet costs']);

        foreach ([$owner, $newcomer] as $person) {
            $actions = $this->primaryActions($this->actingAs($person)->get('/dashboard')->assertOk());

            $this->assertSame([route('spreadsheets.create')], $actions);
            $this->get($actions[0])->assertOk();
        }
    }

    public function test_the_logo_is_the_original_file(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        $this->actingAs($user)->get('/dashboard')->assertOk()
            ->assertSee('<img class="arrive-logo" src="'.asset('dot_sheet.png').'" alt="Dot.Sheet"', false);

        $this->assertFileExists(public_path('dot_sheet.png'));
        $this->assertSame(self::LOGO_SHA256, hash_file('sha256', public_path('dot_sheet.png')));
    }

    public function test_someone_with_no_spreadsheets_is_told_so_and_shown_the_next_step(): void
    {
        $owner = User::factory()->create();
        $newcomer = $this->member($this->company($owner, 'Umzi Freight'), 'Sipho Mokoena', 'editor');

        $response = $this->actingAs($newcomer)->get('/dashboard')->assertOk();

        $response->assertSee('Your first spreadsheet starts here');
        $response->assertSee('You have no spreadsheets yet');
        $response->assertDontSee('id="ledger-rows"', false);
        $response->assertDontSee('id="s-count"', false);
        $this->assertCount(1, $this->primaryActions($response));
    }

    public function test_the_figures_on_the_landing_are_counted_from_real_records(): void
    {
        $owner = User::factory()->create();
        $team = $this->company($owner, 'Khanya Mining Supplies');
        foreach (['Quarterly budget' => [['Rent', '12000'], ['Fuel', '4300'], ['All', '=SUM(B1:B2)']], 'Stock count' => [['Hard hat', '120']]] as $name => $rows) {
            $sheet = Spreadsheet::create(['uuid' => (string) Str::uuid(), 'owner_id' => $owner->id, 'team_id' => $team->id, 'name' => $name]);
            $edits = [];
            foreach ($rows as $r => $row) {
                foreach ($row as $c => $value) {
                    $edits[] = ['row' => $r, 'col' => $c, 'value' => $value];
                }
            }
            app(CellWriter::class)->write($sheet->id, $sheet->sheetTabs()->firstOrFail()->id, $edits, $owner->id);
        }

        $response = $this->actingAs($owner)->get('/dashboard')->assertOk();

        $response->assertSee('<b id="s-count">2</b>', false);
        $response->assertSee('<b id="s-cells">8</b>', false);
        $response->assertSee('<b id="s-formulas">1</b>', false);
        $response->assertSee('Stock count')->assertSee('Quarterly budget');
        $this->assertSame(2, substr_count((string) $response->getContent(), '<tr data-id='));

        // Outside those three counts and the rows themselves, the arrival shows no figure at all.
        preg_match('/<section class="arrive".*?<\/section>/s', (string) $response->getContent(), $arrival);
        $this->assertDoesNotMatchRegularExpression('/\d/', strip_tags(preg_replace('/<img[^>]*>/', '', $arrival[0])));
    }

    public function test_the_way_back_to_infodot_comes_from_the_ecosystem_config(): void
    {
        config(['ecosystem.platforms.infodot.url' => 'https://infodot.co.za/']);
        $user = User::factory()->withPersonalTeam()->create();

        $this->actingAs($user)->get('/dashboard')->assertOk()
            ->assertSee('<a href="https://infodot.co.za/home">InfoDot</a>', false)
            ->assertSee('<a href="https://infodot.co.za/platforms">Platforms</a>', false);
    }

    public function test_everything_the_page_offered_before_is_still_reachable(): void
    {
        $owner = User::factory()->create();
        $team = $this->company($owner, 'Khanya Mining Supplies');
        $sheet = Spreadsheet::create(['uuid' => (string) Str::uuid(), 'owner_id' => $owner->id, 'team_id' => $team->id, 'name' => 'Fleet costs']);

        $this->actingAs($owner)->get('/dashboard')->assertOk()
            ->assertSee('id="filter"', false)
            ->assertSee(route('spreadsheets.show', $sheet))
            ->assertSee(route('spreadsheets.duplicate', $sheet))
            ->assertSee('id="stage-delete"', false)
            ->assertSee('id="open-palette"', false);
    }

    public function test_the_platform_list_holds_only_current_platforms(): void
    {
        $current = ['agents', 'analytics', 'auction', 'central', 'charts', 'design', 'docs', 'dopemine', 'ehail', 'emall', 'engage', 'files', 'finance', 'forms', 'hr', 'memory', 'mines', 'notify', 'press', 'projects', 'pulse', 'sheet', 'tasks', 'tutor', 'infodot'];

        $this->assertEqualsCanonicalizing($current, array_keys(config('ecosystem.platforms')));
    }

    public function test_signed_in_pages_carry_the_official_name(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        $this->actingAs($user)->get('/dashboard')->assertOk()
            ->assertSee('<title>Your spreadsheets: Dot.Sheet</title>', false)
            ->assertDontSee('Dot.Sheets');
    }
}
