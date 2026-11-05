<?php

namespace App\Services\Integrations;

use App\Models\Spreadsheet;
use App\Models\User;
use App\Models\WorkbookFiling;
use App\Services\ExportTooLargeException;
use App\Services\WorkbookWriter;
use Illuminate\Support\Str;

/**
 * A workbook filed in its business's Dot.Files workspace as an Excel file. The first save makes the document, each
 * later one a new version, and Dot.Files refuses a save if someone moved the document on: nothing is overwritten.
 */
final class WorkbookFilings
{
    public function __construct(private FilesClient $files, private WorkbookWriter $writer) {}

    /**
     * @return array{version: int|null, open_url: string|null}
     *
     * @throws FilesRefused|ExportTooLargeException
     */
    public function file(Spreadsheet $spreadsheet, User $user): array
    {
        $team = $spreadsheet->team;
        if ($team === null) {
            throw new FilesRefused('Move this to a team first. Dot.Files keeps a business\'s documents.');
        }

        $bytes = $this->bytesOf($spreadsheet);
        $filing = WorkbookFiling::where('spreadsheet_id', $spreadsheet->id)->where('team_id', $team->id)->first();

        if ($filing === null) {
            $reply = $this->files->store($team, Str::slug((string) $spreadsheet->name, '-').'.xlsx', $bytes, (string) config('services.files.folder'));
        } else {
            try {
                $reply = $this->files->update($team, $filing->file_uuid, $bytes, $filing->version);
            } catch (FilesRefused $refused) {
                if ($refused->status === 404) {
                    $filing->delete();

                    throw new FilesRefused('Dot.Files no longer has this document. Save again to file it as a new one.', 404);
                }

                throw $refused;
            }
        }

        $openUrl = $this->sameHost((string) ($reply['open_url'] ?? '')) ?? $filing?->open_url;
        $version = isset($reply['version']) ? (int) $reply['version'] : null;

        WorkbookFiling::updateOrCreate(['spreadsheet_id' => $spreadsheet->id], [
            'team_id' => $team->id,
            'file_uuid' => (string) ($reply['uuid'] ?? $filing?->file_uuid),
            'version' => $version,
            'open_url' => $openUrl,
            'filed_by' => $user->id,
        ]);

        return ['version' => $version, 'open_url' => $openUrl];
    }

    private function bytesOf(Spreadsheet $spreadsheet): string
    {
        $path = $this->writer->write($spreadsheet, 'xlsx');
        try {
            $bytes = (string) file_get_contents($path);
        } finally {
            @unlink($path);
        }

        if (strlen($bytes) > FilesClient::MAX_BYTES) {
            throw new FilesRefused('This workbook is larger than the 25 MB Dot.Files accepts from other apps.', 413);
        }

        return $bytes;
    }

    /** A link from another server is only passed to a person if it goes where it should. */
    private function sameHost(string $url): ?string
    {
        $expected = parse_url((string) config('services.files.url'), PHP_URL_HOST);

        return parse_url($url, PHP_URL_SCHEME) === 'https' && parse_url($url, PHP_URL_HOST) === $expected ? mb_substr($url, 0, 500) : null;
    }
}
