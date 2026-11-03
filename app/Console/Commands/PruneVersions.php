<?php

namespace App\Console\Commands;

use App\Models\SpreadsheetVersion;
use App\Services\SheetVersions;
use Illuminate\Console\Command;

class PruneVersions extends Command
{
    protected $signature = 'sheet:prune-versions';

    protected $description = 'Keep the newest automatic and safety copies of each sheet and remove older ones; copies people made are kept.';

    public function handle(SheetVersions $versions): int
    {
        $removed = 0;

        SpreadsheetVersion::query()->whereIn('kind', [SheetVersions::AUTO, SheetVersions::SAFETY])
            ->whereNotNull('sheet_tab_id')->distinct()->orderBy('sheet_tab_id')
            ->pluck('sheet_tab_id')
            ->each(function ($sheetTabId) use ($versions, &$removed) {
                $removed += $versions->prune((int) $sheetTabId);
            });

        $this->info("Removed {$removed} old cop".($removed === 1 ? 'y' : 'ies').'.');

        return self::SUCCESS;
    }
}
