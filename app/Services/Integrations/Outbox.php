<?php

namespace App\Services\Integrations;

use App\Models\IntegrationDelivery;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Calls to other Dot platforms go through here. push() only writes a row (never a network call
 * inside a person's request, never an exception into it); deliver() is run by the scheduler
 * every minute and sends what is due.
 *
 * Rules:
 *  - Idempotent: a delivery's key is unique per integration, so the same event is never queued
 *    twice; the key also travels to the platform, which drops replays on its side.
 *  - Safe retries: unreachable, overloaded (429) or erroring (5xx) platforms are retried after 1,
 *    5, 15, 60 and 240 minutes, then given up. A refusal that retrying cannot fix (bad signature,
 *    unknown business, invalid event) is given up at once, with the reason.
 *  - Tracked: every outcome stays on the row; the Integrations page shows what is waiting, sent
 *    and not sent, and a person can send a given-up row again.
 *  - Private: payloads are encrypted at rest and pruned 30 days after sending.
 */
final class Outbox
{
    public const SENT = 'sent';

    public const RETRY = 'retry';

    public const GIVE_UP = 'give_up';

    /** Minutes to wait before each retry. */
    public const BACKOFF = [1, 5, 15, 60, 240];

    public const KEEP_DAYS = 30;

    /** @param  array<string, mixed>  $payload */
    public function push(string $integration, ?int $teamId, string $event, string $key, array $payload): ?IntegrationDelivery
    {
        try {
            return IntegrationDelivery::query()->create([
                'integration' => $integration, 'team_id' => $teamId, 'event' => mb_substr($event, 0, 80), 'key' => mb_substr($key, 0, 190),
                'payload' => $payload, 'status' => 'pending', 'next_attempt_at' => Carbon::now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            return null; // already queued
        } catch (Throwable $e) {
            report($e); // an outbox that cannot be written must never break the change that raised it

            return null;
        }
    }

    /** Sends what is due, at most $limit rows. @return array{sent: int, retrying: int, given_up: int} */
    public function deliver(int $limit = 50): array
    {
        $out = ['sent' => 0, 'retrying' => 0, 'given_up' => 0];
        $due = IntegrationDelivery::query()->where('status', 'pending')->where('next_attempt_at', '<=', Carbon::now())->orderBy('id')->limit($limit)->get();
        foreach ($due as $d) {
            [$outcome, $status, $reason] = $this->send($d);
            $attempts = $d->attempts + 1;
            if ($outcome === self::RETRY && $attempts > count(self::BACKOFF)) {
                [$outcome, $reason] = [self::GIVE_UP, 'Gave up after '.$attempts.' attempts. '.$reason];
            }
            $d->forceFill(match ($outcome) {
                self::SENT => ['status' => 'sent', 'sent_at' => Carbon::now(), 'last_error' => null],
                self::RETRY => ['status' => 'pending', 'next_attempt_at' => Carbon::now()->addMinutes(self::BACKOFF[$attempts - 1])],
                default => ['status' => 'given_up', 'next_attempt_at' => null],
            } + ['attempts' => $attempts, 'last_status' => $status, 'last_error' => $reason === null ? null : mb_substr($reason, 0, 300)])->save();
            $out[match ($outcome) {
                self::SENT => 'sent', self::RETRY => 'retrying', default => 'given_up'
            }]++;
        }

        return $out;
    }

    /** Puts a row that was not sent back in the queue, to go at the next run. */
    public function again(IntegrationDelivery $d): void
    {
        if ($d->status === 'given_up') {
            $d->forceFill(['status' => 'pending', 'attempts' => 0, 'next_attempt_at' => Carbon::now()])->save();
        }
    }

    /** Removes delivered rows older than KEEP_DAYS, so addresses do not linger. */
    public function prune(): int
    {
        return IntegrationDelivery::query()->where('status', 'sent')->where('sent_at', '<', Carbon::now()->subDays(self::KEEP_DAYS))->delete();
    }

    /** @return array{0: string, 1: int|null, 2: string|null} */
    private function send(IntegrationDelivery $d): array
    {
        try {
            return match ($d->integration) {
                'notify' => app(NotifyClient::class)->deliver($d->payload),
                'analytics' => app(AnalyticsClient::class)->deliver($d->payload),
                default => [self::GIVE_UP, null, 'No client for '.$d->integration.'.'],
            };
        } catch (Throwable $e) {
            report($e);

            return [self::RETRY, null, 'Sending failed unexpectedly ('.class_basename($e).').'];
        }
    }
}
