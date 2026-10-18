<?php

namespace App\Jobs;

use App\Models\Cell;
use App\Services\SpreadsheetImportExportService;
use App\Services\StructureVersion;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ImportRowsChunkJob implements ShouldQueue
{
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
        public ?int $structureVersion = null,
        public bool $valuesAreText = false,
    ) {}

    public function handle(): void
    {
        // Rows or columns moved after the import was queued: its coordinates are out of date.
        if ($this->structureVersion !== null && $this->structureVersion !== StructureVersion::current($this->spreadsheetId)) {
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
