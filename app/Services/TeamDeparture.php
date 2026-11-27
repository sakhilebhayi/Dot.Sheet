<?php

namespace App\Services;

use App\Models\Spreadsheet;
use App\Models\Team;
use App\Models\User;
use App\Support\WorkbookAudit;
use Illuminate\Support\Facades\DB;

/**
 * When someone leaves a team (removed by an admin, left on their own, or no longer listed by InfoDot),
 * the team's workbooks they created stay with the team: ownership passes to the team's owner, so the
 * person who left keeps no owner rights over company data.
 */
final class TeamDeparture
{
    /**
     * @return int how many workbooks were handed over
     */
    public function handOver(Team $team, User $leaver): int
    {
        $newOwnerId = (int) $team->user_id;
        if ($newOwnerId === $leaver->id) {
            return 0; // a team's owner cannot leave it
        }

        $newOwner = User::find($newOwnerId);

        return DB::transaction(function () use ($team, $leaver, $newOwner, $newOwnerId) {
            $workbooks = Spreadsheet::query()
                ->where('team_id', $team->id)
                ->where('owner_id', $leaver->id)
                ->get();

            foreach ($workbooks as $workbook) {
                $workbook->forceFill(['owner_id' => $newOwnerId])->save();
                WorkbookAudit::record(
                    $workbook,
                    $newOwner,
                    'Passed to the team owner',
                    'previous owner left the team: '.$leaver->name,
                );
            }

            return $workbooks->count();
        });
    }
}
