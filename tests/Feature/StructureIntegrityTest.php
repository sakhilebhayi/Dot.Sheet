<?php

namespace Tests\Feature;

use App\Jobs\BulkPasteChunkJob;
use App\Jobs\ImportRowsChunkJob;
use App\Models\Cell;
use App\Models\SheetTab;
use App\Models\Spreadsheet;
use App\Models\User;
use App\Services\StructureVersion;
use Illuminate\Bus\Batchable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Second review round: the version lives in its own column, sort and tab changes move it too, the
 * check and the write share one transaction, and queued work goes through the real batch path.
 */
class StructureIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Spreadsheet $spreadsheet;

    private SheetTab $tab;

    protected function setUp(): void
    {
        parent::setUp();

        config(['sheet.structure_ops_enabled' => true]);

        $this->user = User::factory()->withPersonalTeam()->create();
        $this->spreadsheet = Spreadsheet::create(['uuid' => (string) str()->uuid(), 'owner_id' => $this->user->id, 'name' => 'Integrity']);
        $this->tab = $this->spreadsheet->defaultSheetTab();
    }

    private function cell(int $row, int $col, ?string $raw, ?SheetTab $tab = null): Cell
    {
        return Cell::create(['spreadsheet_id' => $this->spreadsheet->id, 'sheet_tab_id' => ($tab ?? $this->tab)->id, 'row_index' => $row, 'col_index' => $col, 'raw_value' => $raw, 'updated_at' => now()]);
    }

    private function raw(int $row, int $col): ?string
    {
        return Cell::where('sheet_tab_id', $this->tab->id)->where('row_index', $row)->where('col_index', $col)->value('raw_value');
    }

    private function version(): int
    {
        return StructureVersion::current($this->spreadsheet->id);
    }

    // ---- the counter is its own column ------------------------------------------------------------

    public function test_the_counter_is_a_column_that_mass_assignment_and_settings_writes_cannot_touch(): void
    {
        $this->assertSame(0, $this->version());
        $stale = Spreadsheet::find($this->spreadsheet->id);   // loaded before the change

        StructureVersion::bump($this->spreadsheet->id);
        StructureVersion::bump($this->spreadsheet->id);

        // A writer of the settings JSON working from an old copy cannot wind the counter back.
        $stale->settings = ['row_heights' => [1 => 40]];
        $stale->save();
        $stale->fill(['structure_version' => 0, 'name' => 'renamed'])->save();

        $this->assertSame(2, $this->version());
        $this->assertSame('renamed', $this->spreadsheet->fresh()->name);
    }

    public function test_update_settings_merges_into_the_stored_json(): void
    {
        $this->spreadsheet->update(['settings' => ['scripts' => [['id' => 's']], 'row_heights' => [0 => 10]]]);

        Spreadsheet::updateSettings($this->spreadsheet->id, function (array $settings) {
            $settings['filter_rules'] = ['x'];

            return $settings;
        });

        $settings = $this->spreadsheet->fresh()->settings;
        $this->assertSame([['id' => 's']], $settings['scripts']);
        $this->assertSame(['x'], $settings['filter_rules']);
        $this->assertSame([0 => 10], $settings['row_heights']);
    }

    public function test_a_request_cannot_replace_the_settings_json_or_set_scripts(): void
    {
        $this->spreadsheet->update(['settings' => ['scripts' => [['id' => 'kept', 'name' => 'K', 'body' => 'return [];']], 'macros' => [['id' => 'm']], 'ai_workflows' => [['x']]]]);
        StructureVersion::bump($this->spreadsheet->id);

        foreach ([false, true] as $flag) {
            config(['sheet.scripts_enabled' => $flag]);

            $this->actingAs($this->user)->put(route('spreadsheets.update', $this->spreadsheet), [
                'name' => 'Still named',
                'settings' => ['scripts' => [['id' => 'evil', 'name' => 'E', 'body' => 'fetch("//x")']], 'macros' => [], 'ai_workflows' => [], 'structure_version' => 0, 'row_heights' => [3 => 55]],
            ])->assertRedirect();

            $settings = $this->spreadsheet->fresh()->settings;
            $this->assertSame('kept', $settings['scripts'][0]['id'], "flag {$flag}");
            $this->assertSame([['id' => 'm']], $settings['macros']);
            $this->assertSame([['x']], $settings['ai_workflows']);
            $this->assertArrayNotHasKey('row_heights', $settings);
            $this->assertSame([3 => 55], $this->spreadsheet->defaultSheetTab()->layout['row_heights']);
            $this->assertArrayNotHasKey('structure_version', $settings);
            $this->assertSame(1, $this->version());
        }
    }

    // ---- sort ---------------------------------------------------------------------------------------

    // ---- one transaction for check and write ---------------------------------------------------------

    // ---- tabs and restores ------------------------------------------------------------------------------

    // ---- queued work through the real batch path -----------------------------------------------------------

    public function test_a_csv_with_three_thousand_rows_imports_end_to_end_through_a_real_batch(): void
    {
        $lines = [];
        for ($i = 0; $i < 3000; $i++) {
            $lines[] = "row{$i},{$i},\"quoted, {$i}\"";
        }

        $response = $this->actingAs($this->user)
            ->post(route('spreadsheets.import', $this->spreadsheet), ['file' => UploadedFile::fake()->createWithContent('big.csv', implode("\n", $lines))])
            ->assertOk()
            ->assertJson(['success' => true, 'queued' => true, 'chunks' => 6]);

        $batchId = $response->json('batch_id');
        $this->assertSame(9000, Cell::where('sheet_tab_id', $this->tab->id)->count());
        $this->assertSame('row2999', $this->raw(2999, 0));
        $this->assertSame('quoted, 0', $this->raw(0, 2));

        $this->actingAs($this->user)->getJson(route('spreadsheets.import.batch-status', [$this->spreadsheet, $batchId]))
            ->assertOk()->assertJson(['status' => 'finished', 'failed' => 0, 'total' => 6]);
    }

    public function test_the_import_job_is_batchable(): void
    {
        $this->assertContains(Batchable::class, class_uses_recursive(ImportRowsChunkJob::class));
        $this->assertContains(Batchable::class, class_uses_recursive(BulkPasteChunkJob::class));
    }
}
