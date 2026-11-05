<?php

namespace App\Services\Integrations;

use App\Models\Cell;
use App\Models\Spreadsheet;
use App\Models\Team;
use App\Models\WorkbookApproval;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The daily numbers Dot.Sheets gives Dot.Analytics for one business, in the keys of Analytics' catalogue for
 * `sheet`. Counts only: no workbook names, people or cell contents leave here.
 */
final class AnalyticsSnapshot
{
    public const KEYS = ['workbooks', 'edited_7d', 'formula_errors', 'awaiting_approval', 'approved', 'templates'];

    /** @return array<string, int> */
    public function metrics(Team $team, ?Carbon $now = null): array
    {
        $now ??= Carbon::now();
        $own = Spreadsheet::where('team_id', $team->id)->select('id');
        $latest = WorkbookApproval::whereIn('spreadsheet_id', $own)->groupBy('spreadsheet_id')->select(DB::raw('max(id)'));
        $states = WorkbookApproval::whereIn('id', $latest)->pluck('state')->countBy();

        return [
            'workbooks' => Spreadsheet::where('team_id', $team->id)->count(),
            'edited_7d' => Cell::whereIn('spreadsheet_id', $own)->where('updated_at', '>=', $now->copy()->subDays(7))->distinct()->count('spreadsheet_id'),
            'formula_errors' => Cell::whereIn('spreadsheet_id', $own)->whereNotNull('formula_error')->count(),
            'awaiting_approval' => (int) ($states[WorkbookApproval::WAITING] ?? 0),
            'approved' => (int) ($states[WorkbookApproval::APPROVED] ?? 0),
            'templates' => Spreadsheet::where('team_id', $team->id)->where('is_template', true)->count(),
        ];
    }

    /**
     * Today's snapshot for a business linked to InfoDot, once a day: the key is the business and the date, and
     * taken_at is fixed in the payload, so a retry sends the very same snapshot.
     */
    public function queue(Team $team, ?Carbon $now = null): bool
    {
        $now ??= Carbon::now();
        if (blank($team->infodot_team_uuid)) {
            return false;
        }

        return app(Outbox::class)->push('analytics', $team->id, 'sheet.snapshot', 'snapshot:'.$team->id.':'.$now->copy()->utc()->toDateString(), [
            'company_uuid' => (string) $team->infodot_team_uuid,
            'platform' => 'sheet',
            'taken_at' => $now->copy()->utc()->format('Y-m-d\TH:i:s\Z'),
            'period' => 'day',
            'metrics' => $this->metrics($team, $now),
        ]) !== null;
    }
}
