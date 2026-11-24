<?php

namespace App\Services\InfoDot;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Asks InfoDot whether a company still holds this platform (R-50), and remembers the answer.
 *
 * InfoDot decides; this only reads `GET {infodot}/api/ecosystem/companies/{uuid}/platforms` with this platform's own
 * service secret and looks at this platform's own row. A clear answer is kept for one minute per company, so a switch
 * made at InfoDot (a platform put in maintenance, R-61) takes effect within about a minute, and is also remembered for
 * six hours. A failure of any kind (no secret, timeout, refused, unknown company, a body that is not the expected shape,
 * no row for this platform) is "unknown": the last clear answer under six hours old is used (a platform last told
 * unavailable stays unavailable); with none, the caller carries on, so an outage or a misconfiguration at InfoDot never
 * locks a company out. Unknown is never reported to anyone as "active".
 */
class CompanyEntitlement
{
    public const ENTITLED = 'entitled';

    public const REFUSED = 'refused';

    public const UNKNOWN = 'unknown';

    private const ANSWER_SECONDS = 60;

    /** How long the last clear answer is used when InfoDot gives no clear answer. */
    private const LAST_KNOWN_SECONDS = 21600;

    private const FAILURE_SECONDS = 60;

    public function enabled(): bool
    {
        return (bool) config('ecosystem.infodot.entitlement_check', true);
    }

    /**
     * @return array{state: string, status: string|null, available: bool, message: string|null}
     */
    public function for(string $companyUuid): array
    {
        $key = 'infodot:entitlement:'.$this->platform().':'.$companyUuid;

        try {
            $cached = Cache::get($key);
        } catch (Throwable) {
            $cached = null;
        }

        if (is_array($cached) && is_array($cached['answer'] ?? null)) {
            return $cached['answer'];
        }

        $answer = $this->ask($companyUuid);

        // A failure is logged inside ask(), where it is also cached: once per window, not once per request.
        try {
            if ($answer['state'] !== self::UNKNOWN) {
                Cache::put($key.':last', ['answer' => $answer, 'at' => time()], self::LAST_KNOWN_SECONDS);
            } else {
                $last = Cache::get($key.':last');

                if (is_array($last) && is_array($last['answer'] ?? null) && time() - (int) ($last['at'] ?? 0) < self::LAST_KNOWN_SECONDS) {
                    $answer = $last['answer'];
                }
            }

            Cache::put($key, ['answer' => $answer], $answer['state'] === self::UNKNOWN ? self::FAILURE_SECONDS : self::ANSWER_SECONDS);
        } catch (Throwable) {
            // No cache, no memory of the answer: the next request asks again.
        }

        return $answer;
    }

    private function platform(): string
    {
        return (string) config('ecosystem.infodot.platform', 'sheet');
    }

    private function url(string $companyUuid): ?string
    {
        $base = (string) config('ecosystem.infodot.companies_url', '');

        if ($base === '') {
            $redeem = (string) config('ecosystem.infodot.redeem_url', '');
            $base = str_ends_with($redeem, '/redeem') ? substr($redeem, 0, -strlen('/redeem')).'/companies' : '';
        }

        return $base === '' ? null : rtrim($base, '/').'/'.rawurlencode($companyUuid).'/platforms';
    }

    /**
     * @return array{state: string, status: string|null, available: bool, message: string|null}
     */
    private function ask(string $companyUuid): array
    {
        $bearer = (string) config('ecosystem.infodot.secret', '');
        $url = $this->url($companyUuid);

        if ($bearer === '' || $url === null) {
            return $this->unknown('not configured');
        }

        try {
            $response = Http::withToken($bearer)
                ->withHeaders(['X-Dot-Platform' => $this->platform()])
                ->acceptJson()
                ->connectTimeout(2)
                ->timeout(max(1, (int) config('ecosystem.infodot.entitlement_timeout', 3)))
                ->get($url, ['platform' => $this->platform()]);
        } catch (Throwable $e) {
            return $this->unknown('unreachable ('.class_basename($e).')');
        }

        if (! $response->successful()) {
            return $this->unknown('status '.$response->status());
        }

        $body = $response->json();
        $rows = is_array($body) ? ($body['platforms'] ?? null) : null;

        if (! is_array($rows) || ($body['company']['uuid'] ?? null) !== $companyUuid) {
            return $this->unknown('unexpected body');
        }

        foreach ($rows as $row) {
            if (! is_array($row) || ($row['key'] ?? null) !== $this->platform()) {
                continue;
            }

            if (! is_bool($row['entitled'] ?? null)) {
                return $this->unknown('unexpected body');
            }

            return [
                'state' => $row['entitled'] ? self::ENTITLED : self::REFUSED,
                'status' => is_string($row['status'] ?? null) ? $row['status'] : null,
                // R-61: an InfoDot from before the contract gives no `available`, which is not a switch-off.
                'available' => ($row['available'] ?? true) !== false,
                'message' => is_string($row['message'] ?? null) && $row['message'] !== '' ? mb_substr($row['message'], 0, 400) : null,
            ];
        }

        return $this->unknown('no row for this platform');
    }

    /**
     * @return array{state: string, status: string|null, available: bool, message: string|null}
     */
    private function unknown(string $why): array
    {
        Log::warning('InfoDot entitlement check could not be answered; people carry on.', ['platform' => $this->platform(), 'reason' => $why]);

        return ['state' => self::UNKNOWN, 'status' => null, 'available' => true, 'message' => null];
    }
}
