<?php

namespace App\Console\Commands;

use App\Models\SheetTab;
use App\Services\OffsetImportRepair;
use Illuminate\Console\Command;
use RuntimeException;

class FixOffsetImports extends Command
{
    protected $signature = 'sheet:fix-offset-imports
        {--tab= : Id of the sheet tab to fix (see sheet:detect-offset-imports)}
        {--confirm : Actually change the data. Without it nothing is changed.}';

    protected $description = 'Move one offset-imported tab one row up and one column left (backup first, one transaction). Never runs by itself.';

    public function handle(OffsetImportRepair $repair): int
    {
        $tab = $this->option('tab') ? SheetTab::find((int) $this->option('tab')) : null;
        if ($tab === null) {
            $this->error('Give an existing tab: --tab=<id>. List candidates with sheet:detect-offset-imports.');

            return self::FAILURE;
        }

        if (($reason = $repair->reasonToLeaveAlone($tab)) !== null) {
            $this->error("Tab {$tab->id} is not an offset import: {$reason}.");

            return self::FAILURE;
        }

        if (! $this->option('confirm')) {
            $this->warn("Dry run: tab {$tab->id} would be moved one row up and one column left. Nothing was changed. Add --confirm to do it.");

            return self::SUCCESS;
        }

        try {
            $result = $repair->repair($tab);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Moved {$result['cells']} cells ({$result['formulas']} formulas checked). Backup: {$result['backup']}");

        return self::SUCCESS;
    }
}
