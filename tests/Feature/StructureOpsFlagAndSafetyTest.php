<?php

namespace Tests\Feature;

use App\Jobs\BulkPasteChunkJob;
use App\Jobs\ImportRowsChunkJob;
use App\Models\Cell;
use App\Models\SheetTab;
use App\Models\Spreadsheet;
use App\Models\User;
use App\Services\SheetStructureService;
use App\Services\StructureChangeRefused;
use App\Services\StructureVersion;
use App\Services\WriteLock;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Third review round. Insert/delete of rows and columns is behind sheet.structure_ops_enabled (off by
 * default); everything else here holds with the flag on or off.
 */
class StructureOpsFlagAndSafetyTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Spreadsheet $spreadsheet;

    private SheetTab $tab;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->withPersonalTeam()->create();
        $this->spreadsheet = Spreadsheet::create(['uuid' => (string) str()->uuid(), 'owner_id' => $this->user->id, 'name' => 'Round three']);
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

    private function lockTimeoutOn(string $sqlStart): void
    {
        DB::listen(function (QueryExecuted $query) use ($sqlStart) {
            if (str_starts_with($query->sql, $sqlStart)) {
                throw new \PDOException('SQLSTATE[HY000]: General error: 5 database is locked');
            }
        });
    }

    // ---- the flag ----------------------------------------------------------------------------------

    public function test_the_switch_is_off_by_default(): void
    {
        $this->assertFalse(config('sheet.structure_ops_enabled'));
    }

    public function test_the_environment_files_carry_the_switch_off(): void
    {
        foreach (['.env.example', '.env.production.example'] as $file) {
            $this->assertStringContainsString("SHEET_STRUCTURE_OPS_ENABLED=false\n", file_get_contents(base_path($file)));
        }
    }

    // ---- layout settings --------------------------------------------------------------------------------

    public function test_layout_settings_follow_the_move_inside_the_same_transaction(): void
    {
        config(['sheet.structure_ops_enabled' => true]);
        $this->cell(0, 0, 'x');
        $layout = [
            'row_heights' => [3 => 40, 6 => 50], 'hidden_rows' => [2, 5],
            'col_widths' => [1 => 120], 'hidden_cols' => [4],
            'validation_rules' => [['id' => 'v', 'row' => 4, 'col' => 1], ['id' => 'w', 'row' => 2, 'col' => 0]],
            'conditional_formatting_rules' => [['id' => 'c', 'row' => 7, 'col' => 2]],
            'filter_rules' => [['id' => 'f', 'col' => 3], ['id' => 'g', 'col' => 1]],
        ];
        $this->tab->forceFill(['layout' => $layout])->save();
        // Another sheet of the workbook keeps its own layout through the move.
        $other = SheetTab::create(['spreadsheet_id' => $this->spreadsheet->id, 'name' => 'Other', 'sort_order' => 9]);
        $other->forceFill(['layout' => $layout])->save();
        $this->spreadsheet->update(['settings' => ['scripts' => [['id' => 'keep']]]]);
        $service = app(SheetStructureService::class);

        $service->insertRow($this->spreadsheet->id, $this->tab->id, 2);
        $settings = $this->tab->fresh()->layout;
        $this->assertSame([4 => 40, 7 => 50], $settings['row_heights']);
        $this->assertSame([3, 6], $settings['hidden_rows']);
        $this->assertSame([5, 3], array_column($settings['validation_rules'], 'row'));
        $this->assertSame([8], array_column($settings['conditional_formatting_rules'], 'row'));
        $this->assertSame([['id' => 'keep']], $this->spreadsheet->fresh()->settings['scripts']);

        $service->deleteColumn($this->spreadsheet->id, $this->tab->id, 1);
        $settings = $this->tab->fresh()->layout;
        $this->assertSame([], $settings['col_widths']);                 // the deleted column's width is gone
        $this->assertSame([3], $settings['hidden_cols']);
        $this->assertSame(['w'], array_column($settings['validation_rules'], 'id') === ['v', 'w'] ? ['w'] : array_column($settings['validation_rules'], 'id'));
        $this->assertSame([2], array_column($settings['filter_rules'], 'col'));
        $this->assertSame(2, $this->version());
        $this->assertSame($layout, $other->fresh()->layout);
    }

    public function test_a_refused_change_leaves_the_layout_settings_alone_too(): void
    {
        config(['sheet.structure_ops_enabled' => true]);
        $this->cell(0, 0, 'x');
        $this->tab->forceFill(['layout' => ['row_heights' => [3 => 40], 'hidden_rows' => [2]]])->save();

        try {
            (new SheetStructureService)->withBudget(SheetStructureService::WORK_BUDGET, -1.0)->insertRow($this->spreadsheet->id, $this->tab->id, 0);
        } catch (StructureChangeRefused) {
        }

        $this->assertSame([3 => 40], $this->tab->fresh()->layout['row_heights']);
        $this->assertSame([2], $this->tab->fresh()->layout['hidden_rows']);
    }

    // ---- running batches and queued work ------------------------------------------------------------------------

    private function runningBatch(string $name, array $overrides = []): void
    {
        DB::table('job_batches')->insert(array_merge([
            'id' => (string) str()->uuid(), 'name' => $name, 'total_jobs' => 3, 'pending_jobs' => 2, 'failed_jobs' => 0,
            'failed_job_ids' => '[]', 'options' => '', 'cancelled_at' => null, 'created_at' => time(), 'finished_at' => null,
        ], $overrides));
    }

    public function test_a_queued_import_is_cancelled_with_a_message_when_the_structure_moved_meanwhile(): void
    {
        config(['queue.default' => 'database']);
        $lines = array_map(fn ($i) => "row{$i},{$i},x{$i}", range(0, 1999));   // 6,000 cells: at the inline limit, so queued

        $batchId = $this->actingAs($this->user)
            ->post(route('spreadsheets.import', $this->spreadsheet), ['file' => UploadedFile::fake()->createWithContent('big.csv', implode("\n", $lines))])
            ->assertOk()->assertJson(['queued' => true])->json('batch_id');

        StructureVersion::bump($this->spreadsheet->id);   // rows moved before the worker got there
        Artisan::call('queue:work', ['--stop-when-empty' => true, '--memory' => 4096, '--quiet' => true]);

        $this->assertSame(0, Cell::count());
        $this->actingAs($this->user)->getJson(route('spreadsheets.import.batch-status', [$this->spreadsheet, $batchId]))
            ->assertOk()->assertJson(['status' => 'cancelled', 'message' => 'The import was cancelled because rows or columns moved while it was running. Part of it may already be on the sheet: check it and import again.']);
    }

    public function test_a_failed_import_chunk_ends_the_batch_and_says_how_far_it_got(): void
    {
        config(['queue.default' => 'database']);
        $good = new ImportRowsChunkJob($this->spreadsheet->id, $this->tab->id, [['one'], ['two']], 0);
        $bad = new ImportRowsChunkJob($this->spreadsheet->id, 987654, [['three']], 500);   // no such tab: the foreign key refuses it
        $batch = Bus::batch([$good, $bad])->name("import:spreadsheet:{$this->spreadsheet->id}")->allowFailures()->dispatch();
        Cache::put("import:{$batch->id}:rows", 1000, 3600);
        Artisan::call('queue:work', ['--stop-when-empty' => true, '--memory' => 4096, '--quiet' => true, '--tries' => 1]);
        $this->actingAs($this->user)->getJson(route('spreadsheets.import.batch-status', [$this->spreadsheet, $batch->id]))
            ->assertOk()
            ->assertJson(['status' => 'failed', 'failed' => 1, 'message' => 'Import failed after 500 of 1000 rows; the rows already written are kept.']);

        $this->assertSame('one', $this->raw(0, 0));
        $this->assertTrue(Bus::findBatch($batch->id)->cancelled());
    }

    public function test_a_paste_chunk_is_all_or_nothing_and_checked_in_the_same_transaction(): void
    {
        $version = $this->version();
        $job = new BulkPasteChunkJob($this->spreadsheet->id, $this->tab->id, [
            ['row_index' => 0, 'col_index' => 0, 'value' => 'one'],
            ['row_index' => 1, 'col_index' => 0, 'value' => 'two'],
            ['row_index' => 2, 'col_index' => 0, 'value' => 'three'],
        ], $this->user->id, $version);

        // The chunk writes its cells in one statement and then their history (EA-4): a failure at the history
        // must take the cells back with it.
        DB::listen(function (QueryExecuted $query) {
            if (str_starts_with($query->sql, 'insert into "cell_history"')) {
                throw new \RuntimeException('boom');
            }
        });

        try {
            $job->handle();
        } catch (\Throwable) {
        }

        $this->assertSame(0, Cell::count(), 'the cells written before the failure were rolled back with it');
    }

    // ---- polite lock timeouts everywhere ---------------------------------------------------------------------------

    public function test_the_write_lock_helper_turns_only_lock_timeouts_into_the_busy_answer(): void
    {
        $this->assertSame('busy', WriteLock::guard(fn () => throw new \PDOException('SQLSTATE[HY000]: General error: 5 database is locked'), fn () => 'busy'));
        $this->assertSame('ok', WriteLock::guard(fn () => 'ok', fn () => 'busy'));
        $this->expectException(\PDOException::class);
        WriteLock::guard(fn () => throw new \PDOException('disk I/O error'), fn () => 'busy');
    }

    // ---- small fixes found on the way --------------------------------------------------------------------------------

    public function test_the_stale_wording_covers_sorts_and_restores_too(): void
    {
        $this->assertStringContainsString('changed elsewhere', StructureVersion::STALE_MESSAGE);
        $this->assertStringContainsString('sorted', StructureVersion::STALE_MESSAGE);
        $this->assertStringContainsString('repeat your change', StructureVersion::STALE_MESSAGE);
    }

    public function test_long_text_in_referenced_cells_does_not_bloat_the_recompute_and_reads_as_zero(): void
    {
        config(['sheet.structure_ops_enabled' => true]);
        $this->cell(0, 1, str_repeat('long text ', 50));
        $this->cell(1, 1, '5');
        Cell::create(['spreadsheet_id' => $this->spreadsheet->id, 'sheet_tab_id' => $this->tab->id, 'row_index' => 0, 'col_index' => 0, 'raw_value' => '', 'computed_value' => 'old', 'formula' => '=SUM(B1:B2)', 'updated_at' => now()]);
        Cell::where('row_index', 0)->where('col_index', 1)->update(['row_index' => 5]);   // keep A1 free
        Cell::where('formula', '!=', null)->update(['formula' => '=SUM(B1:B3)']);

        app(SheetStructureService::class)->insertRow($this->spreadsheet->id, $this->tab->id, 1);

        $this->assertEquals(5, Cell::where('formula', '!=', null)->value('computed_value'));
    }
}
