<?php

namespace App\Support;

use App\Models\Team;
use App\Models\User;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The plain page a person sees when their company does not hold Dot.Sheet (R-50): at sign-in when InfoDot lists no
 * company that holds it, and during a session when the current company stops holding it. It shows no workbook, no
 * company data and nothing from another company; the one action is InfoDot's page for this platform.
 */
class EntitlementRefusal
{
    public static function response(Request $request, ?string $status = null, ?User $user = null, ?Team $company = null): Response
    {
        [$title, $message] = self::wording($status);

        if ($request->expectsJson()) {
            return response()->json(['message' => $title.'. '.$message, 'reason' => 'platform_not_entitled', 'platforms_url' => self::platformsUrl()], 403);
        }

        return response()->view('errors.entitlement', [
            'title' => $title,
            'message' => $message,
            'platformsUrl' => self::platformsUrl(),
            'company' => $company,
            // Only the person's own other companies, by name, so they can move to one that holds the platform.
            'others' => $user && $company ? $user->allTeams()->reject(fn ($team) => $team->id === $company->id || $team->personal_team || blank($team->infodot_team_uuid))->values() : collect(),
        ], 403);
    }

    public static function platformsUrl(): string
    {
        return rtrim((string) config('ecosystem.platforms.infodot.url', 'https://infodot.co.za'), '/').'/platforms/'.config('ecosystem.infodot.platform', 'sheet');
    }

    /**
     * Worded from the state InfoDot gives for the platform (see its PlatformAccess::businessState) or, at sign-in,
     * the reason a company was refused. Anything else reads as "not switched on".
     *
     * @return array{0: string, 1: string}
     */
    public static function wording(?string $status): array
    {
        return match ($status) {
            'suspended' => ['Dot.Sheet is suspended for your company', 'Access has been paused. Your workbooks are kept as they are and nothing has been removed. A company owner or administrator can see what is needed in InfoDot.'],
            'payment_required' => ['Dot.Sheet needs a payment for your company', 'Your workbooks are kept as they are and nothing has been removed. Once a company owner or administrator settles the payment in InfoDot, everything opens again.'],
            'subscription_expired', 'expired', 'trial_ended', 'cancelled' => ['Dot.Sheet has ended for your company', 'Your company\'s time on Dot.Sheet has run out. Your workbooks are kept as they are and nothing has been removed. A company owner or administrator can switch it on again in InfoDot.'],
            'activation_pending' => ['Dot.Sheet is not ready for your company yet', 'It has been asked for and is waiting to be switched on. You can follow it in InfoDot.'],
            'needs_company' => ['Dot.Sheet needs a company that has switched it on', 'A personal workspace does not include Dot.Sheet. Your work there is kept as it is and nothing has been removed. Open Dot.Sheet from a company that has switched it on, or ask a company owner or administrator to switch it on in InfoDot.'],
            'company_required', 'no_personal_access' => ['Dot.Sheet needs a company', 'A personal workspace does not include Dot.Sheet. Open it from a company in InfoDot, or switch it on for one there.'],
            'not_permitted_for_you' => ['Dot.Sheet is not switched on for you', 'Your company chooses who uses Dot.Sheet, and you are not on that list yet. A company owner or administrator can add you in InfoDot.'],
            'temporarily_unavailable', 'not_launched' => ['Dot.Sheet is not available right now', 'Nothing is wrong with your account or your workbooks. InfoDot shows when it is open again.'],
            default => ['Dot.Sheet is not switched on for your company', 'A company owner or administrator can switch it on in InfoDot. If your company used Dot.Sheet before, its workbooks are kept as they are.'],
        };
    }
}
