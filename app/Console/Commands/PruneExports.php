<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class PruneExports extends Command
{
    protected $signature = 'sheet:prune-exports {--minutes=60 : Delete export files older than this}';

    protected $description = 'Delete Excel export files left in storage/app/private/exports (the download deletes its own file, a cancelled download does not).';

    public function handle(): int
    {
        $disk = Storage::disk('local');
        $cutoff = now()->subMinutes(max(1, (int) $this->option('minutes')))->getTimestamp();
        $deleted = 0;

        foreach ($disk->files('exports') as $file) {
            if ($disk->lastModified($file) < $cutoff) {
                $disk->delete($file);
                $deleted++;
            }
        }

        $this->info("Deleted {$deleted} old export file(s).");

        return self::SUCCESS;
    }
}
