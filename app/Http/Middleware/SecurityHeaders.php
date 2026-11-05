<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Baseline browser protections on every response. A header the route already set is left alone, so a stricter route-level
 * value (for example a download that sets its own X-Frame-Options) always wins.
 *
 * X-Content-Type-Options, X-Frame-Options, Referrer-Policy, Permissions-Policy and (HTTPS only) Strict-Transport-Security.
 * HSTS is max-age only, with no includeSubDomains and no preload: this domain has many sibling subdomains not all ready for it.
 *
 * The Content-Security-Policy is ENFORCED and nonce-based, the shape InfoDot, Dot.HR and Dot.Projects run live. A nonce is
 * registered with Vite before the views render, so @vite tags and Livewire's assets carry it. 'unsafe-eval' stays as a stack
 * requirement: Livewire's bundled Alpine evaluates its expressions with the Function constructor. style-src keeps
 * 'unsafe-inline' because the views use style attributes.
 *
 * The classic editor alone stays report-only: it uses on*= handlers throughout. SECURITY_CSP_REPORT_ONLY=true is the
 * kill-switch back to report-only everywhere without a deploy.
 */
class SecurityHeaders
{
    private const REPORT_ONLY_ROUTES = ['spreadsheets.classic'];

    public function handle(Request $request, Closure $next): Response
    {
        // Registered before the views render, so every tag that needs it carries the value the header announces.
        $nonce = Vite::useCspNonce();

        $response = $next($request);

        $reportOnly = config('security.csp_report_only', false) || $request->routeIs(...self::REPORT_ONLY_ROUTES);

        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'SAMEORIGIN',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=(), usb=()',
            ($reportOnly ? 'Content-Security-Policy-Report-Only' : 'Content-Security-Policy') => $this->policy($nonce, $reportOnly),
        ];

        $hstsSeconds = (int) config('security.hsts_seconds', 0);

        if ($request->isSecure() && $hstsSeconds > 0) {
            $headers['Strict-Transport-Security'] = 'max-age='.$hstsSeconds;
        }

        foreach ($headers as $name => $value) {
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        return $response;
    }

    /**
     * Outside origins in use: Google Fonts (stylesheet host and font host), Unsplash (landing and sign-in photographs) and ui-avatars.com,
     * which Jetstream's HasProfilePhoto uses for a user's default avatar. Charts are bundled by Vite, so no script CDN is needed.
     */
    private function policy(string $nonce, bool $reportOnly): string
    {
        // Local development loads assets from the Vite server (http: and its ws: hot reload).
        $dev = app()->isLocal() ? ' http://localhost:* http://127.0.0.1:*' : '';

        return implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'nonce-{$nonce}' 'unsafe-eval'{$dev}",
            // The report-only page is the classic editor, which is why it reports rather than blocks.
            $reportOnly ? "script-src-attr 'unsafe-inline'" : "script-src-attr 'none'",
            "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com{$dev}",
            "font-src 'self' data: https://fonts.gstatic.com",
            "img-src 'self' data: blob: https://images.unsplash.com https://ui-avatars.com",
            "media-src 'self' blob:",
            // Local development talks to Reverb over plaintext ws:; production websockets are wss: only.
            "connect-src 'self' wss: https:".(app()->isLocal() ? ' ws: http:' : ''),
            "frame-src 'self'",
            "frame-ancestors 'self'",
            "object-src 'none'",
            "base-uri 'self'",
            // Sign-in hands over to InfoDot over https; every other form posts here.
            "form-action 'self' https:",
            'report-uri /csp-report',
        ]);
    }
}
