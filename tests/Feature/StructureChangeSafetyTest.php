<?php

namespace Tests\Feature;

use App\Jobs\BulkPasteChunkJob;
use App\Jobs\ImportRowsChunkJob;
use App\Livewire\AiAnalysisPanel;
use App\Livewire\ShowSpreadsheet;
use App\Livewire\SpreadsheetToolbar;
use App\Models\Cell;
use App\Models\NamedRange;
use App\Models\SheetTab;
use App\Models\Spreadsheet;
use App\Models\User;
use App\Services\FormulaEvaluatorService;
use App\Services\SheetStructureService;
use App\Services\StructureVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * What must hold around a row/column insert or delete besides the cells moving: undo history,
 * a second browser with a stale grid, the evaluator's caches, recalculation cost, and data
 * that would be pushed off the sheet.
 */
class StructureChangeSafetyTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Spreadsheet $spreadsheet;

    private SheetTab $tab;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->withPersonalTeam()->create();
        $this->spreadsheet = Spreadsheet::create(['uuid' => (string) str()->uuid(), 'owner_id' => $this->user->id, 'name' => 'Safety']);
        $this->tab = $this->spreadsheet->defaultSheetTab();
    }

    private function cell(int $row, int $col, ?string $raw, ?string $computed = null, ?string $formula = null): Cell
    {
        return Cell::create([
            'spreadsheet_id' => $this->spreadsheet->id, 'sheet_tab_id' => $this->tab->id,
            'row_index' => $row, 'col_index' => $col, 'raw_value' => $raw, 'computed_value' => $computed,
            'formula' => $formula, 'updated_at' => now(),
        ]);
    }

    private function editor(): Testable
    {
        return Livewire::actingAs($this->user)->test(ShowSpreadsheet::class, ['spreadsheet_id' => $this->spreadsheet->id]);
    }

    private function raw(int $row, int $col): ?string
    {
        return Cell::where('sheet_tab_id', $this->tab->id)->where('row_index', $row)->where('col_index', $col)->value('raw_value');
    }

    private function cellCount(): int
    {
        return Cell::where('sheet_tab_id', $this->tab->id)->count();
    }

    // ---- 1. undo history -------------------------------------------------------------

    public function test_undo_after_an_insert_does_not_destroy_the_cell_that_moved(): void
    {
        $editor = $this->editor();
        $editor->set('editingCell', '3_0')->call('saveCell', 'A3-keep');
        $this->assertSame('A3-keep', $this->raw(3, 0));

        $editor->set('selectedRow', 1)->call('insertRowSelected')
            ->assertSet('undoStack', [])
            ->assertSet('redoStack', [])
            ->assertSet('structureNotice', 'Undo history was cleared because rows or columns moved.');

        $editor->call('undoAction');

        $this->assertSame('A3-keep', $this->raw(4, 0));
        $this->assertNull($this->raw(3, 0));
    }

    public function test_every_structure_handler_clears_undo_and_redo(): void
    {
        foreach ([['insertRowSelected', 'selectedRow'], ['deleteRowSelected', 'selectedRow'], ['insertColumnSelected', 'selectedCol'], ['deleteColumnSelected', 'selectedCol']] as [$method, $selection]) {
            $editor = $this->editor();
            $editor->set('editingCell', '5_5')->call('saveCell', 'x')->call('undoAction');
            $editor->set('editingCell', '6_6')->call('saveCell', 'y');
            $this->assertNotEmpty($editor->get('undoStack') + $editor->get('redoStack'), $method);

            $editor->set($selection, 1)->call($method)->assertSet('undoStack', [])->assertSet('redoStack', []);
        }
    }

    // ---- 2. a stale second browser -----------------------------------------------------

    public function test_a_stale_editor_cannot_overwrite_moved_data(): void
    {
        $this->cell(0, 0, 'header');
        $this->cell(1, 0, 'row-one');
        $this->cell(2, 0, 'row-two');

        $a = $this->editor();
        $b = $this->editor();

        $a->set('selectedRow', 1)->call('insertRowSelected');   // row-one -> 2, row-two -> 3

        // B still shows row-one at index 1 and edits it.
        $b->set('editingCell', '1_0')->call('saveCell', 'B-overwrite')
            ->assertSet('structureNotice', StructureVersion::STALE_MESSAGE)
            ->assertSet('editingCell', null);

        $this->assertSame('row-one', $this->raw(2, 0));
        $this->assertNull($this->raw(1, 0));
        $this->assertSame('row-two', $this->raw(3, 0));

        // The grid was reloaded: B now sees the moved rows and can repeat the change.
        $this->assertSame('row-one', $b->get('cellData')[2][0]['raw_value']);
        $b->set('editingCell', '2_0')->call('saveCell', 'B-second-try');
        $this->assertSame('B-second-try', $this->raw(2, 0));
    }

    public function test_a_stale_editor_is_refused_on_every_cell_write_path_and_on_structure_changes(): void
    {
        $this->cell(0, 0, 'a');
        $this->cell(1, 0, 'b');
        $this->cell(2, 0, 'c');

        $paths = [
            'edit' => fn (Testable $e) => $e->set('editingCell', '0_0')->call('saveCell', 'X'),
            'formula bar' => fn (Testable $e) => $e->set('selectedRow', 0)->set('selectedCol', 0)->call('saveFromFormulaBar', 'X'),
            'paste cell' => fn (Testable $e) => $e->call('pasteIntoSelectedCell', 'X'),
            'paste block' => fn (Testable $e) => $e->call('bulkPasteFromText', "X\tY\nZ\tW"),
            'sort' => fn (Testable $e) => $e->call('sortSelectedColumn', ['direction' => 'desc']),
            'script set' => fn (Testable $e) => $e->call('applyScriptActions', ['actions' => [['type' => 'set', 'cell' => 'A1', 'value' => 'X']]]),
            'script clear' => fn (Testable $e) => $e->call('applyScriptActions', ['actions' => [['type' => 'clear', 'cell' => 'A1']]]),
            'insert row' => fn (Testable $e) => $e->call('insertRowSelected'),
            'delete row' => fn (Testable $e) => $e->call('deleteRowSelected'),
            'insert col' => fn (Testable $e) => $e->call('insertColumnSelected'),
            'delete col' => fn (Testable $e) => $e->call('deleteColumnSelected'),
        ];

        foreach ($paths as $name => $write) {
            Cell::query()->delete();
            $this->cell(0, 0, 'a');
            $this->cell(1, 0, 'b');
            $this->cell(2, 0, 'c');
            $stale = $this->editor();
            StructureVersion::bump($this->spreadsheet->id);
            $before = Cell::orderBy('id')->get(['row_index', 'col_index', 'raw_value'])->toArray();

            $write($stale);

            $this->assertSame($before, Cell::orderBy('id')->get(['row_index', 'col_index', 'raw_value'])->toArray(), "{$name} wrote through a stale grid");
            $stale->assertSet('structureNotice', StructureVersion::STALE_MESSAGE);
        }
    }

    public function test_a_stale_undo_is_refused(): void
    {
        $stale = $this->editor();
        $stale->set('editingCell', '0_0')->call('saveCell', 'typed');
        StructureVersion::bump($this->spreadsheet->id);

        $stale->call('undoAction');

        $this->assertSame('typed', $this->raw(0, 0));
    }

    public function test_a_stale_toolbar_does_not_format_cells_at_old_coordinates(): void
    {
        $this->cell(1, 0, 'x');
        $toolbar = Livewire::actingAs($this->user)->test(SpreadsheetToolbar::class, ['spreadsheet_id' => $this->spreadsheet->id]);
        $toolbar->set('selected_row', 1)->set('selected_col', 0)->set('bold', true);

        StructureVersion::bump($this->spreadsheet->id);
        $toolbar->call('applyFormatting')->assertDispatched('structure-stale');

        $this->assertNull(Cell::where('row_index', 1)->value('formatting'));
    }

    public function test_the_toolbar_follows_the_editor_after_a_reload(): void
    {
        $this->cell(1, 0, 'x');
        $toolbar = Livewire::actingAs($this->user)->test(SpreadsheetToolbar::class, ['spreadsheet_id' => $this->spreadsheet->id]);
        StructureVersion::bump($this->spreadsheet->id);

        $toolbar->call('syncStructureVersion', StructureVersion::current($this->spreadsheet->id))
            ->set('selected_row', 1)->set('selected_col', 0)->set('bold', true)
            ->call('applyFormatting')->assertNotDispatched('structure-stale');

        $this->assertTrue(Cell::where('row_index', 1)->first()->formatting['bold']);
    }

    public function test_a_stale_editor_page_reloads_when_the_toolbar_reports_it(): void
    {
        $this->cell(1, 0, 'x');
        $editor = $this->editor();
        StructureVersion::bump($this->spreadsheet->id);

        $editor->call('reloadBecauseStructureChanged')->assertSet('structureNotice', StructureVersion::STALE_MESSAGE);
        $this->assertSame(StructureVersion::current($this->spreadsheet->id), $editor->get('structureVersion'));
    }

    public function test_the_ai_cleaning_does_not_run_on_a_stale_panel(): void
    {
        $this->cell(0, 0, '  padded  ');
        $panel = Livewire::actingAs($this->user)->test(AiAnalysisPanel::class, ['spreadsheet_id' => $this->spreadsheet->id]);
        StructureVersion::bump($this->spreadsheet->id);

        $panel->set('selected_range', 'A1:A1')->call('cleanData')->assertDispatched('structure-stale');

        $this->assertSame('  padded  ', $this->raw(0, 0));
    }

    public function test_the_structure_version_cannot_be_set_from_the_browser(): void
    {
        $this->expectException(CannotUpdateLockedPropertyException::class);

        $this->editor()->set('structureVersion', 99);
    }

    public function test_an_import_is_not_refused_because_the_page_is_stale(): void
    {
        // An import writes at the file's own coordinates from row 0: what the page shows is irrelevant.
        $stale = StructureVersion::current($this->spreadsheet->id);
        StructureVersion::bump($this->spreadsheet->id);

        $this->actingAs($this->user)
            ->post(route('spreadsheets.import', $this->spreadsheet), ['file' => UploadedFile::fake()->createWithContent('data.csv', "a,b\n"), 'structure_version' => $stale])
            ->assertOk()->assertJson(['success' => true]);

        $this->assertSame(2, $this->cellCount());
    }

    public function test_queued_pastes_skip_when_the_structure_moved_but_queued_imports_do_not(): void
    {
        $version = StructureVersion::current($this->spreadsheet->id);
        StructureVersion::bump($this->spreadsheet->id);

        (new BulkPasteChunkJob($this->spreadsheet->id, $this->tab->id, [['row_index' => 0, 'col_index' => 0, 'value' => 'late']], $this->user->id, $version))->handle();
        $this->assertSame(0, $this->cellCount());

        (new ImportRowsChunkJob($this->spreadsheet->id, $this->tab->id, [['now']], 0))->handle();
        $this->assertSame('now', $this->raw(0, 0));
    }

    public function test_every_structure_change_bumps_the_version_in_the_same_transaction(): void
    {
        $service = app(SheetStructureService::class);
        $this->assertSame(0, StructureVersion::current($this->spreadsheet->id));

        $service->insertRow($this->spreadsheet->id, $this->tab->id, 0);
        $service->deleteRow($this->spreadsheet->id, $this->tab->id, 0);
        $service->insertColumn($this->spreadsheet->id, $this->tab->id, 0);
        $service->deleteColumn($this->spreadsheet->id, $this->tab->id, 0);

        $this->assertSame(4, StructureVersion::current($this->spreadsheet->id));
    }

    public function test_the_version_survives_the_editors_own_settings_writes(): void
    {
        $this->cell(0, 0, 'x');
        $editor = $this->editor();
        $editor->set('selectedRow', 0)->call('insertRowSelected')->call('toggleHideRowSelected');

        $this->assertSame(1, StructureVersion::current($this->spreadsheet->id));
        $editor->set('editingCell', '1_0')->call('saveCell', 'still allowed')->assertSet('structureNotice', '');
        $this->assertSame('still allowed', $this->raw(1, 0));
    }

    // ---- 3. cost of the recalculation ------------------------------------------------------

    public function test_inserting_a_row_with_a_thousand_formulas_is_fast_and_recomputes_only_what_changed(): void
    {
        $rows = [];
        for ($r = 0; $r < 1000; $r++) {
            for ($c = 0; $c < 17; $c++) {
                $rows[] = ['spreadsheet_id' => $this->spreadsheet->id, 'sheet_tab_id' => $this->tab->id, 'row_index' => $r, 'col_index' => $c, 'raw_value' => (string) ($r + $c), 'computed_value' => (string) ($r + $c), 'formula' => null, 'updated_at' => now()];
            }
            // Column R: a formula that only moves (=A{r}+B{r}) ...
            $rows[] = ['spreadsheet_id' => $this->spreadsheet->id, 'sheet_tab_id' => $this->tab->id, 'row_index' => $r, 'col_index' => 17, 'raw_value' => '', 'computed_value' => 'STORED', 'formula' => '=A'.($r + 1).'+B'.($r + 1), 'updated_at' => now()];
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            Cell::insert($chunk);
        }
        // ... and one whose range the insert cuts in two.
        $this->cell(1000, 0, '', 'STALE', '=SUM(A1:A1000)');
        $this->assertGreaterThan(18000, $this->cellCount());

        $started = microtime(true);
        app(SheetStructureService::class)->insertRow($this->spreadsheet->id, $this->tab->id, 500);
        $elapsed = microtime(true) - $started;

        $this->assertLessThan(6.0, $elapsed, "insert took {$elapsed}s");
        // Moved only: text rewritten, value kept (no recompute).
        $this->assertSame('STORED', Cell::where('row_index', 0)->where('col_index', 17)->value('computed_value'));
        $this->assertSame('=A1001+B1001', Cell::where('row_index', 1000)->where('col_index', 17)->value('formula'));
        $this->assertSame('=A502+B502', Cell::where('row_index', 501)->where('col_index', 17)->value('formula'));
        $this->assertSame('STORED', Cell::where('row_index', 501)->where('col_index', 17)->value('computed_value'));
        // Range resized: recomputed, no longer the stale marker.
        $sum = Cell::where('row_index', 1001)->where('col_index', 0)->first();
        $this->assertSame('=SUM(A1:A1001)', $sum->formula);
        $this->assertNotSame('STALE', $sum->computed_value);
    }

    public function test_a_failed_insert_rolls_back_cells_formulas_and_version(): void
    {
        $this->cell(0, 0, '1');
        $this->cell(1, 0, '', '1', '=A1');

        try {
            DB::transaction(function () {
                app(SheetStructureService::class)->deleteRow($this->spreadsheet->id, $this->tab->id, 0);
                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException) {
        }

        $this->assertSame('1', $this->raw(0, 0));
        $this->assertSame('=A1', Cell::where('row_index', 1)->value('formula'));
        $this->assertSame(0, StructureVersion::current($this->spreadsheet->id));
    }

    // ---- 4. caches ----------------------------------------------------------------------------

    public function test_a_named_range_formula_computed_after_an_insert_uses_the_moved_range(): void
    {
        foreach ([1, 2, 3] as $r => $v) {
            $this->cell($r, 0, (string) $v);
        }
        NamedRange::create(['spreadsheet_id' => $this->spreadsheet->id, 'sheet_tab_id' => $this->tab->id, 'name' => 'Sales', 'start_row' => 0, 'start_col' => 0, 'end_row' => 2, 'end_col' => 0]);

        // Fills cell_values, formula_dependency_graph and named_ranges in the cache.
        $this->assertEquals(6, (new FormulaEvaluatorService)->evaluate('=SUM(Sales)', $this->spreadsheet->id, $this->tab->id)['value']);

        app(SheetStructureService::class)->insertRow($this->spreadsheet->id, $this->tab->id, 1);
        $this->cell(1, 0, '100');

        $this->assertEquals(106, (new FormulaEvaluatorService)->evaluate('=SUM(Sales)', $this->spreadsheet->id, $this->tab->id)['value']);
    }

    public function test_a_formula_using_a_named_range_that_grew_is_recomputed(): void
    {
        foreach ([1, 2, 3] as $r => $v) {
            $this->cell($r, 0, (string) $v);
        }
        NamedRange::create(['spreadsheet_id' => $this->spreadsheet->id, 'sheet_tab_id' => $this->tab->id, 'name' => 'Sales', 'start_row' => 0, 'start_col' => 0, 'end_row' => 2, 'end_col' => 0]);
        $this->cell(0, 2, '', 'OLD', '=SUM(Sales)');

        app(SheetStructureService::class)->deleteRow($this->spreadsheet->id, $this->tab->id, 1);

        $this->assertEquals(4, Cell::where('col_index', 2)->first()->computed_value);
    }

    public function test_the_service_clears_every_cache_key_the_evaluator_uses(): void
    {
        $evaluator = file_get_contents(app_path('Services/FormulaEvaluatorService.php'));
        preg_match_all('/"spreadsheet:\{\$spreadsheetId\}:tab:\{\$sheetTabId\}:([a-z_]+)"/', $evaluator, $keys);

        $this->assertNotEmpty($keys[1]);
        $this->assertEqualsCanonicalizing(array_unique($keys[1]), SheetStructureService::CACHE_KEYS);
    }

    // ---- insert past the edge, clamping ---------------------------------------------------------

    public function test_inserting_a_column_is_refused_when_data_would_leave_the_sheet(): void
    {
        $this->cell(0, 99, 'last-column');
        $this->cell(0, 0, 'first');

        $this->editor()->set('selectedCol', 50)->call('insertColumnSelected')
            ->assertSet('structureNotice', 'Can not insert a column: there is data in the last column of the sheet and it would be pushed off the end. Clear or move it first.');

        $this->assertSame('last-column', $this->raw(0, 99));
        $this->assertSame(0, StructureVersion::current($this->spreadsheet->id));
    }

    public function test_inserting_a_row_is_refused_when_data_sits_in_the_last_row(): void
    {
        $this->cell(SheetStructureService::GRID_ROWS - 1, 0, 'last-row');

        $this->editor()->set('selectedRow', 3)->call('insertRowSelected')
            ->assertSet('structureNotice', 'Can not insert a row: there is data in the last row of the sheet and it would be pushed off the end. Clear or move it first.');

        $this->assertSame('last-row', $this->raw(SheetStructureService::GRID_ROWS - 1, 0));
    }

    public function test_an_insert_that_fits_is_allowed_and_one_after_the_last_cell_never_needs_room(): void
    {
        $this->cell(0, 98, 'second-last');
        $this->cell(0, 99, 'last');

        $editor = $this->editor();
        // Inserting after both cells moves nothing; deleting is always fine.
        $editor->set('selectedCol', 99)->call('deleteColumnSelected');

        $this->assertSame('second-last', $this->raw(0, 98));
        $editor->set('selectedCol', 98)->call('insertColumnSelected')->assertSet('structureNotice', '');
        $this->assertSame('second-last', $this->raw(0, 99));
    }

    public function test_a_crafted_negative_selection_is_clamped_to_the_first_row(): void
    {
        $this->cell(0, 0, 'a');

        $this->editor()->set('selectedRow', -1)->call('insertRowSelected')->assertOk();
        $this->assertSame('a', $this->raw(1, 0));

        $this->editor()->set('selectedCol', -5)->call('deleteColumnSelected')->assertOk();
        $this->assertSame(0, $this->cellCount());
    }
}
