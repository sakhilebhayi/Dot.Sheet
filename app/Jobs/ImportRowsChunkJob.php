<?php

namespace App\Jobs;

use App\Models\Cell;
use App\Services\Formula\Recalculator;
use App\Services\SpreadsheetImportExportService;
use App\Services\StructureVersion;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ImportRowsChunkJob implements ShouldQueue
{
    private const WRITE_BATCH = 500;

    use Batchable;
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * @param  array<int, array<int, mixed>>  $rows
     */
    public function __construct(
        public int $spreadsheetId,
        public ?int $sheetTabId,
        public array $rows,
        public int $startRowIndex = 0,
        public bool $valuesAreText = false,
        public ?int $structureVersion = null,
    ) {}

    public static function cancelledKey(string $batchId): string
    {
        return "import:{$batchId}:cancelled";
    }

    public function handle(): void
    {
        $this->writeUnderLock();

        // SQLite does not queue writers fairly: a worker that takes the write lock again the moment it
        // lets go (the next chunk) can keep a session or cache write waiting past busy_timeout. A short
        // pause between chunks, outside any transaction, is the window those writers get.
        $pauseMs = max(0, (int) config('sheet.import_chunk_pause_ms'));
        if ($pauseMs > 0) {
            usleep($pauseMs * 1000);
        }
    }

    protected function writeUnderLock(): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        // An import writes at the file's own coordinates from row 0, so what a person's screen shows
        // does not matter. What does matter is rows or columns moving between the import being queued
        // and this chunk landing (they would be overwritten): the version captured at queueing is
        // compared under the write lock, in the same transaction as the chunk. If it changed, the rest of
        // the import is cancelled and the status says why.
        if ($this->structureVersion !== null) {
            if (! StructureVersion::writeIfCurrent($this->spreadsheetId, $this->structureVersion, fn () => $this->writeChunk())) {
                if ($this->batch() !== null) {
                    Cache::put(self::cancelledKey($this->batch()->id), 'structure', 3600);
                    $this->batch()->cancel();
                }
            }

            return;
        }

        $this->writeChunk();
    }

    protected function writeChunk(): void
    {
        // Formulas this chunk writes, and those that read its cells, are worked out on the next read.
        app(Recalculator::class)->markStale($this->spreadsheetId, (int) $this->sheetTabId);

        // EA-4 (P-03): written in batches of 500 rows of cells, one statement each, not one or two per cell. A
        // cell an import fills is a cell of its own, so it no longer holds another formula's spilled answer.
        $batch = [];
        foreach ($this->rows as $offset => $row) {
            if (empty(array_filter((array) $row, fn ($v) => $v !== null && $v !== ''))) {
                continue;
            }

            $rowNumber = $this->startRowIndex + $offset;
            foreach ((array) $row as $colIndex => $value) {
                $attributes = SpreadsheetImportExportService::importedCellAttributes($value, $this->valuesAreText);
                if ($attributes === null) {
                    continue;
                }

                $batch[] = [
                    'spreadsheet_id' => $this->spreadsheetId,
                    'sheet_tab_id' => $this->sheetTabId,
                    'row_index' => $rowNumber,
                    'col_index' => (int) $colIndex,
                    ...$attributes,
                    'spill_row' => null,
                    'spill_col' => null,
                ];
                if (count($batch) >= self::WRITE_BATCH) {
                    $this->upsert($batch);
                    $batch = [];
                }
            }
        }

        $this->upsert($batch);
    }

    /** @param  list<array<string, mixed>>  $cells */
    private function upsert(array $cells): void
    {
        if ($cells !== []) {
            DB::table('cells')->upsert($cells, ['spreadsheet_id', 'sheet_tab_id', 'row_index', 'col_index'], ['raw_value', 'computed_value', 'formula', 'updated_at', 'spill_row', 'spill_col']);
        }
    }
}
