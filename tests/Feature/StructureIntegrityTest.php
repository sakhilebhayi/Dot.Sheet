<?php

namespace Tests\Feature;

use App\Jobs\BulkPasteChunkJob;
use App\Jobs\ImportRowsChunkJob;
use App\Livewire\AiAnalysisPanel;
use App\Livewire\ScriptsEditor;
use App\Livewire\ShowSpreadsheet;
use App\Livewire\SpreadsheetToolbar;
use App\Livewire\VersionHistoryModal;
use App\Models\Cell;
use App\Models\SheetTab;
use App\Models\Spreadsheet;
use App\Models\SpreadsheetVersion;
use App\Models\User;
use App\Services\SheetStructureService;
use App\Services\StructureVersion;
use Illuminate\Bus\Batchable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
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

    private function editor(): Testable
    {
        return Livewire::actingAs($this->user)->test(ShowSpreadsheet::class, ['spreadsheet_id' => $this->spreadsheet->id]);
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

    public function test_the_scripts_and_ai_panels_settings_saves_leave_the_counter_alone_and_a_stale_layout_write_is_refused(): void
    {
        $editor = $this->editor();
        $scripts = Livewire::actingAs($this->user)->test(ScriptsEditor::class, ['spreadsheet_id' => $this->spreadsheet->id]);
        $ai = Livewire::actingAs($this->user)->test(AiAnalysisPanel::class, ['spreadsheet_id' => $this->spreadsheet->id]);

        StructureVersion::bump($this->spreadsheet->id);   // a structure change elsewhere, after all three loaded

        $scripts->call('saveRecordedMacro', ['actions' => [['type' => 'select', 'row' => 0, 'col' => 0]]]);
        $ai->set('workflow_name', 'Warn')->set('workflow_column', 'A')->set('workflow_operator', '>')->set('workflow_value', '1')->call('addWorkflowRule');
        // Hiding a row names a row number: with a stale grid it is refused, not written.
        $editor->call('toggleHideRowSelected')->assertSet('structureNotice', StructureVersion::STALE_MESSAGE);

        $this->assertSame(1, $this->version());
        $settings = $this->spreadsheet->fresh()->settings;
        $this->assertNotEmpty($settings['macros']);
        $this->assertNotEmpty($settings['ai_workflows']);
        $this->assertArrayNotHasKey('hidden_rows', $settings);

        // After the reload it goes through.
        $editor->call('toggleHideRowSelected')->assertSet('structureNotice', '');
        $this->assertSame([0], $this->spreadsheet->fresh()->settings['hidden_rows']);
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
            $this->assertSame([3 => 55], $settings['row_heights']);
            $this->assertArrayNotHasKey('structure_version', $settings);
            $this->assertSame(1, $this->version());
        }
    }

    // ---- sort ---------------------------------------------------------------------------------------

    public function test_a_sort_moves_the_version_clears_undo_and_refuses_a_stale_browser(): void
    {
        $this->cell(0, 1, 'b');
        $this->cell(1, 1, 'c');
        $this->cell(2, 1, 'a');
        $this->cell(2, 0, 'row-three-col-a');

        $a = $this->editor();
        $b = $this->editor();

        $a->set('editingCell', '0_1')->call('saveCell', 'z');          // A edits (0,1) ...
        $a->set('selectedCol', 1)->call('sortSelectedColumn', ['direction' => 'asc']);   // ... then sorts

        $this->assertSame(1, $this->version());
        $a->assertSet('undoStack', [])->assertSet('structureNotice', 'Undo history was cleared because rows or columns moved.');
        // Sorted by column B: a, c, z. The row that moved is intact, undo cannot touch it.
        $this->assertSame(['a', 'c', 'z'], [$this->raw(0, 1), $this->raw(1, 1), $this->raw(2, 1)]);
        $this->assertSame('row-three-col-a', $this->raw(0, 0));
        $a->call('undoAction');
        $this->assertSame(['a', 'c', 'z'], [$this->raw(0, 1), $this->raw(1, 1), $this->raw(2, 1)]);

        // B still shows the old order: its write is refused.
        $b->set('editingCell', '1_1')->call('saveCell', 'B-overwrite')->assertSet('structureNotice', StructureVersion::STALE_MESSAGE);
        $this->assertSame('c', $this->raw(1, 1));
        $this->assertSame(0, Cell::where('row_index', '>=', 200000000)->count(), 'no row left parked at the temporary index');
    }

    public function test_a_sort_that_fails_halfway_changes_nothing(): void
    {
        $this->cell(0, 0, 'b');
        $this->cell(1, 0, 'a');
        $editor = $this->editor();

        // Make the second step blow up: the whole sort is one transaction, so the first step must roll back.
        $seen = 0;
        DB::listen(function (QueryExecuted $query) use (&$seen) {
            if (str_starts_with($query->sql, 'update "cells" set "row_index"') && ++$seen === 3) {
                throw new \RuntimeException('boom');
            }
        });

        try {
            $editor->set('selectedCol', 0)->call('sortSelectedColumn', ['direction' => 'asc']);
        } catch (\Throwable) {
        }

        $this->assertSame(['b', 'a'], [$this->raw(0, 0), $this->raw(1, 0)]);
        $this->assertSame(0, $this->version());
    }

    // ---- one transaction for check and write ---------------------------------------------------------

    public function test_the_version_check_and_the_cell_write_share_one_transaction(): void
    {
        $this->cell(0, 0, 'a');
        $editor = $this->editor();
        $log = [];

        Event::listen(TransactionBeginning::class, function () use (&$log) {
            $log[] = 'begin';
        });
        Event::listen(TransactionCommitted::class, function () use (&$log) {
            $log[] = 'commit';
        });
        DB::listen(function (QueryExecuted $query) use (&$log) {
            if (str_contains($query->sql, 'structure_version')) {
                $log[] = 'check';
            } elseif (str_starts_with($query->sql, 'update "cells"') || str_starts_with($query->sql, 'insert into "cells"')) {
                $log[] = 'write';
            }
        });

        $editor->set('editingCell', '0_0')->call('saveCell', 'changed');

        $firstBegin = array_search('begin', $log, true);
        $lastCheck = array_keys(array_filter($log, fn ($e) => $e === 'check'));
        $firstWrite = array_search('write', $log, true);
        $commit = array_search('commit', $log, true);

        $this->assertNotFalse($firstBegin);
        $this->assertNotFalse($firstWrite);
        // The check that counts is after the transaction began, before the write, and the commit is after the write.
        $this->assertTrue(collect($lastCheck)->contains(fn ($i) => $i > $firstBegin && $i < $firstWrite), json_encode($log));
        $this->assertGreaterThan($firstWrite, $commit, json_encode($log));
    }

    public function test_a_notice_goes_away_after_the_next_accepted_write(): void
    {
        $a = $this->editor();
        $b = $this->editor();
        $a->set('selectedRow', 0)->call('insertRowSelected');
        $b->set('editingCell', '0_0')->call('saveCell', 'refused')->assertSet('structureNotice', StructureVersion::STALE_MESSAGE);

        $b->set('editingCell', '0_0')->call('saveCell', 'accepted')->assertSet('structureNotice', '');
    }

    public function test_the_children_take_the_version_from_the_server_not_from_the_browser(): void
    {
        $toolbar = Livewire::actingAs($this->user)->test(SpreadsheetToolbar::class, ['spreadsheet_id' => $this->spreadsheet->id]);
        StructureVersion::bump($this->spreadsheet->id);

        $toolbar->call('syncStructureVersion', 999)->assertSet('structureVersion', 1);
    }

    // ---- tabs and restores ------------------------------------------------------------------------------

    public function test_deleting_a_tab_moves_the_version_and_an_editor_on_that_tab_gets_a_plain_message(): void
    {
        $second = SheetTab::create(['spreadsheet_id' => $this->spreadsheet->id, 'name' => 'Second', 'sort_order' => 1]);
        $this->cell(0, 0, 'on-second', $second);

        $a = $this->editor();
        $b = $this->editor();
        $b->call('switchTab', $second->id);

        $a->call('deleteTab', $second->id);
        $this->assertSame(1, $this->version());
        $this->assertNull(SheetTab::find($second->id));

        // B is still on the deleted tab: no foreign key error, a sentence, and it lands on the remaining tab.
        $b->set('editingCell', '0_0')->call('saveCell', 'x')
            ->assertSet('structureNotice', StructureVersion::TAB_GONE_MESSAGE)
            ->assertSet('activeSheetTabId', $this->tab->id);
        $this->assertSame(0, Cell::where('raw_value', 'x')->count());
    }

    public function test_restoring_a_version_moves_the_version_so_open_editors_reload(): void
    {
        $this->cell(0, 0, 'now');
        $snapshot = SpreadsheetVersion::create(['spreadsheet_id' => $this->spreadsheet->id, 'sheet_tab_id' => $this->tab->id, 'user_id' => $this->user->id, 'label' => 'old', 'cells_snapshot' => [['row_index' => 5, 'col_index' => 0, 'raw_value' => 'then']]]);
        $other = $this->editor();

        Livewire::actingAs($this->user)->test(VersionHistoryModal::class, ['spreadsheet_id' => $this->spreadsheet->id, 'sheet_tab_id' => $this->tab->id])
            ->call('restoreVersion', $snapshot->id);

        $this->assertSame('then', $this->raw(5, 0));
        $this->assertSame(1, $this->version());
        $other->set('editingCell', '0_0')->call('saveCell', 'stale')->assertSet('structureNotice', StructureVersion::STALE_MESSAGE);
        $this->assertNull($this->raw(0, 0));
    }

    public function test_restoring_a_version_of_a_deleted_tab_is_a_polite_no_op(): void
    {
        $second = SheetTab::create(['spreadsheet_id' => $this->spreadsheet->id, 'name' => 'Second', 'sort_order' => 1]);
        $modal = Livewire::actingAs($this->user)->test(VersionHistoryModal::class, ['spreadsheet_id' => $this->spreadsheet->id, 'sheet_tab_id' => $second->id]);
        $second->delete();

        $modal->call('restoreVersion', 12345)->assertDispatched('structure-stale')->assertSet('open', false);
    }

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

    public function test_a_queued_paste_that_meets_moved_rows_cancels_and_says_so(): void
    {
        config(['queue.default' => 'database']);
        $editor = $this->editor();
        $matrix = implode("\n", array_map(fn ($r) => "a{$r}\tb{$r}", range(0, 29)));   // 60 cells: queued, not immediate

        $editor->call('bulkPasteFromText', $matrix);
        $this->assertSame(0, Cell::count(), 'nothing runs until a worker does');

        // Rows move before the worker gets to it.
        StructureVersion::bump($this->spreadsheet->id);
        Artisan::call('queue:work', ['--stop-when-empty' => true, '--memory' => 4096, '--quiet' => true]);

        $this->assertSame(0, Cell::count());
        $editor->call('getBulkPasteBatchStatus')
            ->assertSet('bulkPasteStatus', 'cancelled')
            ->assertSet('structureNotice', 'The paste was cancelled because rows or columns moved while it was running. Part of it may already be on the sheet: check it and paste again.');
    }

    public function test_a_queued_paste_without_a_structure_change_completes_through_the_batch(): void
    {
        config(['queue.default' => 'database']);
        $editor = $this->editor();
        $matrix = implode("\n", array_map(fn ($r) => "a{$r}\tb{$r}", range(0, 29)));

        $editor->call('bulkPasteFromText', $matrix);
        Artisan::call('queue:work', ['--stop-when-empty' => true, '--memory' => 4096, '--quiet' => true]);

        $this->assertSame(60, Cell::count());
        $editor->call('getBulkPasteBatchStatus')->assertSet('bulkPasteStatus', 'finished');
        $this->assertTrue(Bus::findBatch($editor->get('bulkPasteBatchId'))->finished());
    }

    public function test_the_import_job_is_batchable(): void
    {
        $this->assertContains(Batchable::class, class_uses_recursive(ImportRowsChunkJob::class));
        $this->assertContains(Batchable::class, class_uses_recursive(BulkPasteChunkJob::class));
    }

    public function test_the_service_refuses_a_change_above_its_work_budget_and_rolls_back_whole(): void
    {
        $rows = [];
        for ($r = 0; $r < 300; $r++) {
            $rows[] = ['spreadsheet_id' => $this->spreadsheet->id, 'sheet_tab_id' => $this->tab->id, 'row_index' => $r, 'col_index' => 0, 'raw_value' => '', 'computed_value' => '0', 'formula' => '=SUM(B1:B'.($r + 2).')', 'updated_at' => now()];
        }
        Cell::insert($rows);

        // Every range is cut by an insert at row 1: 300 recomputations of about 150 cells each. The estimate made
        // before the lock (about 7,500) fits 9,000; the recompute work found inside the transaction does not.
        $this->app->instance(SheetStructureService::class, (new SheetStructureService)->withBudget(9000, 2.0));
        $this->editor()->set('selectedRow', 1)->call('insertRowSelected')->assertSet('structureNotice', SheetStructureService::TOO_LARGE_MESSAGE);

        $this->assertSame('=SUM(B1:B2)', Cell::where('row_index', 0)->value('formula'));
        $this->assertSame(300, Cell::count());
        $this->assertSame(0, $this->version());

        // The same change fits a normal budget.
        $this->app->instance(SheetStructureService::class, new SheetStructureService);
        $this->editor()->set('selectedRow', 1)->call('insertRowSelected')->assertSet('structureNotice', '');
        $this->assertSame(1, $this->version());
    }

    public function test_the_service_aborts_when_the_clock_runs_out_inside_the_transaction(): void
    {
        $this->cell(0, 0, 'a');
        $this->cell(1, 0, 'b');

        $this->app->instance(SheetStructureService::class, (new SheetStructureService)->withBudget(SheetStructureService::WORK_BUDGET, -1.0));
        $this->editor()->set('selectedRow', 0)->call('insertRowSelected')->assertSet('structureNotice', SheetStructureService::TOO_LARGE_MESSAGE);

        $this->assertSame(['a', 'b'], [$this->raw(0, 0), $this->raw(1, 0)]);
        $this->assertSame(0, $this->version());
    }
}
