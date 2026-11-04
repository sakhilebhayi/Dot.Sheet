<?php

namespace Tests\Feature;

use App\Models\SheetTab;
use App\Models\Spreadsheet;
use App\Models\User;
use App\Services\StructureVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class GridLayoutTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Spreadsheet $sheet;

    private SheetTab $tab;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->withPersonalTeam()->create();
        $this->sheet = Spreadsheet::create(['uuid' => (string) str()->uuid(), 'owner_id' => $this->owner->id, 'team_id' => $this->owner->currentTeam->id, 'name' => 'Sizes']);
        $this->tab = $this->sheet->sheetTabs()->firstOrFail();
        $this->actingAs($this->owner);
    }

    /**
     * @param  array<int|string, int|null>  $sizes
     */
    private function resize(string $axis, array $sizes, ?int $version = null): TestResponse
    {
        return $this->postJson("/spreadsheets/{$this->sheet->id}/tabs/{$this->tab->id}/layout", [
            'version' => $version ?? StructureVersion::current($this->sheet->id),
            'axis' => $axis,
            'sizes' => (object) $sizes,
        ]);
    }

    public function test_a_size_is_saved_and_the_answer_puts_it_back(): void
    {
        $this->resize('col', [2 => 150])->assertOk()->assertJsonPath('undo.2', null);
        $this->assertSame(150, $this->tab->fresh()->layout['col_widths'][2]);

        $answer = $this->resize('col', [2 => 200])->assertOk()->assertJsonPath('undo.2', 150)->json();

        $this->resize('col', $answer['undo'])->assertOk();
        $this->assertSame(150, $this->tab->fresh()->layout['col_widths'][2]);
    }

    public function test_a_null_size_goes_back_to_the_default(): void
    {
        $this->resize('row', [4 => 60]);

        $this->resize('row', [4 => null])->assertOk()->assertJsonPath('undo.4', 60);

        $this->assertArrayNotHasKey(4, $this->tab->fresh()->layout['row_heights']);
    }

    public function test_several_can_change_together_and_others_stay(): void
    {
        $this->tab->forceFill(['layout' => ['hidden_rows' => [7], 'col_widths' => [9 => 300]]])->save();

        $this->resize('col', [1 => 120, 2 => 120])->assertOk();

        $layout = $this->tab->fresh()->layout;
        $this->assertSame([9 => 300, 1 => 120, 2 => 120], $layout['col_widths']);
        $this->assertSame([7], $layout['hidden_rows']);
    }

    public function test_sizes_have_the_classic_editors_limits(): void
    {
        $this->resize('col', [1 => 47])->assertUnprocessable();
        $this->resize('col', [1 => 421])->assertUnprocessable();
        $this->resize('row', [1 => 17])->assertUnprocessable();
        $this->resize('row', [1 => 121])->assertUnprocessable();
        $this->resize('col', [1 => '100'])->assertUnprocessable();
        $this->resize('col', [-1 => 100])->assertUnprocessable();
        $this->resize('sideways', [1 => 100])->assertUnprocessable();

        $this->assertNull($this->tab->fresh()->layout);
    }

    public function test_a_resize_against_an_old_structure_changes_nothing(): void
    {
        $version = StructureVersion::current($this->sheet->id);
        StructureVersion::bump($this->sheet->id);

        $this->resize('col', [1 => 100], $version)->assertStatus(409)->assertJsonPath('stale', true);

        $this->assertNull($this->tab->fresh()->layout);
    }

    public function test_only_someone_who_can_edit_may_resize(): void
    {
        $viewer = User::factory()->withPersonalTeam()->create();
        $this->sheet->sharedUsers()->attach($viewer->id, ['permission' => 'view']);

        $this->actingAs($viewer);
        $this->resize('col', [1 => 100])->assertForbidden();

        $this->actingAs(User::factory()->withPersonalTeam()->create());
        $this->resize('col', [1 => 100])->assertForbidden();

        $this->assertNull($this->tab->fresh()->layout);
    }

    public function test_a_tab_of_another_sheet_is_not_reached_through_this_one(): void
    {
        $other = Spreadsheet::create(['uuid' => (string) str()->uuid(), 'owner_id' => $this->owner->id, 'team_id' => $this->owner->currentTeam->id, 'name' => 'Other']);
        $theirs = $other->sheetTabs()->firstOrFail();

        $this->postJson("/spreadsheets/{$this->sheet->id}/tabs/{$theirs->id}/layout", ['version' => 0, 'axis' => 'col', 'sizes' => ['1' => 100]])->assertNotFound();
    }

    public function test_the_classic_editors_stored_sizes_are_kept_as_numbers_the_grid_reads(): void
    {
        $this->resize('col', [0 => 240, 1 => 120]);

        $this->getJson("/spreadsheets/{$this->sheet->id}/tabs/{$this->tab->id}/sheet")->assertOk()
            ->assertJsonPath('layout.col_widths.0', 240)->assertJsonPath('layout.col_widths.1', 120);
    }
}
