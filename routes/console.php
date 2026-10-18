<?php

use App\Http\Controllers\SchedulerProbeController;
use App\Jobs\QueueHeartbeat;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Liveness of the platform itself. /up/scheduler reports this heartbeat's
// age and answers 503 once it is older than
// SchedulerProbeController::SCHEDULER_STALE_SECONDS; the Scheduler Watchdog
// workflow turns that into a failed (emailed) run every 15 minutes, and the
// Guardian's scheduler check reads the same key.
Schedule::call(fn () => Cache::forever(SchedulerProbeController::SCHEDULER_KEY, now()->toIso8601String()))
    ->everyMinute()
    ->name('scheduler-heartbeat');

// Proves the cron-driven queue worker actually drains jobs, not just that
// the scheduler fires: only a worker that runs this can refresh the key.
Schedule::job(new QueueHeartbeat)->everyFiveMinutes()->name('queue-heartbeat');

// An Excel export is deleted after it is sent; one whose download was cancelled stays behind.
Schedule::command('sheet:prune-exports')->hourly()->name('prune-exports')->withoutOverlapping();
