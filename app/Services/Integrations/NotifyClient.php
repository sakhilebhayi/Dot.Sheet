<?php

namespace App\Services\Integrations;

/**
 * Sends one event to Dot.Notify (POST /api/ingest/events): "this happened to this company, tell this person".
 * The scheme is Notify's own, as Dot.HR uses it; the sending, signing and circuit breaker are SignedIngestClient's.
 */
final class NotifyClient extends SignedIngestClient
{
    protected function service(): string
    {
        return 'notify';
    }

    protected function name(): string
    {
        return 'Dot.Notify';
    }

    protected function setUpHint(): string
    {
        return 'NOTIFY_INGEST_URL and NOTIFY_INGEST_SECRET';
    }

    protected function refusal(int $status): string
    {
        return match ($status) {
            401, 403 => 'Dot.Notify did not accept our signature: NOTIFY_INGEST_SECRET here must equal NOTIFY_INGEST_SECRET_SHEET on Notify.',
            404 => 'Dot.Notify does not know this business: it must be linked to InfoDot in both apps.',
            422 => 'Dot.Notify refused the event as invalid.',
            default => 'Dot.Notify answered '.$status.'.',
        };
    }
}
