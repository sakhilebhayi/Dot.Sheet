<?php

namespace Tests\Feature;

use App\Jobs\BulkPasteChunkJob;
use App\Jobs\ImportRowsChunkJob;
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

        config(['sheet.structure_ops_enabled' => true]);

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

    private function raw(int $row, int $col): ?string
    {
        return Cell::where('sheet_tab_id', $this->tab->id)->where('row_index', $row)->where('col_index', $col)->value('raw_value');
    }

    private function cellCount(): int
    {
        return Cell::where('sheet_tab_id', $this->tab->id)->count();
    }

    // ---- 1. undo history -------------------------------------------------------------

    // ---- 2. a stale second browser -----------------------------------------------------

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

}
