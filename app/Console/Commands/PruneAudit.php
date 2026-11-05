<?php

namespace App\Console\Commands;

use App\Models\AuditEvent;
use Illuminate\Console\Command;

class PruneAudit extends Command
{
    protected $signature = 'sheet:prune-audit';

    protected $description = 'Remove activity records older than the time they are kept for';

    public function handle(): int
    {
        $removed = AuditEvent::where('created_at', '<', now()->subDays((int) config('sheet.audit_keep_days')))->delete();
        $this->info("Activity: {$removed} old record(s) removed.");

        return self::SUCCESS;
    }
}
