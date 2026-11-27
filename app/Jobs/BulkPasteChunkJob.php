<?php

namespace App\Jobs;

use App\Models\Cell;
use App\Services\Formula\Recalculator;
use App\Services\StructureVersion;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

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
        // Formulas this chunk writes, and those that read its cells, are worked out on the next read.
        app(Recalculator::class)->markStale($this->spreadsheetId, (int) $this->sheetTabId);

        // EA-4 (P-03): the chunk's existing cells are read in one query, written in one upsert, and their history in
        // one insert, instead of two or three statements per cell. What each cell held before is still recorded.
        $entries = [];
        foreach ($this->cells as $entry) {
            $entries[(int) $entry['row_index'].':'.(int) $entry['col_index']] = [
                'row' => (int) $entry['row_index'], 'col' => (int) $entry['col_index'], 'value' => (string) $entry['value'],
            ];
        }
        if ($entries === []) {
            return;
        }

        $positions = fn ($query) => $query->where('spreadsheet_id', $this->spreadsheetId)->where('sheet_tab_id', $this->sheetTabId)
            ->whereIn('row_index', array_unique(array_column($entries, 'row')))
            ->whereIn('col_index', array_unique(array_column($entries, 'col')));
        $before = $positions(DB::table('cells'))->get(['row_index', 'col_index', 'raw_value'])
            ->mapWithKeys(fn ($cell) => [$cell->row_index.':'.$cell->col_index => $cell->raw_value])->all();

        $now = now();
        $rows = array_map(function (array $entry) use ($now) {
            $isFormula = str_starts_with($entry['value'], '=');

            return [
                'spreadsheet_id' => $this->spreadsheetId,
                'sheet_tab_id' => $this->sheetTabId,
                'row_index' => $entry['row'],
                'col_index' => $entry['col'],
                'raw_value' => $isFormula ? '' : $entry['value'],
                'computed_value' => null,
                'formula' => $isFormula ? $entry['value'] : null,
                'updated_at' => $now,
                // A pasted cell is a cell of its own: it no longer holds another formula's spilled answer.
                'spill_row' => null,
                'spill_col' => null,
            ];
        }, array_values($entries));

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('cells')->upsert($chunk, ['spreadsheet_id', 'sheet_tab_id', 'row_index', 'col_index'], ['raw_value', 'computed_value', 'formula', 'updated_at', 'spill_row', 'spill_col']);
        }

        if ($this->userId) {
            $ids = $positions(DB::table('cells'))->get(['id', 'row_index', 'col_index'])
                ->mapWithKeys(fn ($cell) => [$cell->row_index.':'.$cell->col_index => $cell->id])->all();
            $history = [];
            foreach ($entries as $key => $entry) {
                if (isset($ids[$key])) {
                    $history[] = ['cell_id' => $ids[$key], 'user_id' => $this->userId, 'old_value' => $before[$key] ?? null, 'new_value' => $entry['value'], 'created_at' => $now];
                }
            }
            foreach (array_chunk($history, 500) as $chunk) {
                DB::table('cell_history')->insert($chunk);
            }
        }
    }
}
