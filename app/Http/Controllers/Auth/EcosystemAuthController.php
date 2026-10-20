<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class EcosystemAuthController extends Controller
{
    /**
     * Sign-in from InfoDot. The token arrives in the POST body of a form InfoDot's launch page posts here, so it
     * is never in a URL (address bar, history, access logs, Referer). A refused sign-in lands on the plain login
     * page: the credential is not shown, echoed, flashed or logged. A GET with ?token= is still understood for
     * links minted before this change, and is spent exactly the same way.
     */
    public function handle(Request $request): RedirectResponse
    {
        if ($request->isMethod('post') && ! $this->postedFromInfoDot($request)) {
            return $this->refused();
        }

        try {
            return app()->call([$this, 'redeem'], ['request' => $request]);
        } catch (HttpExceptionInterface $e) {
            return $this->refused($e->getStatusCode() === 503);
        }
    }

    /**
     * Login CSRF: a form on another site could sign a visitor in as someone else (the attacker's own valid
     * token). A browser names the page that posted a cross-site form in Origin, so only InfoDot's launch page,
     * on this app's parent domain (tasks.infodot.co.za -> infodot.co.za), is accepted.
     */
    private function postedFromInfoDot(Request $request): bool
    {
        $origin = (string) $request->headers->get('Origin', '');
        $host = $origin !== '' && $origin !== 'null'
            ? parse_url($origin, PHP_URL_HOST)
            : parse_url((string) $request->headers->get('Referer', ''), PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return in_array($request->headers->get('Sec-Fetch-Site'), ['same-origin', 'same-site'], true);
        }

        $host = strtolower($host);
        $self = strtolower($request->getHost());
        $parent = str_contains($self, '.') ? substr($self, strpos($self, '.') + 1) : $self;

        return $host === $self || $host === $parent || str_ends_with($host, '.'.$parent);
    }

    private function refused(bool $unavailable = false): RedirectResponse
    {
        return redirect('/login', 303)->with('status', $unavailable
            ? 'Sign-in through InfoDot is unavailable right now. Please try again in a moment.'
            : 'We could not sign you in through InfoDot. Please open this app again from InfoDot.');
    }

    public function redeem(Request $request): RedirectResponse
    {
        $accessToken = PersonalAccessToken::findToken($request->input('token'));

        abort_if(
            ! $accessToken
            || ! $accessToken->can('ecosystem:read')
            || ($accessToken->expires_at && $accessToken->expires_at->isPast()),
            403
        );

        /** @var User $user */
        $user = $accessToken->tokenable;
        $accessToken->delete();

        Auth::login($user);

        return redirect()->route('dashboard');
    }
}
