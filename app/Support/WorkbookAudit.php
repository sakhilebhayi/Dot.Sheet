<?php

namespace App\Support;

use App\Models\AuditEvent;
use App\Models\Spreadsheet;
use App\Models\User;
use Throwable;

/**
 * Writes one line to a workbook's Activity from code that is not a named HTTP route (Livewire panels,
 * team changes). Never throws: a failed audit write is reported, and the action it describes stands.
 */
final class WorkbookAudit
{
    public static function record(Spreadsheet $spreadsheet, ?User $actor, string $action, ?string $detail = null): void
    {
        try {
            AuditEvent::create([
                'team_id' => $spreadsheet->team_id,
                'spreadsheet_name' => mb_substr((string) $spreadsheet->name, 0, 255),
                'owner_id' => $spreadsheet->owner_id,
                'spreadsheet_id' => $spreadsheet->id,
                'user_id' => $actor?->id,
                'user_name' => mb_substr((string) $actor?->name, 0, 255),
                'action' => mb_substr($action, 0, 60),
                'detail' => $detail === null || $detail === '' ? null : mb_substr($detail, 0, 300),
                'ip' => app()->runningInConsole() ? null : request()->ip(),
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
