<?php

namespace App\Services\Integrations;

/**
 * Sends one daily snapshot to Dot.Analytics (POST /api/ingest/snapshots, its docs/metrics-contract.md): the
 * business's counts, signed with this platform's own secret. Analytics stores a snapshot once; a
 * retry of the same one is answered as a replay, so the outbox may send it again safely.
 */
final class AnalyticsClient extends SignedIngestClient
{
    protected function service(): string
    {
        return 'analytics';
    }

    protected function name(): string
    {
        return 'Dot.Analytics';
    }

    protected function setUpHint(): string
    {
        return 'ANALYTICS_INGEST_SECRET';
    }

    protected function refusal(int $status): string
    {
        return match ($status) {
            401, 403 => 'Dot.Analytics did not accept our signature: ANALYTICS_INGEST_SECRET here must equal ANALYTICS_INGEST_SECRET_SHEET on Analytics, and Analytics must list Dot.Projects in its catalogue.',
            404 => 'Dot.Analytics does not know this business: it must be linked to InfoDot in both apps.',
            413 => 'Dot.Analytics refused the snapshot as too large.',
            422 => 'Dot.Analytics refused the snapshot as invalid.',
            default => 'Dot.Analytics answered '.$status.'.',
        };
    }
}
