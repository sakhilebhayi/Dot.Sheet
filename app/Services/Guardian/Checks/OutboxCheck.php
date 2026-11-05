<?php

namespace App\Services\Guardian\Checks;

use App\Models\IntegrationDelivery;
use App\Services\Guardian\CheckResult;
use App\Services\Guardian\Contracts\GuardianCheck;
use Illuminate\Support\Carbon;

/**
 * Calls to other Dot apps: critical when due calls sit unsent (the sender has stopped), a warning when any were
 * given up in the last day (a neighbour refused them, or stayed down through every retry).
 */
class OutboxCheck implements GuardianCheck
{
    private const OVERDUE_SECONDS = 600;

    public function key(): string
    {
        return 'outbox';
    }

    public function run(): CheckResult
    {
        try {
            $oldest = IntegrationDelivery::where('status', 'pending')->where('next_attempt_at', '<=', Carbon::now())->min('next_attempt_at');
            $overdue = $oldest === null ? 0 : max(0, (int) Carbon::parse((string) $oldest)->diffInSeconds(Carbon::now()));
            $waiting = IntegrationDelivery::where('status', 'pending')->count();
            $givenUp = IntegrationDelivery::where('status', 'given_up')->where('updated_at', '>=', Carbon::now()->subDay())->count();
        } catch (\Throwable $e) {
            return CheckResult::unknown('Outbox unreadable.', ['error' => $e::class]);
        }

        $metrics = ['overdue_seconds' => $overdue, 'waiting' => $waiting, 'given_up_24h' => $givenUp];

        if ($overdue > self::OVERDUE_SECONDS) {
            return CheckResult::critical('Calls to other Dot apps are overdue: the sender is not running.', $metrics);
        }

        if ($givenUp > 0) {
            return CheckResult::warning('Some calls to other Dot apps were given up in the last day.', $metrics);
        }

        return CheckResult::healthy('Outbox moving.', $metrics);
    }
}
