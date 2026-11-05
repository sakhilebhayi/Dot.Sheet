<?php

namespace App\Services\Integrations;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Posting to a Dot platform's signed ingest (Dot.Notify's events, Dot.Analytics' snapshots): HMAC-SHA256 over
 * "{timestamp}.{raw body}" with this platform's secret, sent as "sha256=<hex>" in X-InfoDot-Signature with the
 * unix time in X-InfoDot-Timestamp, made at send time so a retry is never stale. A small circuit breaker stops
 * calling a failing platform for a minute. Never throws: it says what the outbox should do next.
 */
abstract class SignedIngestClient
{
    private const FAILURE_THRESHOLD = 5;

    private const PAUSE_SECONDS = 60;

    /** The key under config('services.*'): url, secret and timeout. */
    abstract protected function service(): string;

    /** The platform's name, as people read it ("Dot.Notify"). */
    abstract protected function name(): string;

    /** What to set when it is not set up. */
    abstract protected function setUpHint(): string;

    /** Why the platform refused, for a status retrying cannot fix. */
    abstract protected function refusal(int $status): string;

    public function enabled(): bool
    {
        return filled(config('services.'.$this->service().'.url')) && filled(config('services.'.$this->service().'.secret'));
    }

    /**
     * Posts one payload and says what the outbox should do next, with the HTTP status and a reason a person can
     * act on.
     *
     * @param  array<string, mixed>  $payload
     * @return array{0: string, 1: int|null, 2: string|null} [Outbox::SENT|RETRY|GIVE_UP, status, reason]
     */
    public function deliver(array $payload): array
    {
        if (! $this->enabled()) {
            return [Outbox::GIVE_UP, null, $this->name().' is not set up on this server ('.$this->setUpHint().').'];
        }
        if ($this->circuitOpen()) {
            return [Outbox::RETRY, null, $this->name().' failed several times in a row; pausing for a minute.'];
        }
        try {
            $body = (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $timestamp = (string) now()->timestamp;
            $timeout = (int) config('services.'.$this->service().'.timeout', 5);
            $response = Http::timeout($timeout)->connectTimeout($timeout)->acceptJson()
                ->withHeaders([
                    'X-InfoDot-Timestamp' => $timestamp,
                    'X-InfoDot-Signature' => 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, (string) config('services.'.$this->service().'.secret')),
                ])
                ->withBody($body, 'application/json')
                ->post((string) config('services.'.$this->service().'.url'));
        } catch (ConnectionException) {
            $this->recordFailure();

            return [Outbox::RETRY, null, $this->name().' could not be reached.'];
        } catch (Throwable $e) {
            $this->recordFailure();

            return [Outbox::RETRY, null, 'The call to '.$this->name().' failed ('.class_basename($e).').'];
        }

        $status = $response->status();
        if ($status >= 200 && $status < 300) {
            $this->recordSuccess();

            return [Outbox::SENT, $status, null];
        }
        if ($status === 429 || $status >= 500) {
            $this->recordFailure();

            return [Outbox::RETRY, $status, $status === 429 ? $this->name().' asked us to slow down.' : $this->name().' had an error.'];
        }

        // Retrying cannot fix these, and they say nothing about the platform's health.
        return [Outbox::GIVE_UP, $status, $this->refusal($status)];
    }

    private function key(string $what): string
    {
        return 'integrations:'.$this->service().':'.$what;
    }

    private function circuitOpen(): bool
    {
        try {
            return (int) Cache::get($this->key('open-until'), 0) > now()->timestamp;
        } catch (Throwable) {
            return false;
        }
    }

    private function recordFailure(): void
    {
        try {
            $failures = (int) Cache::get($this->key('failures'), 0) + 1;
            Cache::put($this->key('failures'), $failures, 300);
            if ($failures >= self::FAILURE_THRESHOLD) {
                Cache::put($this->key('open-until'), now()->timestamp + self::PAUSE_SECONDS, self::PAUSE_SECONDS);
            }
        } catch (Throwable) {
            // The breaker is a courtesy to the other platform; losing it must not matter.
        }
    }

    private function recordSuccess(): void
    {
        try {
            Cache::forget($this->key('failures'));
            Cache::forget($this->key('open-until'));
        } catch (Throwable) {
        }
    }
}
