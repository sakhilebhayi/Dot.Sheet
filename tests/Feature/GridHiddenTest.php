<?php

namespace Tests\Feature;

use App\Models\SheetTab;
use App\Models\Spreadsheet;
use App\Models\User;
use App\Services\SheetStructureService;
use App\Services\StructureVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class GridHiddenTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Spreadsheet $sheet;

    private SheetTab $tab;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->withPersonalTeam()->create();
        $this->sheet = Spreadsheet::create(['uuid' => (string) str()->uuid(), 'owner_id' => $this->owner->id, 'team_id' => $this->owner->currentTeam->id, 'name' => 'Hidden']);
        $this->tab = $this->sheet->sheetTabs()->firstOrFail();
        $this->actingAs($this->owner);
    }

    /**
     * @param  list<int>  $hide
     * @param  list<int>  $show
     */
    private function change(string $axis, array $hide, array $show = [], ?int $version = null): TestResponse
    {
        return $this->postJson("/spreadsheets/{$this->sheet->id}/tabs/{$this->tab->id}/hidden", [
            'version' => $version ?? StructureVersion::current($this->sheet->id),
            'axis' => $axis,
            'hide' => $hide,
            'show' => $show,
        ]);
    }

    public function test_rows_hide_and_the_answer_says_how_to_show_them(): void
    {
        $this->change('row', [2, 3])->assertOk()
            ->assertJsonPath('hidden', [2, 3])
            ->assertJsonPath('undo', ['hide' => [], 'show' => [2, 3]]);

        $this->assertSame([2, 3], $this->tab->fresh()->layout['hidden_rows']);
    }

    public function test_columns_keep_their_own_list(): void
    {
        $this->change('col', [1]);
        $this->change('row', [5]);

        $this->assertSame([1], $this->tab->fresh()->layout['hidden_cols']);
        $this->assertSame([5], $this->tab->fresh()->layout['hidden_rows']);
    }

    public function test_undo_only_takes_back_what_this_change_did(): void
    {
        $this->change('row', [2]);

        $undo = $this->change('row', [2, 3])->assertOk()->json('undo');
        $this->assertSame(['hide' => [], 'show' => [3]], $undo);

        $this->change('row', $undo['hide'], $undo['show'])->assertOk()->assertJsonPath('hidden', [2]);
    }

    public function test_showing_gives_back_hidden_rows_and_undo_hides_them_again(): void
    {
        $this->change('row', [2, 3]);

        $undo = $this->change('row', [], [3])->assertOk()->assertJsonPath('hidden', [2])->json('undo');
        $this->assertSame(['hide' => [3], 'show' => []], $undo);

        $this->change('row', $undo['hide'], $undo['show'])->assertOk();
        $this->assertEqualsCanonicalizing([2, 3], $this->tab->fresh()->layout['hidden_rows']);
    }

    public function test_a_place_off_the_sheet_is_refused(): void
    {
        $this->change('row', [SheetStructureService::GRID_ROWS])->assertStatus(422);
        $this->change('col', [SheetStructureService::GRID_COLS])->assertStatus(422);
    }

    public function test_at_least_one_column_stays_showing(): void
    {
        $this->tab->forceFill(['layout' => ['hidden_cols' => range(1, SheetStructureService::GRID_COLS - 1)]])->save();

        $this->change('col', [0])->assertStatus(422)->assertJsonPath('message', 'Leave at least one showing.');

        $this->assertNotContains(0, $this->tab->fresh()->layout['hidden_cols']);
    }

    public function test_a_stale_browser_changes_nothing(): void
    {
        $this->change('row', [1], [], 999)->assertStatus(409);

        $this->assertNull($this->tab->fresh()->layout['hidden_rows'] ?? null);
    }

    public function test_only_people_who_can_edit_may_hide(): void
    {
        $this->actingAs(User::factory()->withPersonalTeam()->create());

        $this->change('row', [1])->assertForbidden();
    }

    public function test_hidden_rows_follow_rows_going_in_and_out(): void
    {
        config(['sheet.structure_ops_enabled' => true]);
        $this->change('row', [4]);

        app(SheetStructureService::class)->insertRow($this->sheet->id, $this->tab->id, 1);

        $this->assertSame([5], $this->tab->fresh()->layout['hidden_rows']);
    }
}
