<?php

namespace App\Services\Integrations;

use App\Models\Team;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The Dot.Files platform file API (Dot.Files docs/programme/PLATFORM_FILE_API.md, v1): store,
 * and update (as a new version) the files Dot.Sheets filed for one company.
 * Every call is a signed POST: HMAC-SHA256 over "{timestamp}.{raw body}" with this platform's
 * secret, in X-Dot-Signature, with X-Dot-Platform and X-Dot-Timestamp. Calls are made while a
 * person waits (uploading is interactive), so failures come back as a sentence they can act on,
 * and the last outcome is kept for the Integrations page.
 */
final class FilesClient
{
    public const MAX_BYTES = 25 * 1024 * 1024;

    /** The formats Dot.Files accepts from platforms. */
    public const EXTENSIONS = ['pdf', 'csv', 'txt', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'zip', 'mp4', 'mp3', 'mov'];

    private const KEY_LAST = 'integrations:files:last';

    public function enabled(): bool
    {
        return filled(config('services.files.url')) && filled(config('services.files.secret'));
    }

    /** @return array<string, mixed> {uuid, name, size, mime, reference, open_url} */
    public function store(Team $team, string $name, string $bytes, string $folder): array
    {
        return $this->call('store', $team, ['name' => $name, 'content_b64' => base64_encode($bytes), 'folder' => mb_substr($folder, 0, 255)]);
    }

    /** @return array<string, mixed> meta plus {version, checksum} */
    public function update(Team $team, string $uuid, string $bytes, ?int $baseVersion, ?string $name = null): array
    {
        return $this->call('update', $team, array_filter(['uuid' => $uuid, 'content_b64' => base64_encode($bytes), 'base_version' => $baseVersion, 'name' => $name], fn ($v) => $v !== null));
    }

    /** @return array{at: string, ok: bool, message: string|null}|null the last call's outcome */
    public static function last(): ?array
    {
        try {
            return Cache::get(self::KEY_LAST);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function call(string $endpoint, Team $team, array $payload): array
    {
        if (! $this->enabled()) {
            throw new FilesRefused('Dot.Files is not set up on this server yet.');
        }
        if (! filled($team->infodot_team_uuid)) {
            throw new FilesRefused('This business is not linked to its InfoDot company, so Dot.Files cannot place it.');
        }
        $body = (string) json_encode(['team_uuid' => $team->infodot_team_uuid] + $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $ts = (string) Carbon::now()->timestamp;
        try {
            $response = Http::timeout((int) config('services.files.timeout', 20))->connectTimeout(5)->acceptJson()
                ->withHeaders([
                    'X-Dot-Platform' => (string) config('services.files.platform', 'sheet'),
                    'X-Dot-Timestamp' => $ts,
                    'X-Dot-Signature' => 'sha256='.hash_hmac('sha256', $ts.'.'.$body, (string) config('services.files.secret')),
                ])
                ->withBody($body, 'application/json')
                ->post(rtrim((string) config('services.files.url'), '/').'/api/platform/files/'.$endpoint);
        } catch (ConnectionException) {
            $this->fail('Dot.Files could not be reached. Try again in a moment.');
        } catch (Throwable $e) {
            report($e);
            $this->fail('The call to Dot.Files failed. Try again in a moment.');
        }
        $status = $response->status();
        if ($status >= 200 && $status < 300) {
            $this->remember(true, null);

            return (array) $response->json();
        }
        $this->fail(match (true) {
            $status === 401 => 'Dot.Files did not accept our signature: FILES_PLATFORM_SECRET here must equal DOT_SERVICE_SECRET_SHEET on Dot.Files.',
            $status === 404 => 'Dot.Files no longer has this document, or does not know this business.',
            $status === 409 => 'Someone saved a newer version in Dot.Files since this one. Open it there before saving over it.',
            $status === 413 => 'The file is larger than the 25 MB Dot.Files accepts from other apps. Upload it in Dot.Files itself.',
            $status === 422 => 'Dot.Files refused the file: it accepts '.implode(', ', self::EXTENSIONS).'.',
            $status === 429 => 'Dot.Files is busy. Try again in a minute.',
            default => 'Dot.Files had an error ('.$status.'). Try again in a moment.',
        }, $status);
    }

    private function fail(string $message, ?int $status = null): never
    {
        $this->remember(false, $message);

        throw new FilesRefused($message, $status);
    }

    private function remember(bool $ok, ?string $message): void
    {
        try {
            Cache::put(self::KEY_LAST, ['at' => Carbon::now()->toIso8601String(), 'ok' => $ok, 'message' => $message], now()->addDays(30));
        } catch (Throwable) {
        }
    }
}
