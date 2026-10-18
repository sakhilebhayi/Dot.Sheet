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
use App\Services\FormulaEvaluatorService;
use App\Services\SheetStructureService;
use App\Services\StructureChangeRefused;
use App\Services\StructureVersion;
use App\Services\WriteLock;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
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

    public function test_with_the_switch_off_every_handler_and_the_service_refuse_and_nothing_moves(): void
    {
        $this->cell(0, 0, 'a');
        $this->cell(1, 0, 'b');
        $editor = $this->editor();

        foreach (['insertRowSelected', 'deleteRowSelected', 'insertColumnSelected', 'deleteColumnSelected'] as $handler) {
            $editor->set('selectedRow', 0)->set('selectedCol', 0)->call($handler)
                ->assertSet('structureNotice', 'Inserting and deleting rows and columns is not available yet.');
        }
        // The toolbar buttons and the crafted events end in the same handlers.
        Livewire::actingAs($this->user)->test(SpreadsheetToolbar::class, ['spreadsheet_id' => $this->spreadsheet->id])->call('insertRow')->assertDispatched('insert-row-selected');
        $editor->dispatch('insert-row-selected')->assertSet('structureNotice', SheetStructureService::DISABLED_MESSAGE);

        $service = app(SheetStructureService::class);
        foreach (['insertRow', 'deleteRow', 'insertColumn', 'deleteColumn'] as $method) {
            try {
                $service->{$method}($this->spreadsheet->id, $this->tab->id, 0);
                $this->fail("{$method} ran with the switch off");
            } catch (StructureChangeRefused $e) {
                $this->assertSame(SheetStructureService::DISABLED_MESSAGE, $e->getMessage());
            }
        }

        $this->assertSame(['a', 'b'], [$this->raw(0, 0), $this->raw(1, 0)]);
        $this->assertSame(0, $this->version());
    }

    public function test_the_menu_items_and_toolbar_buttons_are_hidden_with_the_switch_off_and_shown_with_it_on(): void
    {
        $this->editor()->assertDontSee('Insert Row')->assertDontSee('Delete Column')->assertSee('Hide/Unhide Row');
        Livewire::actingAs($this->user)->test(SpreadsheetToolbar::class, ['spreadsheet_id' => $this->spreadsheet->id])->assertDontSee('+Row')->assertSee('Hide Row');

        config(['sheet.structure_ops_enabled' => true]);

        $this->editor()->assertSee('Insert Row')->assertSee('Delete Column');
        Livewire::actingAs($this->user)->test(SpreadsheetToolbar::class, ['spreadsheet_id' => $this->spreadsheet->id])->assertSee('+Row');
    }

    public function test_sort_edit_import_export_and_tab_delete_work_with_the_switch_off(): void
    {
        $this->cell(0, 0, 'b');
        $this->cell(1, 0, 'a');
        $editor = $this->editor();

        $editor->set('editingCell', '5_0')->call('saveCell', 'typed');
        $editor->set('selectedCol', 0)->call('sortSelectedColumn', ['direction' => 'asc']);

        $this->assertSame(['a', 'b', 'typed'], [$this->raw(0, 0), $this->raw(1, 0), $this->raw(2, 0)]);
        $this->assertSame(1, $this->version());
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
        $this->spreadsheet->update(['settings' => [
            'row_heights' => [3 => 40, 6 => 50], 'hidden_rows' => [2, 5],
            'col_widths' => [1 => 120], 'hidden_cols' => [4],
            'validation_rules' => [['id' => 'v', 'row' => 4, 'col' => 1], ['id' => 'w', 'row' => 2, 'col' => 0]],
            'conditional_formatting_rules' => [['id' => 'c', 'row' => 7, 'col' => 2]],
            'filter_rules' => [['id' => 'f', 'col' => 3], ['id' => 'g', 'col' => 1]],
            'scripts' => [['id' => 'keep']],
        ]]);
        $service = app(SheetStructureService::class);

        $service->insertRow($this->spreadsheet->id, $this->tab->id, 2);
        $settings = $this->spreadsheet->fresh()->settings;
        $this->assertSame([4 => 40, 7 => 50], $settings['row_heights']);
        $this->assertSame([3, 6], $settings['hidden_rows']);
        $this->assertSame([5, 3], array_column($settings['validation_rules'], 'row'));
        $this->assertSame([8], array_column($settings['conditional_formatting_rules'], 'row'));
        $this->assertSame([['id' => 'keep']], $settings['scripts']);

        $service->deleteColumn($this->spreadsheet->id, $this->tab->id, 1);
        $settings = $this->spreadsheet->fresh()->settings;
        $this->assertSame([], $settings['col_widths']);                 // the deleted column's width is gone
        $this->assertSame([3], $settings['hidden_cols']);
        $this->assertSame(['w'], array_column($settings['validation_rules'], 'id') === ['v', 'w'] ? ['w'] : array_column($settings['validation_rules'], 'id'));
        $this->assertSame([2], array_column($settings['filter_rules'], 'col'));
        $this->assertSame(2, $this->version());
    }

    public function test_a_refused_change_leaves_the_layout_settings_alone_too(): void
    {
        config(['sheet.structure_ops_enabled' => true]);
        $this->cell(0, 0, 'x');
        $this->spreadsheet->update(['settings' => ['row_heights' => [3 => 40], 'hidden_rows' => [2]]]);

        try {
            (new SheetStructureService)->withBudget(SheetStructureService::WORK_BUDGET, -1.0)->insertRow($this->spreadsheet->id, $this->tab->id, 0);
        } catch (StructureChangeRefused) {
        }

        $this->assertSame([3 => 40], $this->spreadsheet->fresh()->settings['row_heights']);
        $this->assertSame([2], $this->spreadsheet->fresh()->settings['hidden_rows']);
    }

    public function test_the_editor_changes_only_the_key_it_means_and_keeps_what_others_stored(): void
    {
        $editor = $this->editor();
        // Somebody else stores a rule after this editor loaded.
        $this->spreadsheet->update(['settings' => ['validation_rules' => [['id' => 'theirs', 'row' => 1, 'col' => 1]], 'hidden_cols' => [9]]]);

        $editor->set('selectedRow', 4)->call('toggleHideRowSelected');

        $settings = $this->spreadsheet->fresh()->settings;
        $this->assertSame([4], $settings['hidden_rows']);
        $this->assertSame('theirs', $settings['validation_rules'][0]['id']);
        $this->assertSame([9], $settings['hidden_cols']);
    }

    public function test_a_layout_key_has_a_size_limit(): void
    {
        $this->actingAs($this->user)->put(route('spreadsheets.update', $this->spreadsheet), ['name' => 'x', 'settings' => ['row_heights' => array_fill(0, 40000, 100)]])->assertSessionHasErrors('settings.row_heights');

        $this->spreadsheet->update(['settings' => ['row_heights' => array_fill(0, 12000, 100)]]);
        $this->editor()->set('selectedRow', 0)->call('resizeRowSelected', ['row' => 99999, 'height' => 30])
            ->assertSet('structureNotice', 'The layout settings of this sheet are too large to add to.');
    }

    // ---- running batches and queued work ------------------------------------------------------------------------

    private function runningBatch(string $name, array $overrides = []): void
    {
        DB::table('job_batches')->insert(array_merge([
            'id' => (string) str()->uuid(), 'name' => $name, 'total_jobs' => 3, 'pending_jobs' => 2, 'failed_jobs' => 0,
            'failed_job_ids' => '[]', 'options' => '', 'cancelled_at' => null, 'created_at' => time(), 'finished_at' => null,
        ], $overrides));
    }

    public function test_an_insert_is_refused_while_an_import_or_paste_is_running_for_the_sheet(): void
    {
        config(['sheet.structure_ops_enabled' => true]);
        $this->cell(0, 0, 'a');

        $this->runningBatch("import:spreadsheet:{$this->spreadsheet->id}");
        $this->editor()->call('insertRowSelected')->assertSet('structureNotice', 'An import is running for this sheet; try again when it finishes.');
        $this->assertSame('a', $this->raw(0, 0));

        DB::table('job_batches')->delete();
        $this->runningBatch("bulk-paste:spreadsheet:{$this->spreadsheet->id}");
        $this->editor()->call('deleteRowSelected')->assertSet('structureNotice', 'A paste is running for this sheet; try again when it finishes.');

        // Finished, cancelled, another sheet's, or abandoned an hour ago: none of them blocks.
        DB::table('job_batches')->delete();
        $this->runningBatch("import:spreadsheet:{$this->spreadsheet->id}", ['finished_at' => time()]);
        $this->runningBatch("import:spreadsheet:{$this->spreadsheet->id}", ['cancelled_at' => time()]);
        $this->runningBatch('import:spreadsheet:99999');
        $this->runningBatch("import:spreadsheet:{$this->spreadsheet->id}", ['created_at' => time() - 7200]);
        $this->editor()->call('insertRowSelected')->assertSet('structureNotice', '');
        $this->assertSame('a', $this->raw(1, 0));
    }

    public function test_a_queued_import_is_cancelled_with_a_message_when_the_structure_moved_meanwhile(): void
    {
        config(['queue.default' => 'database']);
        $lines = array_map(fn ($i) => "row{$i},{$i}", range(0, 1999));

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

    public function test_a_failed_paste_chunk_is_reported_by_the_editor(): void
    {
        config(['queue.default' => 'database']);
        $editor = $this->editor();
        $editor->call('bulkPasteFromText', implode("\n", array_map(fn ($r) => "a{$r}\tb{$r}", range(0, 29))));
        // Break the chunk: its tab disappears from under it.
        DB::statement('PRAGMA foreign_keys = OFF');
        DB::table('sheet_tabs')->where('id', $this->tab->id)->delete();
        DB::statement('PRAGMA foreign_keys = ON');
        Artisan::call('queue:work', ['--stop-when-empty' => true, '--memory' => 4096, '--quiet' => true, '--tries' => 1]);

        $status = $editor->call('getBulkPasteBatchStatus')->get('bulkPasteStatus');
        $this->assertSame('failed', $status);
        $editor->assertSet('structureNotice', 'Paste failed after 0 of 60 cells; the cells already written are kept.');
    }

    public function test_a_paste_chunk_is_all_or_nothing_and_checked_in_the_same_transaction(): void
    {
        $version = $this->version();
        $job = new BulkPasteChunkJob($this->spreadsheet->id, $this->tab->id, [
            ['row_index' => 0, 'col_index' => 0, 'value' => 'one'],
            ['row_index' => 1, 'col_index' => 0, 'value' => 'two'],
            ['row_index' => 2, 'col_index' => 0, 'value' => 'three'],
        ], $this->user->id, $version);

        $seen = 0;
        DB::listen(function (QueryExecuted $query) use (&$seen) {
            if (str_starts_with($query->sql, 'insert into "cells"') && ++$seen === 3) {
                throw new \RuntimeException('boom');
            }
        });

        try {
            $job->handle();
        } catch (\Throwable) {
        }

        $this->assertSame(0, Cell::count(), 'the two cells before the failure were rolled back with it');
    }

    public function test_a_small_paste_and_script_actions_are_all_or_nothing_against_a_stale_grid(): void
    {
        $stale = $this->editor();
        StructureVersion::bump($this->spreadsheet->id);

        $stale->set('selectedRow', 0)->set('selectedCol', 0)->call('bulkPasteFromText', "a\tb\nc\td");
        $this->assertSame(0, Cell::count());
        $stale->assertSet('structureNotice', StructureVersion::STALE_MESSAGE);

        // Same for script actions, on a fresh stale editor: not one of the three lands.
        $stale2 = $this->editor();
        StructureVersion::bump($this->spreadsheet->id);
        $stale2->call('applyScriptActions', ['actions' => [
            ['type' => 'set', 'cell' => 'A1', 'value' => '1'], ['type' => 'set', 'cell' => 'A2', 'value' => '2'], ['type' => 'set', 'cell' => 'A3', 'value' => '3'],
        ]])->assertSet('structureNotice', StructureVersion::STALE_MESSAGE);
        $this->assertSame(0, Cell::count());
    }

    public function test_a_small_paste_and_script_actions_each_run_in_one_outer_transaction(): void
    {
        $levels = [];
        Event::listen(TransactionBeginning::class, function ($event) use (&$levels) {
            $levels[] = $event->connection->transactionLevel();
        });

        $editor = $this->editor();
        $levels = [];
        // Level 1 is the test's own transaction. One paste = one transaction at level 2, whatever the cell count.
        $editor->set('selectedRow', 0)->set('selectedCol', 0)->call('bulkPasteFromText', "a\tb\nc\td");
        $this->assertSame(1, count(array_filter($levels, fn ($l) => $l === 2)), json_encode($levels));

        $levels = [];
        $editor->call('applyScriptActions', ['actions' => [['type' => 'set', 'cell' => 'F1', 'value' => '1'], ['type' => 'set', 'cell' => 'F2', 'value' => '2'], ['type' => 'clear', 'cell' => 'A1']]]);
        $this->assertSame(1, count(array_filter($levels, fn ($l) => $l === 2)), json_encode($levels));
    }

    public function test_a_small_paste_on_a_current_grid_lands_whole(): void
    {
        $this->editor()->set('selectedRow', 0)->set('selectedCol', 0)->call('bulkPasteFromText', "a\tb\nc\td");

        $this->assertSame(4, Cell::count());
        $this->assertSame('d', $this->raw(1, 1));
    }

    // ---- polite lock timeouts everywhere ---------------------------------------------------------------------------

    public function test_the_write_lock_helper_turns_only_lock_timeouts_into_the_busy_answer(): void
    {
        $this->assertSame('busy', WriteLock::guard(fn () => throw new \PDOException('SQLSTATE[HY000]: General error: 5 database is locked'), fn () => 'busy'));
        $this->assertSame('ok', WriteLock::guard(fn () => 'ok', fn () => 'busy'));
        $this->expectException(\PDOException::class);
        WriteLock::guard(fn () => throw new \PDOException('disk I/O error'), fn () => 'busy');
    }

    public function test_every_writer_answers_the_busy_sentence_instead_of_an_error(): void
    {
        $this->cell(0, 0, '  padded  ');
        $snapshot = SpreadsheetVersion::create(['spreadsheet_id' => $this->spreadsheet->id, 'sheet_tab_id' => $this->tab->id, 'user_id' => $this->user->id, 'label' => 's', 'cells_snapshot' => []]);

        $toolbar = Livewire::actingAs($this->user)->test(SpreadsheetToolbar::class, ['spreadsheet_id' => $this->spreadsheet->id]);
        $ai = Livewire::actingAs($this->user)->test(AiAnalysisPanel::class, ['spreadsheet_id' => $this->spreadsheet->id]);
        $scripts = Livewire::actingAs($this->user)->test(ScriptsEditor::class, ['spreadsheet_id' => $this->spreadsheet->id]);
        $modal = Livewire::actingAs($this->user)->test(VersionHistoryModal::class, ['spreadsheet_id' => $this->spreadsheet->id, 'sheet_tab_id' => $this->tab->id]);
        $editor = $this->editor();

        $this->lockTimeoutOn('update "cells"');
        $this->lockTimeoutOn('insert into "cells"');
        $this->lockTimeoutOn('update "spreadsheets"');

        $toolbar->set('selected_row', 0)->set('selected_col', 0)->set('bold', true)->call('applyFormatting')->assertDispatched('sheet-busy');
        $ai->set('selected_range', 'A1:A1')->call('cleanData')->assertSet('error', WriteLock::BUSY_MESSAGE);
        $scripts->call('saveRecordedMacro', ['actions' => [['type' => 'select', 'row' => 0, 'col' => 0]]])->assertSet('status', WriteLock::BUSY_MESSAGE);
        $editor->set('editingCell', '0_0')->call('saveCell', 'x')->assertSet('structureNotice', WriteLock::BUSY_MESSAGE);
        $editor->set('selectedRow', 3)->call('toggleHideRowSelected')->assertSet('structureNotice', WriteLock::BUSY_MESSAGE);
        $editor->call('showSheetBusy')->assertSet('structureNotice', WriteLock::BUSY_MESSAGE);
        // Last: Laravel does not roll a nested transaction back on a lock error (it leaves that to the outer one).
        $modal->call('restoreVersion', $snapshot->id)->assertDispatched('sheet-busy');
    }

    // ---- small fixes found on the way --------------------------------------------------------------------------------

    public function test_the_ai_cleaning_still_cleans_on_a_current_grid(): void
    {
        $this->cell(0, 0, '  padded  ');
        $ai = Livewire::actingAs($this->user)->test(AiAnalysisPanel::class, ['spreadsheet_id' => $this->spreadsheet->id]);

        $ai->set('selected_range', 'A1:A1')->call('cleanData')->assertSet('success_message', 'Applied 1 cleaning fixes (trim/normalize spacing).');
        $this->assertSame('padded', $this->raw(0, 0));
    }

    public function test_formatting_an_empty_cell_creates_it_in_the_right_tab_with_a_timestamp(): void
    {
        $second = SheetTab::create(['spreadsheet_id' => $this->spreadsheet->id, 'name' => 'Second', 'sort_order' => 1]);
        $this->cell(0, 0, 'on first tab');   // same coordinates on the other tab: must not be touched
        $toolbar = Livewire::actingAs($this->user)->test(SpreadsheetToolbar::class, ['spreadsheet_id' => $this->spreadsheet->id]);

        $toolbar->call('followTab', $second->id)->call('loadCellFormatting', 0, 0)->set('bold', true)->call('applyFormatting');

        $made = Cell::where('sheet_tab_id', $second->id)->sole();
        $this->assertTrue($made->formatting['bold']);
        $this->assertNotNull($made->updated_at);
        $this->assertNull(Cell::where('sheet_tab_id', $this->tab->id)->sole()->formatting);
    }

    public function test_max_and_min_without_arguments_are_an_error_value_not_an_exception(): void
    {
        $evaluator = new FormulaEvaluatorService;

        foreach (['=MAX()', '=MIN()'] as $formula) {
            $result = $evaluator->evaluate($formula, $this->spreadsheet->id, $this->tab->id);
            $this->assertTrue($result['error'], $formula);
        }
        $this->editor()->set('editingCell', '0_0')->call('saveCell', '=MAX()');
        $this->assertSame('#ERROR', Cell::first()->computed_value);
    }

    public function test_a_big_tab_skips_the_automatic_snapshot_and_logs_it(): void
    {
        Log::spy();
        $rows = [];
        for ($i = 0; $i <= ShowSpreadsheet::AUTO_SNAPSHOT_MAX_CELLS; $i++) {
            $rows[] = ['spreadsheet_id' => $this->spreadsheet->id, 'sheet_tab_id' => $this->tab->id, 'row_index' => $i, 'col_index' => 0, 'raw_value' => 'x', 'updated_at' => now()];
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            Cell::insert($chunk);
        }

        $this->editor()->set('editingCell', '0_1')->call('saveCell', 'edit');

        $this->assertSame(0, SpreadsheetVersion::count());
        Log::shouldHaveReceived('notice')->withArgs(fn ($message) => str_contains($message, 'Auto snapshot skipped'))->once();

        // A small tab still gets one.
        Cell::query()->where('row_index', '>', 10)->delete();
        $this->editor()->set('editingCell', '0_2')->call('saveCell', 'again');
        $this->assertSame(1, SpreadsheetVersion::count());
    }

    public function test_a_stale_editor_switches_tab_after_reloading_instead_of_refusing(): void
    {
        $second = SheetTab::create(['spreadsheet_id' => $this->spreadsheet->id, 'name' => 'Second', 'sort_order' => 1]);
        $stale = $this->editor();
        StructureVersion::bump($this->spreadsheet->id);

        $stale->call('switchTab', $second->id)->assertSet('activeSheetTabId', $second->id)->assertSet('structureNotice', '');

        // A tab that has gone: it reloads onto what exists.
        $third = SheetTab::create(['spreadsheet_id' => $this->spreadsheet->id, 'name' => 'Third', 'sort_order' => 2]);
        $other = $this->editor();
        $third->delete();
        StructureVersion::bump($this->spreadsheet->id);
        $other->call('switchTab', $third->id)->assertSet('activeSheetTabId', $this->tab->id);
    }

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
