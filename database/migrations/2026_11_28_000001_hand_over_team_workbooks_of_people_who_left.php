<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * EA-1 (S-01): before this release, leaving a team kept the leaver as owner of the team workbooks they had
 * created. Hand every such workbook to its team's owner, as TeamDeparture now does at the moment someone
 * leaves. Data only; nothing to undo (the previous owners are gone from those teams).
 */
return new class extends Migration
{
    public function up(): void
    {
        $orphans = DB::table('spreadsheets')
            ->join('teams', 'teams.id', '=', 'spreadsheets.team_id')
            ->whereColumn('spreadsheets.owner_id', '!=', 'teams.user_id')
            ->whereNotExists(fn ($q) => $q->from('team_user')
                ->whereColumn('team_user.team_id', 'spreadsheets.team_id')
                ->whereColumn('team_user.user_id', 'spreadsheets.owner_id'))
            ->select('spreadsheets.id', 'spreadsheets.name', 'spreadsheets.team_id', 'spreadsheets.owner_id', 'teams.user_id as team_owner_id')
            ->get();

        foreach ($orphans as $row) {
            DB::table('spreadsheets')->where('id', $row->id)->update(['owner_id' => $row->team_owner_id]);
            DB::table('audit_events')->insert([
                'team_id' => $row->team_id,
                'spreadsheet_id' => $row->id,
                'spreadsheet_name' => mb_substr((string) $row->name, 0, 255),
                'owner_id' => $row->team_owner_id,
                'user_id' => null,
                'user_name' => 'System',
                'action' => 'Passed to the team owner',
                'detail' => 'previous owner had left the team',
                'ip' => null,
                'created_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Intentionally empty: restoring a former member's ownership would reopen the gap this closes.
    }
};
