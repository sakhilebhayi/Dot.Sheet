<?php

namespace App\Console\Commands;

use App\Services\Integrations\Outbox;
use Illuminate\Console\Command;

/** Sends what is due in the integration outbox (every minute), and prunes daily. */
class DeliverIntegrations extends Command
{
    protected $signature = 'integrations:deliver {--prune : Remove delivered rows older than 30 days instead}';

    protected $description = 'Send due calls to other Dot platforms from the outbox';

    public function handle(Outbox $outbox): int
    {
        if ($this->option('prune')) {
            $this->info('Integrations: '.$outbox->prune().' delivered row(s) removed.');

            return self::SUCCESS;
        }
        $out = $outbox->deliver();
        $this->info('Integrations: '.$out['sent'].' sent, '.$out['retrying'].' to retry, '.$out['given_up'].' not sent.');

        return self::SUCCESS;
    }
}
