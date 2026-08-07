<?php

namespace App\Console\Commands;

use App\Jobs\SimulateSpreadsheetEditJob;
use App\Models\Spreadsheet;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;

class SpreadsheetLoadTestCommand extends Command
{
    protected $signature = 'spreadsheet:load-test
        {spreadsheet : Spreadsheet ID to target}
        {--jobs=20 : Number of concurrent jobs}
        {--iterations=200 : Cell edits per job}
        {--max-row=500 : Max row boundary for random edits}
        {--max-col=50 : Max column boundary for random edits}
        {--poll-ms=500 : Poll interval in milliseconds}';

    protected $description = 'Run a lightweight queued load test that simulates concurrent spreadsheet edits';

    public function handle(): int
    {
        $spreadsheetId = (int) $this->argument('spreadsheet');
        $jobsCount = max(1, (int) $this->option('jobs'));
        $iterations = max(1, (int) $this->option('iterations'));
        $maxRow = max(1, (int) $this->option('max-row'));
        $maxCol = max(1, (int) $this->option('max-col'));
        $pollMs = max(100, (int) $this->option('poll-ms'));

        $spreadsheet = Spreadsheet::find($spreadsheetId);
        if (! $spreadsheet) {
            $this->error('Spreadsheet not found.');

            return self::FAILURE;
        }

        $jobs = [];
        for ($i = 0; $i < $jobsCount; $i++) {
            $jobs[] = new SimulateSpreadsheetEditJob(
                spreadsheetId: $spreadsheetId,
                iterations: $iterations,
                maxRow: $maxRow,
                maxCol: $maxCol,
            );
        }

        $this->info("Dispatching {$jobsCount} jobs x {$iterations} edits...");
        $start = microtime(true);

        $batch = Bus::batch($jobs)
            ->name("load-test:spreadsheet:{$spreadsheetId}")
            ->allowFailures()
            ->dispatch();

        $this->line('Batch ID: '.$batch->id);

        do {
            usleep($pollMs * 1000);
            $fresh = Bus::findBatch($batch->id);
            if (! $fresh) {
                $this->error('Batch could not be found while polling.');

                return self::FAILURE;
            }

            $total = max(1, $fresh->totalJobs);
            $processed = $fresh->processedJobs();
            $progress = (int) floor(($processed / $total) * 100);
            $this->line("Progress: {$progress}% ({$processed}/{$total})");
        } while (! $fresh->finished());

        $duration = microtime(true) - $start;
        $ops = $jobsCount * $iterations;
        $opsPerSec = $duration > 0 ? round($ops / $duration, 2) : $ops;

        $this->newLine();
        $this->info('Load test complete.');
        $this->line('Duration: '.round($duration, 2).'s');
        $this->line('Operations: '.$ops);
        $this->line('Ops/sec: '.$opsPerSec);
        $this->line('Failed jobs: '.$fresh->failedJobs);

        return self::SUCCESS;
    }
}
