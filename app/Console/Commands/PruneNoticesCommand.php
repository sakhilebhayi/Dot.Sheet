<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Keeps the notification centre to what is still useful (EA-2, E-02): read notices go after 90 days,
 * unread ones after 180.
 */
class PruneNoticesCommand extends Command
{
    protected $signature = 'notices:prune';

    protected $description = 'Delete read notifications older than 90 days and any older than 180 days';

    public function handle(): int
    {
        $deleted = DB::table('notifications')
            ->where(fn ($q) => $q->whereNotNull('read_at')->where('created_at', '<', now()->subDays(90)))
            ->orWhere('created_at', '<', now()->subDays(180))
            ->delete();

        $this->info("Deleted {$deleted} old notifications.");

        return self::SUCCESS;
    }
}
