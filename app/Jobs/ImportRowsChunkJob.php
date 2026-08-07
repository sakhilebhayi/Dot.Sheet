<?php

namespace App\Jobs;

use App\Models\Cell;
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
        public array $rows,
        public int $startRowIndex = 1,
    ) {}

    public function handle(): void
    {
        foreach ($this->rows as $offset => $row) {
            if (empty(array_filter((array) $row, fn ($v) => $v !== null && $v !== ''))) {
                continue;
            }

            $rowNumber = $this->startRowIndex + $offset;
            foreach ((array) $row as $colIndex => $value) {
                $value = (string) ($value ?? '');
                if ($value === '') {
                    continue;
                }

                $isFormula = str_starts_with($value, '=');
                Cell::updateOrCreate(
                    [
                        'spreadsheet_id' => $this->spreadsheetId,
                        'row_index' => $rowNumber,
                        'col_index' => $colIndex + 1,
                    ],
                    [
                        'raw_value' => $value,
                        'computed_value' => $isFormula ? null : $value,
                        'formula' => $isFormula ? $value : null,
                    ]
                );
            }
        }
    }
}
