<?php

namespace Tests\Feature;

use App\Jobs\ImportRowsChunkJob;
use App\Models\Cell;
use App\Models\SheetTab;
use App\Models\Spreadsheet;
use App\Models\User;
use App\Services\SpreadsheetImportExportService;
use Illuminate\Bus\PendingBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Excel as ExcelWriter;
use Maatwebsite\Excel\Facades\Excel;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * An import below the cell threshold is one all-or-nothing transaction; at or above it the file goes to
 * queued chunk jobs. The decision and the chunk size are measured in CELLS, because the SQLite write
 * lock is held for about 0.35 ms per cell whatever the shape of the file (1,499 rows x 20 columns held
 * it for 10 seconds).
 */
class ImportCellThresholdTest extends TestCase
{
    use RefreshDatabase;

    private const QUEUED_MESSAGE = 'This file is large, so it is being imported in the background. You can keep working; the sheet reloads when it is done.';

    private User $user;

    private Spreadsheet $spreadsheet;

    private SheetTab $tab;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->withPersonalTeam()->create();
        $this->spreadsheet = Spreadsheet::create(['uuid' => (string) str()->uuid(), 'owner_id' => $this->user->id, 'name' => 'Cells']);
        $this->tab = $this->spreadsheet->defaultSheetTab();
    }

    /** @return list<list<string>> */
    private function grid(int $rows, int $cols): array
    {
        $grid = [];
        for ($r = 0; $r < $rows; $r++) {
            $row = [];
            for ($c = 0; $c < $cols; $c++) {
                $row[] = "r{$r}c{$c}";
            }
            $grid[] = $row;
        }

        return $grid;
    }

    /** @param  list<list<mixed>>  $grid */
    private function csv(array $grid): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('data.csv', implode("\n", array_map(fn ($row) => implode(',', array_map(fn ($v) => (string) $v, $row)), $grid)));
    }

    /** @param  list<list<mixed>>  $grid */
    private function xlsx(array $grid): UploadedFile
    {
        $content = Excel::raw(new class($grid) implements FromArray
        {
            public function __construct(private array $grid) {}

            public function array(): array
            {
                return $this->grid;
            }
        }, ExcelWriter::XLSX);

        return UploadedFile::fake()->createWithContent('data.xlsx', $content);
    }

    private function upload(UploadedFile $file): TestResponse
    {
        return $this->actingAs($this->user)->post(route('spreadsheets.import', $this->spreadsheet), ['file' => $file])->assertOk();
    }

    /** @return array<string, array{int, int, bool}> rows, columns, queued */
    public static function boundaries(): array
    {
        return [
            'just under, wide (100 x 59 = 5,900)' => [100, 59, false],
            'at the threshold, wide (100 x 60 = 6,000)' => [100, 60, true],
            'just under, tall (5,999 x 1)' => [5999, 1, false],
            'at the threshold, tall (6,000 x 1)' => [6000, 1, true],
            'old queue threshold in rows, one column (1,499 x 1)' => [1499, 1, false],
            'old queue threshold in rows is gone (1,500 x 1)' => [1500, 1, false],
            'few rows in the old limit, many columns (1,499 x 20)' => [1499, 20, true],
            'wide small-row file (10 x 600)' => [10, 600, true],
            'long thin file (2,000 x 2 = 4,000)' => [2000, 2, false],
        ];
    }

    #[DataProvider('boundaries')]
    public function test_csv_is_queued_by_cell_count_not_row_count(int $rows, int $cols, bool $queued): void
    {
        Bus::fake();

        $response = $this->upload($this->csv($this->grid($rows, $cols)))->assertJson(['success' => true]);

        if ($queued) {
            $response->assertJson(['queued' => true, 'rows' => $rows, 'message' => self::QUEUED_MESSAGE]);
            Bus::assertBatched(fn (PendingBatch $batch) => $batch->jobs->count() === $response->json('chunks'));
            $this->assertSame(0, Cell::count(), 'nothing is written inside the request');
        } else {
            $response->assertJson(['rows' => $rows, 'cells' => $rows * $cols]);
            $this->assertArrayNotHasKey('queued', $response->json());
            Bus::assertNothingBatched();
            $this->assertSame($rows * $cols, Cell::count());
        }
    }

    public function test_only_non_empty_cells_count_towards_the_threshold(): void
    {
        Bus::fake();

        // 100 x 70 cells in the file, but only 5,950 of them hold anything: inline.
        $grid = $this->grid(100, 70);
        for ($r = 0; $r < 100; $r++) {
            for ($c = 59; $c < 70; $c++) {
                $grid[$r][$c] = '';
            }
        }
        $grid[0][59] = 'x';   // 5,901 non-empty cells

        $this->upload($this->csv($grid))->assertJson(['success' => true, 'cells' => 5901]);
        Bus::assertNothingBatched();
    }

    public function test_xlsx_is_queued_by_cell_count_too(): void
    {
        Bus::fake();

        $this->upload($this->xlsx($this->grid(100, 59)))->assertJson(['success' => true, 'cells' => 5900]);
        Bus::assertNothingBatched();

        $this->upload($this->xlsx($this->grid(100, 60)))->assertJson(['success' => true, 'queued' => true, 'rows' => 100, 'message' => self::QUEUED_MESSAGE]);
        Bus::assertBatchCount(1);
    }

    public function test_the_threshold_is_configurable_and_zero_queues_everything(): void
    {
        Bus::fake();
        config(['sheet.import_inline_max_cells' => 10]);

        $this->upload($this->csv($this->grid(3, 3)))->assertJson(['success' => true, 'cells' => 9]);
        Bus::assertNothingBatched();
        $this->upload($this->csv($this->grid(2, 5)))->assertJson(['queued' => true]);
        Bus::assertBatchCount(1);
    }

    public function test_chunks_are_sized_in_cells_with_the_right_start_rows(): void
    {
        Bus::fake();

        // 1,499 x 20 = 29,980 cells: 5000 / 20 = 250 rows per chunk, five full chunks and a partial one of 249.
        $this->upload($this->csv($this->grid(1499, 20)))->assertJson(['queued' => true, 'chunks' => 6]);

        Bus::assertBatched(function (PendingBatch $batch) {
            $starts = $batch->jobs->map(fn (ImportRowsChunkJob $job) => $job->startRowIndex)->all();
            $sizes = $batch->jobs->map(fn (ImportRowsChunkJob $job) => count($job->rows))->all();

            return $starts === [0, 250, 500, 750, 1000, 1250] && $sizes === [250, 250, 250, 250, 250, 249];
        });
    }

    public function test_a_wide_file_gets_small_chunks_and_a_thin_file_is_capped_at_500_rows(): void
    {
        $this->assertSame(500, SpreadsheetImportExportService::rowsPerChunk(1));
        $this->assertSame(500, SpreadsheetImportExportService::rowsPerChunk(10));
        $this->assertSame(250, SpreadsheetImportExportService::rowsPerChunk(20));
        $this->assertSame(83, SpreadsheetImportExportService::rowsPerChunk(60));
        $this->assertSame(1, SpreadsheetImportExportService::rowsPerChunk(5000));
        $this->assertSame(1, SpreadsheetImportExportService::rowsPerChunk(20000), 'a row wider than a chunk is still one row');
        $this->assertSame(500, SpreadsheetImportExportService::rowsPerChunk(0));

        config(['sheet.import_chunk_cells' => 0]);
        $this->assertSame(1, SpreadsheetImportExportService::rowsPerChunk(7), 'a nonsense setting never produces a zero-row chunk');
    }

    public function test_every_chunk_stays_within_the_cell_budget(): void
    {
        Bus::fake();

        $this->upload($this->csv($this->grid(100, 60)))->assertJson(['queued' => true, 'chunks' => 2]);   // 83 + 17 rows

        Bus::assertBatched(function (PendingBatch $batch) {
            foreach ($batch->jobs as $job) {
                if (collect($job->rows)->sum(fn ($row) => count($row)) > 5000) {
                    return false;
                }
            }

            return true;
        });
    }

    /**
     * A grid with empty rows, empty cells and rows of different widths, cut into uneven chunks (the last
     * one partial) and run through the real batch and a real worker: the sheet ends up with exactly the
     * file's cells at the file's coordinates.
     *
     * @return list<list<?string>>
     */
    private function raggedGrid(): array
    {
        $grid = [];
        for ($r = 0; $r < 23; $r++) {
            $width = 1 + (($r * 7) % 5);          // 1..5 columns
            $row = [];
            for ($c = 0; $c < $width; $c++) {
                $row[] = ($r + $c) % 4 === 3 ? '' : "v{$r}_{$c}";
            }
            $grid[] = $row;
        }
        $grid[4] = ['', '', ''];                  // an empty row in the middle of a chunk
        $grid[22] = ['last', '', '', '', 'far'];   // widest row, in the final partial chunk

        return $grid;
    }

    /** @return array<string, string> "row,col" => value */
    private function expectedCells(array $grid): array
    {
        $expected = [];
        foreach ($grid as $r => $row) {
            foreach ($row as $c => $value) {
                if ($value !== null && $value !== '') {
                    $expected["{$r},{$c}"] = (string) $value;
                }
            }
        }
        ksort($expected);

        return $expected;
    }

    /** @return array<string, string> */
    private function storedCells(): array
    {
        $stored = [];
        foreach (Cell::where('sheet_tab_id', $this->tab->id)->get() as $cell) {
            $stored["{$cell->row_index},{$cell->col_index}"] = (string) $cell->raw_value;
        }
        ksort($stored);

        return $stored;
    }

    private function runWorker(): void
    {
        Artisan::call('queue:work', ['--stop-when-empty' => true, '--memory' => 4096, '--quiet' => true, '--tries' => 1]);
    }

    public function test_uneven_csv_chunks_reproduce_the_exact_cell_grid_through_a_real_worker(): void
    {
        config(['queue.default' => 'database', 'sheet.import_inline_max_cells' => 10, 'sheet.import_chunk_cells' => 10]);
        $grid = $this->raggedGrid();

        // 21 non-empty rows, 47 cells, widest row 4 cells -> 10 / 4 = 2 rows per chunk: 12 chunks, the last one holds 1 row.
        $response = $this->upload($this->csv($grid))->assertJson(['queued' => true, 'chunks' => 12, 'rows' => 21, 'cells' => 47]);
        $this->assertSame(0, Cell::count());

        $this->runWorker();

        $this->assertSame($this->expectedCells($grid), $this->storedCells());
        $this->actingAs($this->user)->getJson(route('spreadsheets.import.batch-status', [$this->spreadsheet, $response->json('batch_id')]))
            ->assertOk()->assertJson(['status' => 'finished', 'failed' => 0, 'total' => 12]);
    }

    public function test_uneven_xlsx_chunks_reproduce_the_exact_cell_grid_through_a_real_worker(): void
    {
        config(['queue.default' => 'database', 'sheet.import_inline_max_cells' => 10, 'sheet.import_chunk_cells' => 10]);
        $grid = $this->raggedGrid();

        $this->upload($this->xlsx($grid))->assertJson(['queued' => true]);
        $this->runWorker();

        $this->assertSame($this->expectedCells($grid), $this->storedCells());
    }

    public function test_chunks_of_different_sizes_land_at_the_right_rows(): void
    {
        // The job places row N of its chunk at startRowIndex + N, whatever size the chunk is.
        $sizes = [3, 1, 4, 2];
        $row = 0;
        foreach ($sizes as $size) {
            $chunk = [];
            for ($i = 0; $i < $size; $i++) {
                $chunk[] = ["row{$row}", "x{$row}"];
                $row++;
            }
            (new ImportRowsChunkJob($this->spreadsheet->id, $this->tab->id, $chunk, $row - $size))->handle();
        }

        $stored = $this->storedCells();
        $this->assertCount(20, $stored);
        for ($r = 0; $r < 10; $r++) {
            $this->assertSame("row{$r}", $stored["{$r},0"]);
            $this->assertSame("x{$r}", $stored["{$r},1"]);
        }
    }

    public function test_a_chunk_pauses_after_it_commits_so_other_writers_get_a_window(): void
    {
        $job = new ImportRowsChunkJob($this->spreadsheet->id, $this->tab->id, [['a']], 0);

        config(['sheet.import_chunk_pause_ms' => 0]);
        $start = hrtime(true);
        $job->handle();
        $this->assertLessThan(0.1, (hrtime(true) - $start) / 1e9, 'no pause when it is switched off');

        config(['sheet.import_chunk_pause_ms' => 200]);
        $start = hrtime(true);
        $job->handle();
        $this->assertGreaterThanOrEqual(0.2, (hrtime(true) - $start) / 1e9);
        $this->assertSame(['0,0' => 'a'], $this->storedCells());
    }

    public function test_a_failing_chunk_is_still_reported_with_the_actual_chunk_size(): void
    {
        config(['queue.default' => 'database']);
        $good1 = new ImportRowsChunkJob($this->spreadsheet->id, $this->tab->id, [['one'], ['two']], 0);
        $bad = new ImportRowsChunkJob($this->spreadsheet->id, 987654, [['three'], ['four']], 2);   // no such tab: the foreign key refuses it
        $good2 = new ImportRowsChunkJob($this->spreadsheet->id, $this->tab->id, [['five']], 4);
        $batch = Bus::batch([$good1, $bad, $good2])->name("import:spreadsheet:{$this->spreadsheet->id}")->allowFailures()->dispatch();
        Cache::put("import:{$batch->id}:rows", 5, 3600);
        Cache::put("import:{$batch->id}:chunk_rows", 2, 3600);
        $this->runWorker();

        $this->actingAs($this->user)->getJson(route('spreadsheets.import.batch-status', [$this->spreadsheet, $batch->id]))
            ->assertOk()
            ->assertJson(['status' => 'failed', 'failed' => 1, 'message' => 'Import failed after 4 of 5 rows; the rows already written are kept.']);
        $this->assertSame(['0,0' => 'one', '1,0' => 'two', '4,0' => 'five'], $this->storedCells());
    }

    public function test_a_queued_import_records_its_chunk_size_for_the_status_endpoint(): void
    {
        Bus::fake();

        $response = $this->upload($this->csv($this->grid(100, 60)))->assertJson(['queued' => true]);

        $this->assertSame(83, Cache::get('import:'.$response->json('batch_id').':chunk_rows'));
        $this->assertSame(100, Cache::get('import:'.$response->json('batch_id').':rows'));
        $this->assertSame(0, DB::table('job_batches')->count(), 'the batch was faked, nothing else wrote one');
    }
}
