<?php

namespace App\Http\Controllers;

use App\Models\AuditEvent;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** What has been done to a business's workbooks, for its owner and admins. Anyone sees the workbooks they own. */
class ActivityController extends Controller
{
    private const PER_PAGE = 50;

    public function __invoke(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();
        /** @var Team|null $team */
        $team = $user->currentTeam;
        $oversees = IntegrationsController::oversees($user, $team);

        $events = AuditEvent::where(function ($query) use ($user, $team, $oversees) {
            $query->where('owner_id', $user->id);
            if ($oversees) {
                $query->orWhere('team_id', $team?->id);
            }
        })->orderByDesc('id')->paginate(self::PER_PAGE);

        return view('activity', ['events' => $events, 'team' => $oversees ? $team : null]);
    }
}
