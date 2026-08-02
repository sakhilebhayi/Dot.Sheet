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
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=sora:400,500,600,700|fraunces:600,700,800" rel="stylesheet" />

        <!-- Vite -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])

        <!-- Livewire -->
        @livewireStyles

        <style>
            :root {
                --ink: #122226;
                --ink-soft: #355257;
                --accent: #1f9d74;
                --accent-strong: #0f7f5a;
                --card: rgba(255, 255, 255, 0.78);
                --line: rgba(18, 34, 38, 0.14);
                --shadow: 0 24px 70px rgba(13, 38, 46, 0.2);
                --bg-photo: url('https://images.unsplash.com/photo-1517248135467-4c7edcad34c4?auto=format&fit=crop&w=2400&q=80');
            }

            *, *::before, *::after { box-sizing: border-box; }

            body {
                margin: 0;
                min-height: 100vh;
                font-family: 'Sora', system-ui, sans-serif;
                color: var(--ink);
                background:
                    linear-gradient(rgba(12, 27, 31, 0.42), rgba(12, 27, 31, 0.42)),
                    var(--bg-photo) center/cover fixed no-repeat,
                    radial-gradient(900px 400px at 85% -10%, rgba(31, 157, 116, 0.24), transparent 70%),
                    radial-gradient(700px 500px at -10% 100%, rgba(234, 179, 8, 0.18), transparent 70%),
                    linear-gradient(160deg, #f8f5ef 0%, #eff6f2 45%, #f6f6f2 100%);
                background-blend-mode: multiply, normal, normal, normal, normal;
                overflow-x: hidden;
            }

            /* Grain texture overlay */
            body::before {
                content: '';
                position: fixed;
                inset: 0;
                pointer-events: none;
                opacity: 0.22;
                background-image: radial-gradient(rgba(20, 20, 20, 0.06) 0.55px, transparent 0.55px);
                background-size: 4px 4px;
                z-index: 0;
            }

            body::after {
                content: '';
                position: fixed;
                inset: 0;
                pointer-events: none;
                background:
                    radial-gradient(1200px 500px at 110% -15%, rgba(31, 157, 116, 0.34), transparent 65%),
                    radial-gradient(900px 520px at -20% 105%, rgba(234, 179, 8, 0.2), transparent 70%);
                z-index: 0;
            }

            /* ── Layout ── */
            .auth-wrap {
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 2.5rem 1rem;
                position: relative;
                z-index: 1;
            }

            .auth-inner {
                width: 100%;
                max-width: 448px;
                display: flex;
                flex-direction: column;
                gap: 1.5rem;
                animation: authLiftIn 700ms cubic-bezier(0.2, 0.85, 0.26, 1) both;
            }

            /* ── Logo ── */
            .auth-logo-wrap { text-align: center; }

            .auth-logo-link {
                display: inline-flex;
                align-items: center;
                gap: 0.6rem;
                text-decoration: none;
                color: var(--ink);
            }

            .auth-logo-dot {
                width: 38px;
                height: 38px;
                background: var(--accent);
                border-radius: 11px;
                display: flex;
                align-items: center;
                justify-content: center;
                color: #fff;
                font-weight: 700;
                font-size: 1.1rem;
                flex-shrink: 0;
                box-shadow: 0 6px 16px rgba(31, 157, 116, 0.38);
            }

            .auth-logo-name {
                font-family: 'Fraunces', serif;
                font-size: 1.4rem;
                font-weight: 700;
                letter-spacing: -0.01em;
            }

            /* ── Card ── */
            .auth-card {
                background: var(--card);
                border: 1px solid rgba(255, 255, 255, 0.65);
                box-shadow: var(--shadow);
                border-radius: 28px;
                padding: 2.4rem 2.2rem;
                position: relative;
                overflow: hidden;
            }

            .auth-card::after {
                content: '';
                position: absolute;
                width: 220px;
                height: 220px;
                border-radius: 999px;
                right: -75px;
                top: -95px;
                background: radial-gradient(circle, rgba(31, 157, 116, 0.18), transparent 68%);
                pointer-events: none;
            }

            /* ── Headings ── */
            .auth-heading {
                margin: 0 0 0.3rem;
                font-family: 'Fraunces', serif;
                font-size: 1.75rem;
                font-weight: 700;
                line-height: 1.1;
                letter-spacing: -0.01em;
            }

            .auth-sub {
                margin: 0 0 1.75rem;
                color: var(--ink-soft);
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
                background: rgba(255, 255, 255, 0.72);
                color: var(--ink);
                font-family: 'Sora', system-ui, sans-serif;
                font-size: 0.92rem;
                outline: none;
                transition: border-color 160ms ease, box-shadow 160ms ease;
                -webkit-appearance: none;
                appearance: none;
            }

            .ds-input:focus {
                border-color: var(--accent);
                box-shadow: 0 0 0 3px rgba(31, 157, 116, 0.15);
            }

            .ds-input::placeholder { color: rgba(53, 82, 87, 0.45); }

            .ds-checkbox-row {
                display: flex;
                align-items: center;
                gap: 0.55rem;
                margin-bottom: 1.4rem;
            }

            .ds-checkbox {
                width: 15px;
                height: 15px;
                accent-color: var(--accent);
                cursor: pointer;
                flex-shrink: 0;
            }

            .ds-checkbox-label {
                font-size: 0.84rem;
                color: var(--ink-soft);
                cursor: pointer;
            }

            /* ── Buttons ── */
            .ds-btn-primary {
                display: block;
                width: 100%;
                padding: 0.82rem 1.2rem;
                background: var(--ink);
                color: #f5fbf8;
                border: none;
                border-radius: 12px;
                font-family: 'Sora', system-ui, sans-serif;
                font-size: 0.93rem;
                font-weight: 600;
                cursor: pointer;
                text-align: center;
                text-decoration: none;
                transition: transform 180ms ease, box-shadow 180ms ease;
                box-shadow: 0 8px 20px rgba(18, 34, 38, 0.2);
                margin-top: 0.5rem;
            }

            .ds-btn-primary:hover {
                transform: translateY(-2px);
                box-shadow: 0 14px 28px rgba(18, 34, 38, 0.28);
            }

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
                color: var(--ink-soft);
            }

            .ds-link {
                color: var(--accent-strong);
                font-weight: 600;
                text-decoration: none;
            }

            .ds-link:hover { text-decoration: underline; }

            /* ── Alerts ── */
            .ds-error-box {
                background: rgba(239, 68, 68, 0.08);
                border: 1px solid rgba(239, 68, 68, 0.25);
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
                background: rgba(31, 157, 116, 0.1);
                border: 1px solid rgba(31, 157, 116, 0.28);
                border-radius: 12px;
                padding: 0.78rem 1rem;
                margin-bottom: 1.4rem;
                font-size: 0.87rem;
                color: var(--accent-strong);
                font-weight: 500;
            }

            /* ── Terms row ── */
            .ds-terms-row {
                display: flex;
                align-items: flex-start;
                gap: 0.6rem;
                margin-bottom: 1.1rem;
                font-size: 0.84rem;
                color: var(--ink-soft);
                line-height: 1.5;
            }

            .ds-terms-row a {
                color: var(--accent-strong);
                font-weight: 500;
                text-decoration: none;
            }
            .ds-terms-row a:hover { text-decoration: underline; }

            @keyframes authLiftIn {
                from { transform: translateY(18px) scale(0.99); opacity: 0; }
                to   { transform: translateY(0)    scale(1);    opacity: 1; }
            }

            @media (max-width: 520px) {
                .auth-card { padding: 1.8rem 1.4rem; border-radius: 22px; }
                .auth-heading { font-size: 1.45rem; }
            }
        </style>
    </head>
    <body>
        {{ $slot }}

        @livewireScripts
    </body>
</html>
