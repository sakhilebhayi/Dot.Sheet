<?php

namespace App\Jobs;

use App\Models\Cell;
use App\Models\CellHistory;
use App\Services\StructureVersion;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

class BulkPasteChunkJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param  array<int, array{row_index:int,col_index:int,value:string}>  $cells
     */
    public function __construct(
        public int $spreadsheetId,
        public ?int $sheetTabId,
        public array $cells,
        public ?int $userId = null,
        public ?int $structureVersion = null,
    ) {}

    public static function cancelledKey(string $batchId): string
    {
        return "bulk-paste:{$batchId}:cancelled";
    }

    public function handle(): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        // The whole chunk is one transaction that first compares the version this paste was queued at
        // with the stored one, under the write lock: a structure change cannot commit between the check
        // and the cells landing. Rows or columns that moved mean its coordinates are out of date: cancel
        // the rest of the batch and leave a reason for the editor, so a paste that stopped halfway cannot
        // look like one that finished.
        if ($this->structureVersion !== null) {
            if (! StructureVersion::writeIfCurrent($this->spreadsheetId, $this->structureVersion, fn () => $this->writeChunk())) {
                if ($this->batch() !== null) {
                    Cache::put(self::cancelledKey($this->batch()->id), 'structure', 3600);
                    $this->batch()->cancel();
                }

                return;
            }
        } else {
            $this->writeChunk();
        }

        Cache::forget("spreadsheet:{$this->spreadsheetId}:tab:{$this->sheetTabId}:cell_values");
        Cache::forget("spreadsheet:{$this->spreadsheetId}:tab:{$this->sheetTabId}:formula_dependency_graph");
    }

    protected function writeChunk(): void
    {
        foreach ($this->cells as $entry) {
            $row = (int) $entry['row_index'];
            $col = (int) $entry['col_index'];
            $value = (string) $entry['value'];

            $cell = Cell::where('spreadsheet_id', $this->spreadsheetId)
                ->where('sheet_tab_id', $this->sheetTabId)
                ->where('row_index', $row)
                ->where('col_index', $col)
                ->first();

            $oldValue = $cell?->raw_value;
            $isFormula = str_starts_with($value, '=');

            $rawValue = $isFormula ? '' : $value;
            $formula = $isFormula ? $value : null;
            $computedValue = null;

            if ($cell) {
                $cell->update([
                    'raw_value' => $rawValue,
                    'computed_value' => $computedValue,
                    'formula' => $formula,
                ]);
            } else {
                $cell = Cell::create([
                    'spreadsheet_id' => $this->spreadsheetId,
                    'sheet_tab_id' => $this->sheetTabId,
                    'row_index' => $row,
                    'col_index' => $col,
                    'raw_value' => $rawValue,
                    'computed_value' => $computedValue,
                    'formula' => $formula,
                    'updated_at' => now(),
                ]);
            }

            if ($this->userId) {
                CellHistory::create([
                    'cell_id' => $cell->id,
                    'user_id' => $this->userId,
                    'old_value' => $oldValue,
                    'new_value' => $value,
                    'created_at' => now(),
                ]);
            }
        }
    }
}
