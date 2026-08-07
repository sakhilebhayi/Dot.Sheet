<?php

namespace App\Jobs;

use App\Models\Cell;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SimulateSpreadsheetEditJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public int $spreadsheetId,
        public int $iterations = 100,
        public int $maxRow = 200,
        public int $maxCol = 30,
    ) {}

    public function handle(): void
    {
        for ($i = 0; $i < $this->iterations; $i++) {
            $row = random_int(0, max(0, $this->maxRow - 1));
            $col = random_int(0, max(0, $this->maxCol - 1));
            $value = (string) random_int(1, 100000);

            Cell::updateOrCreate(
                [
                    'spreadsheet_id' => $this->spreadsheetId,
                    'row_index' => $row,
                    'col_index' => $col,
                ],
                [
                    'raw_value' => $value,
                    'computed_value' => $value,
                    'formula' => null,
                    'updated_at' => now(),
                ]
            );
        }
    }
}
