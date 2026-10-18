<?php

namespace App\Jobs;

use App\Models\Cell;
use App\Services\SpreadsheetImportExportService;
use App\Services\StructureVersion;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

class ImportRowsChunkJob implements ShouldQueue
{
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

                Cell::updateOrCreate(
                    [
                        'spreadsheet_id' => $this->spreadsheetId,
                        'sheet_tab_id' => $this->sheetTabId,
                        'row_index' => $rowNumber,
                        'col_index' => $colIndex,
                    ],
                    $attributes
                );
            }
        }
    }
}
