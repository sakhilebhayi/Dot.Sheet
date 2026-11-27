<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * EA-4 (P-07): cell history grew by one row per changed cell for ever. Rows older than a year go, except each
 * cell's newest one, which says who last changed it.
 */
class PruneCellHistoryCommand extends Command
{
    protected $signature = 'sheet:prune-cell-history {--days=365}';

    protected $description = 'Delete cell history older than --days, keeping each cell\'s newest row';

    public function handle(): int
    {
        $before = now()->subDays(max(30, (int) $this->option('days')));
        $deleted = 0;

        do {
            $ids = DB::table('cell_history as h')
                ->where('h.created_at', '<', $before)
                ->whereExists(fn ($newer) => $newer->from('cell_history as n')->whereColumn('n.cell_id', 'h.cell_id')->whereColumn('n.id', '>', 'h.id'))
                ->limit(5000)
                ->pluck('h.id');
            $deleted += DB::table('cell_history')->whereIn('id', $ids)->delete();
        } while ($ids->count() === 5000);

        $this->info("Deleted {$deleted} old cell history rows.");

        return self::SUCCESS;
    }
}
