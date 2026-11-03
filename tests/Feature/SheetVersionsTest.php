<?php

namespace Tests\Feature;

use App\Livewire\VersionHistoryModal;
use App\Models\Cell;
use App\Models\CellHistory;
use App\Models\SheetTab;
use App\Models\Spreadsheet;
use App\Models\SpreadsheetVersion;
use App\Models\User;
use App\Services\SheetVersions;
use App\Services\StructureVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Copies of a sheet: the history list stays light, a restore can be undone and keeps cell history and
 * layout, and old automatic copies do not pile up.
 */
class SheetVersionsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Spreadsheet $spreadsheet;

    private SheetTab $tab;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->withPersonalTeam()->create();
        $this->spreadsheet = Spreadsheet::create(['uuid' => (string) str()->uuid(), 'owner_id' => $this->user->id, 'team_id' => $this->user->currentTeam->id, 'name' => 'Budget']);
        $this->tab = $this->spreadsheet->defaultSheetTab();
    }

    private function cell(int $row, int $col, ?string $value, array $extra = []): Cell
    {
        return Cell::updateOrCreate(
            ['spreadsheet_id' => $this->spreadsheet->id, 'sheet_tab_id' => $this->tab->id, 'row_index' => $row, 'col_index' => $col],
            ['raw_value' => $value, 'updated_at' => now(), ...$extra],
        );
    }

    private function value(int $row, int $col): ?string
    {
        return Cell::where('sheet_tab_id', $this->tab->id)->where('row_index', $row)->where('col_index', $col)->value('raw_value');
    }

    private function snapshot(string $kind = SheetVersions::MANUAL, string $label = 'Copy'): SpreadsheetVersion
    {
        return app(SheetVersions::class)->snapshot($this->spreadsheet->id, $this->tab->id, $this->user->id, $kind, $label);
    }

    private function modal()
    {
        return Livewire::actingAs($this->user)->test(VersionHistoryModal::class, ['spreadsheet_id' => $this->spreadsheet->id, 'sheet_tab_id' => $this->tab->id]);
    }

    public function test_the_history_list_reads_counts_not_snapshots(): void
    {
        $this->cell(0, 0, 'a');
        $this->cell(1, 0, 'b');
        $this->snapshot();

        $versions = $this->modal()->call('openModal')->get('versions');

        $this->assertSame(2, $versions[0]['cell_count']);
        $this->assertArrayNotHasKey('cells_snapshot', $versions[0]);
    }

    public function test_a_restore_changes_only_what_differs_and_keeps_cell_history(): void
    {
        $kept = $this->cell(0, 0, 'same');
        $changed = $this->cell(1, 0, 'before');
        $this->cell(2, 0, 'removed later');
        $version = $this->snapshot();

        $this->cell(1, 0, 'after');
        Cell::where('sheet_tab_id', $this->tab->id)->where('row_index', 2)->delete();
        $this->cell(3, 0, 'added after the copy');
        CellHistory::create(['cell_id' => $kept->id, 'user_id' => $this->user->id, 'old_value' => null, 'new_value' => 'same', 'created_at' => now()]);
        $kept->forceFill(['note' => 'a note the copy does not hold'])->save();

        $this->modal()->call('restoreVersion', $version->id);

        $this->assertSame(['same', 'before', 'removed later', null], [$this->value(0, 0), $this->value(1, 0), $this->value(2, 0), $this->value(3, 0)]);
        // Cells that stay keep their id, their history and what the copy does not hold.
        $this->assertSame($kept->id, Cell::where('sheet_tab_id', $this->tab->id)->where('row_index', 0)->value('id'));
        $this->assertSame($changed->id, Cell::where('sheet_tab_id', $this->tab->id)->where('row_index', 1)->value('id'));
        $this->assertSame(1, CellHistory::where('cell_id', $kept->id)->count());
        $this->assertSame('a note the copy does not hold', $kept->fresh()->note);
        // The changed value is in the history, by the person who restored.
        $this->assertTrue(CellHistory::where('cell_id', $changed->id)->where('old_value', 'after')->where('new_value', 'before')->where('user_id', $this->user->id)->exists());
        $this->assertSame(1, StructureVersion::current($this->spreadsheet->id));
    }

    public function test_a_restore_can_be_undone_with_its_safety_copy(): void
    {
        $this->cell(0, 0, 'old');
        $this->tab->forceFill(['layout' => ['col_widths' => [0 => 200]]])->save();
        $version = $this->snapshot(label: 'Monday');

        $this->cell(0, 0, 'new');
        $this->cell(5, 5, 'more');
        $this->tab->forceFill(['layout' => ['col_widths' => [0 => 90], 'hidden_rows' => [4]]])->save();

        $this->modal()->call('restoreVersion', $version->id);

        $this->assertSame('old', $this->value(0, 0));
        $this->assertNull($this->value(5, 5));
        $this->assertSame(['col_widths' => [0 => 200]], $this->tab->fresh()->layout);

        $safety = SpreadsheetVersion::where('kind', SheetVersions::SAFETY)->sole();
        $this->assertStringStartsWith('Before restoring “Monday”', $safety->label);
        $this->assertSame(2, $safety->cell_count);

        $this->modal()->call('restoreVersion', $safety->id);

        $this->assertSame('new', $this->value(0, 0));
        $this->assertSame('more', $this->value(5, 5));
        $this->assertSame(['col_widths' => [0 => 90], 'hidden_rows' => [4]], $this->tab->fresh()->layout);
    }

    public function test_a_copy_from_before_layouts_were_kept_leaves_the_layout_alone(): void
    {
        $this->cell(0, 0, 'x');
        $old = SpreadsheetVersion::create(['spreadsheet_id' => $this->spreadsheet->id, 'sheet_tab_id' => $this->tab->id, 'user_id' => $this->user->id, 'label' => 'Old', 'cells_snapshot' => [['row_index' => 0, 'col_index' => 0, 'raw_value' => 'y', 'computed_value' => null, 'formula' => null, 'formatting' => ['bold' => true]]]]);
        $this->tab->forceFill(['layout' => ['hidden_cols' => [3]]])->save();

        $this->modal()->call('restoreVersion', $old->id);

        $this->assertSame('y', $this->value(0, 0));
        $this->assertSame(['bold' => true], Cell::where('sheet_tab_id', $this->tab->id)->sole()->formatting);
        $this->assertSame(['hidden_cols' => [3]], $this->tab->fresh()->layout);
    }

    public function test_old_automatic_and_safety_copies_are_pruned_and_peoples_copies_kept(): void
    {
        $this->cell(0, 0, 'x');
        $other = SheetTab::create(['spreadsheet_id' => $this->spreadsheet->id, 'name' => 'Other', 'sort_order' => 1]);
        $make = fn (int $tabId, string $kind, int $n) => collect(range(1, $n))->each(fn ($i) => SpreadsheetVersion::create([
            'spreadsheet_id' => $this->spreadsheet->id, 'sheet_tab_id' => $tabId, 'label' => "{$kind} {$i}", 'kind' => $kind, 'cell_count' => 0, 'cells_snapshot' => [],
        ])->forceFill(['created_at' => now()->subMinutes(100 - $i)])->save());

        $make($this->tab->id, SheetVersions::AUTO, 34);
        $make($this->tab->id, SheetVersions::SAFETY, 12);
        $make($this->tab->id, SheetVersions::MANUAL, 40);
        $make($other->id, SheetVersions::AUTO, 3);

        // Taking a copy prunes its own sheet.
        $this->snapshot(SheetVersions::AUTO, 'Auto Snapshot');

        $auto = SpreadsheetVersion::where('sheet_tab_id', $this->tab->id)->where('kind', SheetVersions::AUTO);
        $this->assertSame(SheetVersions::KEEP_AUTO, (clone $auto)->count());
        $this->assertFalse((clone $auto)->where('label', 'auto 1')->exists());   // the oldest went
        $this->assertSame(SheetVersions::KEEP_SAFETY, SpreadsheetVersion::where('sheet_tab_id', $this->tab->id)->where('kind', SheetVersions::SAFETY)->count());
        $this->assertSame(40, SpreadsheetVersion::where('kind', SheetVersions::MANUAL)->count());
        $this->assertSame(3, SpreadsheetVersion::where('sheet_tab_id', $other->id)->count());

        // The nightly command does every sheet.
        $make($other->id, SheetVersions::AUTO, 30);
        $this->artisan('sheet:prune-versions')->expectsOutput('Removed 3 old copies.')->assertSuccessful();
        $this->assertSame(SheetVersions::KEEP_AUTO, SpreadsheetVersion::where('sheet_tab_id', $other->id)->count());
    }

    public function test_the_migration_marks_old_automatic_copies_and_counts_their_cells(): void
    {
        $migration = require database_path('migrations/2026_11_03_000002_add_kind_count_and_layout_to_spreadsheet_versions.php');
        $migration->down();

        DB::table('spreadsheet_versions')->insert([
            ['spreadsheet_id' => $this->spreadsheet->id, 'sheet_tab_id' => $this->tab->id, 'label' => 'Auto Snapshot', 'cells_snapshot' => json_encode([['row_index' => 0], ['row_index' => 1]]), 'created_at' => now(), 'updated_at' => now()],
            ['spreadsheet_id' => $this->spreadsheet->id, 'sheet_tab_id' => $this->tab->id, 'label' => 'Before the audit', 'cells_snapshot' => '[]', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $migration->up();

        $this->assertSame([['Auto Snapshot', 'auto', 2], ['Before the audit', 'manual', 0]], SpreadsheetVersion::orderBy('id')->get()->map(fn ($v) => [$v->label, $v->kind, $v->cell_count])->all());
    }

    public function test_restoring_a_large_sheet_stays_within_a_modest_memory_budget(): void
    {
        $rows = [];
        for ($i = 0; $i < 20000; $i++) {
            $rows[] = ['spreadsheet_id' => $this->spreadsheet->id, 'sheet_tab_id' => $this->tab->id, 'row_index' => intdiv($i, 20), 'col_index' => $i % 20, 'raw_value' => "value {$i}", 'updated_at' => now()];
        }
        foreach (array_chunk($rows, 1000) as $chunk) {
            DB::table('cells')->insert($chunk);
        }
        unset($rows, $chunk);
        $version = $this->snapshot();
        DB::table('cells')->where('sheet_tab_id', $this->tab->id)->where('row_index', '<', 500)->update(['raw_value' => 'changed']);

        gc_collect_cycles();
        $before = memory_get_usage();
        memory_reset_peak_usage();

        DB::transaction(fn () => app(SheetVersions::class)->restore($version->fresh(), $this->user->id));

        $used = (memory_get_peak_usage() - $before) / 1048576;
        $this->assertLessThan(48, $used, sprintf('restore used %.1f MB', $used));
        $this->assertSame('value 0', $this->value(0, 0));
        $this->assertSame(20000, Cell::where('sheet_tab_id', $this->tab->id)->count());
        $this->assertSame(10000, CellHistory::count());
    }
}
