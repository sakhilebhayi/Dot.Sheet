<?php

namespace App\Http\Middleware;

use App\Models\Team;
use App\Models\User;
use App\Services\InfoDot\CompanyEntitlement;
use App\Support\EntitlementRefusal;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * On the signed-in area: stops when InfoDot says the person's CURRENT company no longer holds Dot.Sheet (R-50).
 *
 * Only a clear "not entitled" stops a request, before any controller runs, so no data is read or changed. Every other
 * outcome carries on: the check switched off, no signed-in person, a workspace that is not an InfoDot company
 * (a personal team, or a team never linked), and any failure to get an answer. Switching company, signing out, the
 * InfoDot sign-in receiver, health checks and public pages are outside the routes this sits on.
 */
class EnsureCompanyEntitled
{
    public function __construct(private CompanyEntitlement $entitlement) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->entitlement->enabled()) {
            return $next($request);
        }

        $user = $request->user();
        $company = $user instanceof User ? $user->currentTeam : null;

        if (! $company instanceof Team || blank($company->infodot_team_uuid)) {
            return $next($request);
        }

        $answer = $this->entitlement->for((string) $company->infodot_team_uuid);

        if ($answer['state'] !== CompanyEntitlement::REFUSED) {
            return $next($request);
        }

        return EntitlementRefusal::response($request, $answer['status'], $user, $company);
    }
}
