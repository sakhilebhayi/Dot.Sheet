<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'Dot.Sheet') }}</title>

        <!-- Favicon -->
        <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
        <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon-16x16.png') }}">
        <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Fraunces:wght@600;700;800&family=Sora:wght@400;500;600;700&family=Space+Mono:wght@400;700&display=swap" rel="stylesheet">

        <!-- Vite -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])

        <!-- Livewire -->
        @livewireStyles

        <style>
            :root {
                --ink: #12231f;
                --ink-soft: #1c332c;
                --gold: #e3ab1f;
                --gold-soft: #f1c657;
                --green: #2f9e46;
                --green-soft: #4fbb63;
                --paper: #faf7ec;
                --paper-soft: #f1ecdb;
                --slate: #4c5f58;
                --cream: #ece5cf;
                --line: rgba(18, 35, 31, 0.13);
                --line-dark: rgba(250, 247, 236, 0.16);
                --font-display: 'Fraunces', ui-serif, Georgia, serif;
                --font-body: 'Sora', system-ui, sans-serif;
                --font-mono: 'Space Mono', ui-monospace, monospace;
                --ease-out: cubic-bezier(0.23, 1, 0.32, 1);
            }

            *, *::before, *::after { box-sizing: border-box; }

            body {
                margin: 0;
                min-height: 100vh;
                font-family: var(--font-body);
                color: var(--ink);
                background: var(--paper);
            }

            /* ── Layout ── */
            .auth-wrap {
                position: relative;
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 2.5rem 1.25rem;
                overflow: hidden;
            }

            /* Same hero photo as welcome.blade.php (laptop computer on glass-top table, by Carlos
               Muza), with a light paper-toned scrim matching this platform's own light auth-card
               theme — the welcome hero's dark-ink scrim is a localized full-bleed treatment for
               that one photo section, not this platform's overall identity (the rest of the
               welcome page, and every auth page, is light --paper). */
            .auth-photo,
            .auth-scrim {
                position: absolute;
                inset: 0;
            }
            .auth-photo {
                background-image: url('https://images.unsplash.com/photo-1460925895917-afdab827c52f?q=80&w=2400&auto=format&fit=crop');
                background-size: cover;
                background-position: center;
            }
            .auth-scrim {
                background: radial-gradient(ellipse 68% 62% at 50% 40%, var(--paper) 0%, rgba(250,247,236,0.94) 45%, rgba(250,247,236,0.7) 74%, rgba(250,247,236,0.35) 100%);
            }
            .auth-inner { position: relative; z-index: 1; }

            .auth-inner {
                width: 100%;
                max-width: 448px;
                display: flex;
                flex-direction: column;
                gap: 1.5rem;
                animation: authLiftIn 500ms var(--ease-out) both;
            }

            /* ── Logo ── */
            .auth-logo-wrap { text-align: center; }

            /* ── Card ── */
            .auth-card {
                background: #fff;
                border: 1px solid var(--line);
                box-shadow: 0 20px 50px rgba(18, 35, 31, 0.1);
                border-radius: 20px;
                padding: 2.4rem 2.2rem;
            }

            /* ── Headings ── */
            .auth-heading {
                margin: 0 0 0.3rem;
                font-family: var(--font-display);
                font-size: 1.7rem;
                font-weight: 700;
                line-height: 1.15;
                letter-spacing: -0.01em;
                color: var(--ink);
            }

            .auth-sub {
                margin: 0 0 1.75rem;
                color: var(--slate);
                font-size: 0.9rem;
                line-height: 1.6;
            }

            /* ── Form elements ── */
            .ds-field { margin-bottom: 1.1rem; }

            .ds-label {
                display: block;
                font-size: 0.82rem;
                font-weight: 600;
                color: var(--ink);
                margin-bottom: 0.38rem;
            }

            .ds-input {
                width: 100%;
                padding: 0.68rem 0.9rem;
                border: 1px solid var(--line);
                border-radius: 10px;
                background: var(--paper);
                color: var(--ink);
                font-family: var(--font-body);
                font-size: 0.92rem;
                outline: none;
                transition: border-color 160ms ease, box-shadow 160ms ease;
                -webkit-appearance: none;
                appearance: none;
            }

            .ds-input:focus {
                border-color: var(--gold);
                box-shadow: 0 0 0 3px rgba(227, 171, 31, 0.22);
                /* The forms plugin's transparent focus outline (what Tailwind 3 left in place over `outline: none` above; it still shows in forced-colors mode). */
                outline: 2px solid transparent;
                outline-offset: 2px;
            }

            .ds-input::placeholder { color: rgba(76, 95, 88, 0.55); }

            .ds-checkbox-row {
                display: flex;
                align-items: center;
                gap: 0.55rem;
                margin-bottom: 1.4rem;
            }

            .ds-checkbox {
                width: 15px;
                height: 15px;
                accent-color: var(--gold);
                cursor: pointer;
                flex-shrink: 0;
            }

            .ds-checkbox-label {
                font-size: 0.84rem;
                color: var(--slate);
                cursor: pointer;
            }

            /* ── Buttons ── */
            .ds-btn-primary {
                display: block;
                width: 100%;
                padding: 0.82rem 1.2rem;
                background: var(--gold);
                color: #1c1204;
                border: none;
                border-radius: 12px;
                font-family: var(--font-display);
                font-size: 0.93rem;
                font-weight: 700;
                cursor: pointer;
                text-align: center;
                text-decoration: none;
                transition: background-color 160ms ease, transform 160ms var(--ease-out);
                margin-top: 0.5rem;
            }

            .ds-btn-primary:hover { background: var(--gold-soft); }
            .ds-btn-primary:active { transform: scale(0.98); }

            /* ── Footer row ── */
            .ds-footer {
                display: flex;
                align-items: center;
                justify-content: space-between;
                flex-wrap: wrap;
                gap: 0.5rem;
                margin-top: 1.5rem;
                padding-top: 1.35rem;
                border-top: 1px solid var(--line);
                font-size: 0.84rem;
                color: var(--slate);
            }

            .ds-link {
                color: var(--ink);
                font-weight: 600;
                text-decoration: underline;
                text-underline-offset: 2px;
            }

            .ds-link:hover { color: var(--green); }

            /* ── Alerts ── */
            .ds-error-box {
                background: rgba(220, 38, 38, 0.06);
                border: 1px solid rgba(220, 38, 38, 0.22);
                border-radius: 12px;
                padding: 0.9rem 1rem;
                margin-bottom: 1.4rem;
                font-size: 0.86rem;
            }

            .ds-error-title {
                font-weight: 600;
                color: #b91c1c;
                margin-bottom: 0.4rem;
            }

            .ds-error-box ul { margin: 0; padding-left: 1.1rem; color: #b91c1c; }
            .ds-error-box li { margin-top: 0.22rem; }

            .ds-status {
                background: rgba(47, 158, 70, 0.1);
                border: 1px solid rgba(47, 158, 70, 0.28);
                border-radius: 12px;
                padding: 0.78rem 1rem;
                margin-bottom: 1.4rem;
                font-size: 0.87rem;
                color: #1f7a34;
                font-weight: 500;
            }

            /* ── Terms row ── */
            .ds-terms-row {
                display: flex;
                align-items: flex-start;
                gap: 0.6rem;
                margin-bottom: 1.1rem;
                font-size: 0.84rem;
                color: var(--slate);
                line-height: 1.5;
            }

            .ds-terms-row a {
                color: var(--ink);
                font-weight: 500;
                text-decoration: underline;
            }
            .ds-terms-row a:hover { color: var(--green); }

            @keyframes authLiftIn {
                from { transform: translateY(14px); opacity: 0; }
                to   { transform: translateY(0);    opacity: 1; }
            }

            @media (prefers-reduced-motion: reduce) {
                .auth-inner { animation: none; }
            }

            @media (max-width: 520px) {
                .auth-card { padding: 1.8rem 1.4rem; border-radius: 18px; }
                .auth-heading { font-size: 1.45rem; }
            }
        </style>
    </head>
    <body>
        {{ $slot }}

        @livewireScripts
    </body>
</html>
