<?php

namespace Tests\Feature;

use App\Models\Cell;
use App\Models\SheetTab;
use App\Models\Spreadsheet;
use App\Models\User;
use App\Services\StructureVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class GridTogetherTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $friend;

    private Spreadsheet $sheet;

    private SheetTab $tab;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->owner = User::factory()->withPersonalTeam()->create(['name' => 'Thandi']);
        $this->friend = User::factory()->withPersonalTeam()->create(['name' => 'Sipho']);
        $this->sheet = Spreadsheet::create(['uuid' => (string) Str::uuid(), 'owner_id' => $this->owner->id, 'name' => 'Shared']);
        $this->sheet->sharedUsers()->attach($this->friend->id, ['permission' => 'edit']);
        $this->tab = $this->sheet->sheetTabs()->firstOrFail();
        $this->actingAs($this->owner);
    }

    private function base(): string
    {
        return "/spreadsheets/{$this->sheet->id}/tabs/{$this->tab->id}";
    }

    /** @param array<string, mixed> $edit */
    private function edit(array $edit): TestResponse
    {
        return $this->postJson($this->base().'/cells', ['version' => StructureVersion::current($this->sheet->id), 'edits' => [['row' => 0, 'col' => 0, ...$edit]]]);
    }

    private function stored(): ?string
    {
        return Cell::where('sheet_tab_id', $this->tab->id)->where('row_index', 0)->where('col_index', 0)->value('raw_value');
    }

    public function test_typing_over_what_was_seen_is_saved(): void
    {
        $this->edit(['value' => 'First', 'was' => ''])->assertOk()->assertJsonMissingPath('conflicts');
        $this->edit(['value' => 'Second', 'was' => 'First'])->assertOk()->assertJsonMissingPath('conflicts');

        $this->assertSame('Second', $this->stored());
    }

    public function test_someone_who_got_there_first_is_not_typed_over(): void
    {
        $this->actingAs($this->friend);
        $this->edit(['value' => 'Theirs', 'was' => ''])->assertOk();

        $this->actingAs($this->owner);
        $this->edit(['value' => 'Mine', 'was' => ''])->assertOk()->assertJsonPath('conflicts.0.theirs', 'Theirs')->assertJsonPath('conflicts.0.who', 'Sipho')->assertJsonPath('conflicts.0.row', 0);

        $this->assertSame('Theirs', $this->stored());
    }

    public function test_the_same_change_from_two_people_is_no_conflict(): void
    {
        $this->actingAs($this->friend);
        $this->edit(['value' => 'Same', 'was' => ''])->assertOk();

        $this->actingAs($this->owner);
        $this->edit(['value' => 'Same', 'was' => ''])->assertOk()->assertJsonMissingPath('conflicts');
    }

    public function test_saying_nothing_about_what_was_there_writes_as_it_always_did(): void
    {
        $this->edit(['value' => 'One'])->assertOk();
        $this->edit(['value' => 'Mine after all'])->assertOk()->assertJsonMissingPath('conflicts');

        $this->assertSame('Mine after all', $this->stored());
    }

    public function test_a_formula_is_compared_by_what_it_says(): void
    {
        $this->edit(['value' => '=1+1', 'was' => ''])->assertOk();

        $this->edit(['value' => 'x', 'was' => '=1+1'])->assertOk()->assertJsonMissingPath('conflicts');
    }

    public function test_the_first_ask_reports_no_change(): void
    {
        $this->getJson($this->base().'/pulse')->assertOk()->assertJsonPath('changed', false);
    }

    public function test_a_change_since_the_last_ask_is_reported_on_any_sheet(): void
    {
        $at = $this->getJson($this->base().'/pulse')->json('at');
        $this->travel(5)->seconds();
        $this->getJson($this->base().'/pulse?since='.urlencode(now()->toDateTimeString()))->assertJsonPath('changed', false);

        $this->edit(['value' => 'New']);

        $this->getJson($this->base().'/pulse?since='.urlencode($at))->assertJsonPath('changed', true);
    }

    public function test_the_version_comes_with_it(): void
    {
        $this->getJson($this->base().'/pulse')->assertJsonPath('version', StructureVersion::current($this->sheet->id));
    }

    public function test_it_says_who_else_is_here_and_not_you(): void
    {
        $this->getJson($this->base().'/pulse')->assertJsonPath('here', []);

        $this->actingAs($this->friend);
        $this->getJson($this->base().'/pulse')->assertJsonPath('here', ['Thandi']);

        $this->actingAs($this->owner);
        $this->getJson($this->base().'/pulse')->assertJsonPath('here', ['Sipho']);
    }

    public function test_someone_who_left_stops_being_here(): void
    {
        $this->actingAs($this->friend);
        $this->getJson($this->base().'/pulse');

        $this->travel(61)->seconds();

        $this->actingAs($this->owner);
        $this->getJson($this->base().'/pulse')->assertJsonPath('here', []);
    }

    public function test_being_here_is_per_workbook(): void
    {
        $this->actingAs($this->friend);
        $this->getJson($this->base().'/pulse');

        $other = Spreadsheet::create(['uuid' => (string) Str::uuid(), 'owner_id' => $this->owner->id, 'name' => 'Other']);
        $tab = $other->sheetTabs()->firstOrFail();

        $this->actingAs($this->owner);
        $this->getJson("/spreadsheets/{$other->id}/tabs/{$tab->id}/pulse")->assertJsonPath('here', []);
    }

    public function test_a_stranger_learns_nothing(): void
    {
        $this->actingAs(User::factory()->withPersonalTeam()->create());

        $this->getJson($this->base().'/pulse')->assertForbidden();
    }
}
