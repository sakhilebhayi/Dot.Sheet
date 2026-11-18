<?php

use App\Sso\SheetProvisioner;

/*
 * Dot.Sheet's own choices for the shared sign-in package (packages/dot-sso-client). Everything else, including
 * DOTSSO_ENABLED (default false) and the session guard (the package defaults it to `web`), comes from the package's
 * defaults and the host .env.
 */
return [
    'provisioner' => SheetProvisioner::class,

    // Sheet has always created an account for a person InfoDot vouches for (INFODOT_PROVISION_USERS), so that stays.
    'auto_provision' => (bool) env('DOTSSO_AUTO_PROVISION', env('INFODOT_PROVISION_USERS', true)),

    'landing' => '/dashboard',

    // A deep link may open any path of the app (same-origin only, checked by the package). Every Sheet page is a
    // signed-in page, and spreadsheet and team invitation links must survive the sign-in, so the allow-list is every
    // path; the deny-list keeps the auth pages out, so no loop is possible.
    'deep_link' => [
        'allow' => ['#^/#'],
        'deny' => ['#^/(sso|login|register|logout|forgot-password|reset-password|auth|oauth|two-factor-challenge|user/confirm)(/|\?|$)#i'],
    ],

    // Never intercepted: nothing extra. Sheet's machine and outside-party doors (Sanctum, the public share link
    // /shared/s/{token}, /up, /ready, /guardian, /csp-report) are outside the pages the package takes over.
    'allow_paths' => [],
];
