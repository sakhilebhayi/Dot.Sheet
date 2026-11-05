<?php

namespace App\Http\Controllers;

use App\Models\IntegrationDelivery;
use App\Models\Membership;
use App\Models\Team;
use App\Models\User;
use App\Models\WorkbookFiling;
use App\Services\Integrations\AnalyticsClient;
use App\Services\Integrations\FilesClient;
use App\Services\Integrations\NotifyClient;
use App\Services\Integrations\Outbox;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Which Dot platforms Dot.Sheets is connected to, for a business's owner and admins. It never claims a connection
 * works: it shows what is set up and what actually happened, with the reason where something did not go.
 */
class IntegrationsController extends Controller
{
    private const LISTED = 30;

    public static function oversees(User $user, ?Team $team): bool
    {
        return $team !== null && ($user->ownsTeam($team)
            || Membership::where('team_id', $team->id)->where('user_id', $user->id)->value('role') === 'admin');
    }

    public function index(Request $request): View
    {
        $team = $this->team($request);

        $last = fn (string $integration) => IntegrationDelivery::where('team_id', $team->id)->where('integration', $integration)->where('status', 'sent')->max('sent_at');

        return view('integrations', [
            'team' => $team,
            'listed' => self::LISTED,
            'linked' => filled($team->infodot_team_uuid),
            'platforms' => [
                ['name' => 'Dot.Notify', 'does' => 'Emails for mentions and approvals', 'ready' => app(NotifyClient::class)->enabled(), 'last' => $last('notify')],
                ['name' => 'Dot.Analytics', 'does' => 'Daily counts, with no names or contents', 'ready' => app(AnalyticsClient::class)->enabled(), 'last' => $last('analytics')],
                ['name' => 'Dot.Files', 'does' => 'Workbooks saved from the editor', 'ready' => app(FilesClient::class)->enabled(), 'last' => WorkbookFiling::where('team_id', $team->id)->max('updated_at')],
            ],
            'deliveries' => IntegrationDelivery::where('team_id', $team->id)->latest('id')->limit(self::LISTED)->get(),
        ]);
    }

    public function again(Request $request, IntegrationDelivery $delivery, Outbox $outbox): RedirectResponse
    {
        abort_unless($delivery->team_id === $this->team($request)->id, 404);

        $outbox->again($delivery);

        return back()->with('success', 'It will go again in the next minute.');
    }

    private function team(Request $request): Team
    {
        /** @var User $user */
        $user = $request->user();
        /** @var Team|null $team */
        $team = $user->currentTeam;
        abort_unless($team !== null && self::oversees($user, $team), 403);

        return $team;
    }
}
