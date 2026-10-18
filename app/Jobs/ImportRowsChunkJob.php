<?php

namespace App\Jobs;

use App\Models\Cell;
use App\Services\SpreadsheetImportExportService;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

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
    ) {}

    public function handle(): void
    {
        // An import writes at the file's own coordinates from row 0, so moving rows in the editor
        // meanwhile does not make it stale (unlike a paste, which lands relative to the selection).
        if ($this->batch()?->cancelled()) {
            return;
        }

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
