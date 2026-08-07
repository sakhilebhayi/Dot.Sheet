<?php

namespace App\Jobs;

use App\Models\Cell;
use App\Models\CellHistory;
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
        public array $cells,
        public ?int $userId = null,
    ) {}

    public function handle(): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        foreach ($this->cells as $entry) {
            $row = (int) $entry['row_index'];
            $col = (int) $entry['col_index'];
            $value = (string) $entry['value'];

            $cell = Cell::where('spreadsheet_id', $this->spreadsheetId)
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
                    'row_index' => $row,
                    'col_index' => $col,
                    'raw_value' => $rawValue,
                    'computed_value' => $computedValue,
                    'formula' => $formula,
                ]);
            }

            if ($this->userId) {
                CellHistory::create([
                    'cell_id' => $cell->id,
                    'user_id' => $this->userId,
                    'old_value' => $oldValue,
                    'new_value' => $value,
                ]);
            }
        }

        Cache::forget("spreadsheet:{$this->spreadsheetId}:cell_values");
        Cache::forget("spreadsheet:{$this->spreadsheetId}:formula_dependency_graph");
    }
}
