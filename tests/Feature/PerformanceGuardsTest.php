<?php

namespace Tests\Feature;

use App\Http\Controllers\SpreadsheetController;
use App\Models\Spreadsheet;
use App\Models\User;
use App\Services\SheetVersions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/** EA-4: performance guards (P-02, P-04, P-07, P-09). */
class PerformanceGuardsTest extends TestCase
{
    use RefreshDatabase;

    private function sheet(User $owner, string $name = 'Budget'): Spreadsheet
    {
        return Spreadsheet::create(['uuid' => (string) Str::uuid(), 'owner_id' => $owner->id, 'team_id' => null, 'name' => $name]);
    }

    public function test_the_list_loads_a_bounded_number_of_workbooks_and_says_so(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        foreach (range(1, SpreadsheetController::DASHBOARD_LISTED + 3) as $i) {
            DB::table('spreadsheets')->insert(['uuid' => (string) Str::uuid(), 'owner_id' => $owner->id, 'name' => 'Book '.$i, 'created_at' => now(), 'updated_at' => now()->subMinutes($i)]);
        }

        $response = $this->actingAs($owner)->get(route('dashboard'))->assertOk();

        $this->assertCount(SpreadsheetController::DASHBOARD_LISTED, $response->viewData('spreadsheets'));
        $response->assertSee('3 more are not listed here');
        $response->assertSee('Book 1')->assertDontSee('Book '.(SpreadsheetController::DASHBOARD_LISTED + 3).'<', false);
    }

    public function test_the_reach_columns_are_indexed(): void
    {
        $indexes = collect(Schema::getIndexes('spreadsheets'))->pluck('columns')->map(fn ($c) => implode(',', $c));
        $this->assertContains('owner_id,updated_at', $indexes);
        $this->assertContains('team_id,updated_at', $indexes);
        $this->assertContains('user_id', collect(Schema::getIndexes('spreadsheet_user'))->pluck('columns')->map(fn ($c) => implode(',', $c)));
    }

    public function test_a_sheet_too_large_to_copy_safely_is_refused_not_run_out_of_memory(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $sheet = $this->sheet($owner);
        $tab = $sheet->sheetTabs()->first();
        $now = now();
        foreach (array_chunk(range(0, SheetVersions::MAX_CELLS), 2000) as $rows) {
            DB::table('cells')->insert(array_map(fn ($r) => ['spreadsheet_id' => $sheet->id, 'sheet_tab_id' => $tab->id, 'row_index' => $r, 'col_index' => 0, 'raw_value' => 'x', 'updated_at' => $now], $rows));
        }

        $this->actingAs($owner)->postJson("/spreadsheets/{$sheet->id}/tabs/{$tab->id}/copies", ['label' => 'Mine'])
            ->assertStatus(422)->assertJsonPath('message', SheetVersions::TOO_LARGE_MESSAGE);
        $this->assertSame(0, DB::table('spreadsheet_versions')->count());
    }

    public function test_old_cell_history_is_pruned_but_each_cells_newest_row_stays(): void
    {
        $owner = User::factory()->create();
        $sheet = $this->sheet($owner);
        $tab = $sheet->sheetTabs()->first();
        $cell = DB::table('cells')->insertGetId(['spreadsheet_id' => $sheet->id, 'sheet_tab_id' => $tab->id, 'row_index' => 0, 'col_index' => 0, 'raw_value' => 'c', 'updated_at' => now()]);
        $quiet = DB::table('cells')->insertGetId(['spreadsheet_id' => $sheet->id, 'sheet_tab_id' => $tab->id, 'row_index' => 1, 'col_index' => 0, 'raw_value' => 'q', 'updated_at' => now()]);
        foreach (['a' => 800, 'b' => 500, 'c' => 1] as $value => $daysAgo) {
            DB::table('cell_history')->insert(['cell_id' => $cell, 'user_id' => $owner->id, 'new_value' => $value, 'created_at' => now()->subDays($daysAgo)]);
        }
        DB::table('cell_history')->insert(['cell_id' => $quiet, 'user_id' => $owner->id, 'new_value' => 'q', 'created_at' => now()->subDays(900)]);

        $this->artisan('sheet:prune-cell-history')->assertSuccessful();

        $this->assertSame(['c'], DB::table('cell_history')->where('cell_id', $cell)->pluck('new_value')->all());
        $this->assertSame(['q'], DB::table('cell_history')->where('cell_id', $quiet)->pluck('new_value')->all(), 'a cell\'s only row stays however old');
    }
}
