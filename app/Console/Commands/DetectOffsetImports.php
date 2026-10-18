<?php

namespace App\Console\Commands;

use App\Models\Spreadsheet;
use App\Services\OffsetImportRepair;
use Illuminate\Console\Command;

class DetectOffsetImports extends Command
{
    protected $signature = 'sheet:detect-offset-imports';

    protected $description = 'List tabs imported before the 0-based import fix (cells one row and one column off). Read-only.';

    public function handle(OffsetImportRepair $repair): int
    {
        $this->line('How this decides: a tab is listed when its first cell is at row index 1 and column index 1 (the editor starts at 0, the old importer started at 1),');
        $this->line('no cell of it has ever been edited in the editor (no cell_history rows: an import leaves none), and it has no comments, named ranges or charts.');
        $this->line('It reads the database only and changes nothing. A tab that someone built by hand and left row 1 and column A empty looks the same: check before fixing.');
        $this->newLine();

        $found = $repair->detect();

        if ($found === []) {
            $this->info('No offset imports found.');

            return self::SUCCESS;
        }

        $this->table(
            ['Tab', 'Spreadsheet', 'Name', 'Tab name', 'Cells', 'Formulas', 'First row', 'First col'],
            array_map(fn ($f) => [
                $f['tab']->id,
                $f['tab']->spreadsheet_id,
                Spreadsheet::find($f['tab']->spreadsheet_id)?->name,
                $f['tab']->name,
                $f['cells'],
                $f['formulas'],
                $f['min_row'],
                $f['min_col'],
            ], $found),
        );

        $this->newLine();
        $this->line('To fix one tab: php artisan sheet:fix-offset-imports --tab=<id> --confirm');

        return self::SUCCESS;
    }
}
