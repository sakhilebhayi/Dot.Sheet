<?php

namespace App\Console\Commands;

use App\Models\Team;
use App\Services\Integrations\AnalyticsClient;
use App\Services\Integrations\AnalyticsSnapshot;
use Illuminate\Console\Command;

class SendAnalyticsSnapshots extends Command
{
    protected $signature = 'integrations:analytics-snapshots';

    protected $description = 'Queue today\'s numbers for Dot.Analytics, for each business linked to InfoDot';

    public function handle(AnalyticsClient $analytics, AnalyticsSnapshot $snapshots): int
    {
        if (! $analytics->enabled()) {
            $this->info('Dot.Analytics is not set up here, so nothing was queued.');

            return self::SUCCESS;
        }

        $queued = 0;
        Team::whereNotNull('infodot_team_uuid')->whereHas('spreadsheets')->each(function (Team $team) use ($snapshots, &$queued) {
            $queued += $snapshots->queue($team) ? 1 : 0;
        });
        $this->info("Dot.Analytics: {$queued} snapshot(s) queued.");

        return self::SUCCESS;
    }
}
