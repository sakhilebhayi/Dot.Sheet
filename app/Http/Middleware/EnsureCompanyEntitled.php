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
    /** Session key: what InfoDot said at sign-in about the personal workspace (a bool), absent when it said nothing. */
    public const PERSONAL = 'infodot.personal_entitled';

    public function __construct(private CompanyEntitlement $entitlement) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->entitlement->enabled()) {
            return $next($request);
        }

        $user = $request->user();
        $current = $user instanceof User ? $user->currentTeam : null;
        $company = $current instanceof Team ? $current : null;

        // A workspace that is not an InfoDot company (the local personal team, or one never linked) is never asked about.
        // Individuals hold the free core only: if InfoDot said at sign-in that the personal workspace does not hold this
        // platform, such a workspace is not a way round the check. True, or nothing remembered, carries on.
        if ($company === null || $company->personal_team || blank($company->infodot_team_uuid)) {
            if ($user instanceof User && $request->hasSession() && $request->session()->get(self::PERSONAL) === false) {
                return $this->refuse($request, 'needs_company', $user, $company);
            }

            return $next($request);
        }

        $answer = $this->entitlement->for((string) $company->infodot_team_uuid);

        if ($answer['state'] !== CompanyEntitlement::REFUSED) {
            return $next($request);
        }

        return $this->refuse($request, $answer['status'], $user, $company);
    }

    /**
     * A Livewire component request is stopped with an exception, not a returned page: Livewire discards a non-redirect
     * response from persistent middleware, answers 302 and has already run the action. aborting 403 stops it for real.
     */
    private function refuse(Request $request, ?string $status, ?User $user, ?Team $company): Response
    {
        if ($request->headers->has('X-Livewire')) {
            abort(403, EntitlementRefusal::wording($status)[0]);
        }

        return EntitlementRefusal::response($request, $status, $user, $company);
    }
}
