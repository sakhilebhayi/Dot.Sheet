<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name', 'Dot.Sheet') }}</title>
    <!-- Favicon -->
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
    <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon-16x16.png') }}">
    <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=sora:400,500,600,700|fraunces:600,700,800" rel="stylesheet" />
    <style>
        :root {
            --ink: #122226;
            --ink-soft: #355257;
            --paper: #f7f4eb;
            --accent: #1f9d74;
            --accent-strong: #0f7f5a;
            --card: rgba(255, 255, 255, 0.78);
            --line: rgba(18, 34, 38, 0.14);
            --shadow: 0 24px 70px rgba(13, 38, 46, 0.2);
            /* Photographic Background: real charts/analytics-on-laptop-screen photo by Carlos Muza (@kmuza), unsplash.com/photos/laptop-computer-on-glass-top-table-hpjSkU2UYSU */
            --bg-photo: url('https://images.unsplash.com/photo-1460925895917-afdab827c52f?auto=format&fit=crop&w=2400&q=80');
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            min-height: 100vh;
            font-family: 'Sora', system-ui, sans-serif;
            color: var(--ink);
            background:
                linear-gradient(rgba(12, 27, 31, 0.44), rgba(12, 27, 31, 0.44)),
                var(--bg-photo) center/cover fixed no-repeat,
                radial-gradient(900px 400px at 85% -10%, rgba(31, 157, 116, 0.24), transparent 70%),
                radial-gradient(700px 500px at -10% 100%, rgba(234, 179, 8, 0.18), transparent 70%),
                linear-gradient(160deg, #f8f5ef 0%, #eff6f2 45%, #f6f6f2 100%);
            background-blend-mode: multiply, normal, normal, normal, normal;
            overflow-x: hidden;
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

        .grain {
            position: fixed;
            inset: 0;
            pointer-events: none;
            opacity: 0.22;
            background-image: radial-gradient(rgba(20, 20, 20, 0.06) 0.55px, transparent 0.55px);
            background-size: 4px 4px;
        }

        .page {
            width: min(1160px, calc(100% - 2.5rem));
            margin: 1.25rem auto 2.8rem;
            position: relative;
            z-index: 1;
        }

        .topbar {
            display: flex;
            justify-content: flex-end;
            align-items: center;
            gap: 0.75rem;
            margin-bottom: 1.1rem;
        }

        .topbar a {
            text-decoration: none;
            color: var(--ink);
            border: 1px solid var(--line);
            padding: 0.58rem 1rem;
            border-radius: 999px;
            font-size: 0.86rem;
            font-weight: 600;
            transition: transform 180ms ease, background-color 180ms ease, border-color 180ms ease;
            backdrop-filter: blur(8px);
            background: rgba(255, 255, 255, 0.55);
        }

        .topbar a:hover {
            transform: translateY(-1px);
            border-color: rgba(18, 34, 38, 0.28);
            background: rgba(255, 255, 255, 0.85);
        }

        .topbar .primary {
            background: var(--accent);
            color: #f6fffb;
            border-color: var(--accent);
        }

        .topbar .primary:hover {
            background: var(--accent-strong);
            border-color: var(--accent-strong);
        }

        .hero {
            background: var(--card);
            border: 1px solid rgba(255, 255, 255, 0.6);
            box-shadow: var(--shadow);
            border-radius: 30px;
            padding: 3.2rem 3.2rem 2.4rem;
            display: grid;
            gap: 2.4rem;
            grid-template-columns: 1.05fr 0.95fr;
            position: relative;
            overflow: hidden;
            animation: liftIn 820ms cubic-bezier(0.2, 0.85, 0.26, 1) both;
            backdrop-filter: blur(5px);
        }

        .hero::after {
            content: '';
            position: absolute;
            width: 340px;
            height: 340px;
            border-radius: 999px;
            right: -130px;
            top: -140px;
            background: radial-gradient(circle, rgba(31, 157, 116, 0.22), transparent 68%);
        }

        .kicker {
            display: inline-flex;
            align-items: center;
            gap: 0.45rem;
            font-size: 0.78rem;
            letter-spacing: 0.06em;
            text-transform: uppercase;
            color: var(--ink-soft);
            background: rgba(255, 255, 255, 0.65);
            border: 1px solid var(--line);
            border-radius: 999px;
            padding: 0.35rem 0.72rem;
            margin-bottom: 1rem;
        }

        h1 {
            margin: 0;
            font-family: 'Fraunces', serif;
            font-size: clamp(2rem, 3.6vw, 3.75rem);
            line-height: 1.06;
            letter-spacing: -0.01em;
            max-width: 15ch;
        }

        .subtitle {
            margin: 1rem 0 0;
            color: var(--ink-soft);
            font-size: 1.04rem;
            line-height: 1.7;
            max-width: 52ch;
        }

        .hero-ribbon {
            margin-top: 1.2rem;
            display: inline-flex;
            gap: 0.7rem;
            align-items: center;
            background: rgba(18, 34, 38, 0.08);
            border: 1px solid var(--line);
            border-radius: 12px;
            padding: 0.48rem 0.72rem;
            font-size: 0.78rem;
            color: var(--ink-soft);
        }

        .hero-ribbon strong {
            color: var(--ink);
        }

        .cta-row {
            display: flex;
            flex-wrap: wrap;
            gap: 0.8rem;
            margin-top: 1.75rem;
        }

        .btn {
            text-decoration: none;
            border-radius: 12px;
            padding: 0.82rem 1.15rem;
            font-weight: 600;
            font-size: 0.92rem;
            transition: transform 180ms ease, box-shadow 180ms ease, background-color 180ms ease;
        }

        .btn-main {
            background: var(--ink);
            color: #f5fbf8;
            box-shadow: 0 14px 24px rgba(18, 34, 38, 0.22);
        }

        .btn-main:hover {
            transform: translateY(-2px);
            box-shadow: 0 18px 30px rgba(18, 34, 38, 0.28);
        }

        .btn-muted {
            color: var(--ink);
            border: 1px solid var(--line);
            background: rgba(255, 255, 255, 0.62);
        }

        .btn-muted:hover {
            transform: translateY(-2px);
            background: rgba(255, 255, 255, 0.9);
        }

        .feature-grid {
            margin-top: 2.2rem;
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 0.68rem;
        }

        .feature {
            border: 1px solid var(--line);
            border-radius: 14px;
            padding: 0.78rem;
            font-size: 0.86rem;
            color: var(--ink-soft);
            background: rgba(255, 255, 255, 0.55);
        }

        .feature strong {
            display: block;
            color: var(--ink);
            margin-bottom: 0.35rem;
            font-size: 0.92rem;
        }

        .hero-trust {
            margin-top: 1.35rem;
            display: flex;
            flex-wrap: wrap;
            gap: 0.6rem;
        }

        .hero-trust span {
            border: 1px solid var(--line);
            background: rgba(255, 255, 255, 0.56);
            border-radius: 999px;
            font-size: 0.74rem;
            color: var(--ink-soft);
            padding: 0.33rem 0.66rem;
        }

        .panel {
            border: 1px solid var(--line);
            border-radius: 24px;
            background: rgba(255, 255, 255, 0.68);
            padding: 1.4rem;
            backdrop-filter: blur(10px);
        }

        .logo-wrap {
            border-radius: 18px;
            border: 1px solid var(--line);
            background: linear-gradient(145deg, #ffffff 5%, #edf8f2 100%);
            min-height: 250px;
            display: grid;
            place-items: center;
            padding: 1.25rem;
            animation: softFloat 3.2s ease-in-out infinite;
        }

        .logo-wrap img {
            width: min(260px, 82%);
            filter: drop-shadow(0 12px 24px rgba(17, 40, 42, 0.28));
        }

        .metrics {
            margin-top: 1rem;
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 0.68rem;
        }

        .metric {
            border: 1px solid var(--line);
            border-radius: 12px;
            background: rgba(255, 255, 255, 0.62);
            padding: 0.72rem;
        }

        .metric .value {
            font-family: 'Fraunces', serif;
            font-size: 1.26rem;
            line-height: 1;
        }

        .metric .label {
            margin-top: 0.3rem;
            font-size: 0.76rem;
            color: var(--ink-soft);
        }

        .footer {
            margin-top: 1rem;
            font-size: 0.82rem;
            color: rgba(18, 34, 38, 0.75);
        }

        .ambient-stats {
            margin-top: 0.95rem;
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 0.68rem;
        }

        .ambient-stats article {
            border: 1px solid var(--line);
            border-radius: 12px;
            padding: 0.6rem 0.68rem;
            background: rgba(255, 255, 255, 0.56);
        }

        .ambient-stats .stat-value {
            font-family: 'Fraunces', serif;
            font-size: 1.12rem;
            line-height: 1;
            color: var(--ink);
        }

        .ambient-stats .stat-label {
            margin-top: 0.28rem;
            font-size: 0.72rem;
            color: var(--ink-soft);
        }

        @keyframes liftIn {
            from { transform: translateY(18px) scale(0.99); opacity: 0; }
            to { transform: translateY(0) scale(1); opacity: 1; }
        }

        @keyframes softFloat {
            0%, 100% { transform: translateY(0px); }
            50% { transform: translateY(-5px); }
        }

        @media (max-width: 960px) {
            .hero {
                grid-template-columns: 1fr;
                padding: 2rem;
            }

            .feature-grid,
            .metrics {
                grid-template-columns: 1fr 1fr;
            }

            .ambient-stats {
                grid-template-columns: 1fr 1fr;
            }
        }

        @media (max-width: 640px) {
            .page { width: calc(100% - 1.4rem); }
            .hero { border-radius: 22px; padding: 1.35rem; gap: 1.4rem; }
            .topbar { flex-wrap: wrap; justify-content: center; }
            h1 { font-size: clamp(1.7rem, 9vw, 2.25rem); }
            .feature-grid,
            .metrics {
                grid-template-columns: 1fr;
            }

            .ambient-stats {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
<div class="grain" aria-hidden="true"></div>

<div class="page">
    @if (Route::has('login'))
        <nav class="topbar">
            @auth
                <a href="{{ url('/dashboard') }}" class="primary">Go to Dashboard</a>
            @else
                <a href="{{ route('login') }}">Log in</a>
                @if (Route::has('register'))
                    <a href="{{ route('register') }}" class="primary">Start free</a>
                @endif
            @endauth
        </nav>
    @endif

    <main class="hero">
        <section>
            <span class="kicker">AI-Powered Spreadsheets</span>
            <h1>Make collaborative analysis feel effortless.</h1>
            <p class="subtitle">
                Dot.Sheet brings formulas, automation, and team collaboration into one intelligent workspace,
                so your data work feels less like maintenance and more like momentum.
            </p>

            <div class="hero-ribbon">
                <strong>New:</strong>
                Template packs, chart snapshots, and AI-powered workflow triggers now in one command surface.
            </div>

            <div class="cta-row">
                @auth
                    <a href="{{ url('/dashboard') }}" class="btn btn-main">Open your workspace</a>
                @else
                    <a href="{{ route('login') }}" class="btn btn-main">Sign in to Dot.Sheet</a>
                    @if (Route::has('register'))
                        <a href="{{ route('register') }}" class="btn btn-muted">Create account</a>
                    @endif
                @endauth
            </div>

            <div class="feature-grid">
                <article class="feature">
                    <strong>Realtime teams</strong>
                    Presence, comments, and shared cursors in one canvas.
                </article>
                <article class="feature">
                    <strong>AI formulas</strong>
                    Generate and explain formulas directly from prompts.
                </article>
                <article class="feature">
                    <strong>Fast at scale</strong>
                    Virtualized grid and queue-backed operations.
                </article>
            </div>

            <div class="hero-trust">
                <span>Import CSV / XLSX</span>
                <span>Version history</span>
                <span>Role-based sharing</span>
                <span>AI insights panel</span>
            </div>
        </section>

        <aside class="panel">
            <div class="logo-wrap">
                <img src="{{ asset('dot_sheet.png') }}" alt="Dot.Sheet logo">
            </div>
            <div class="metrics">
                <div class="metric">
                    <div class="value">1M+</div>
                    <div class="label">Cell-ready rendering</div>
                </div>
                <div class="metric">
                    <div class="value">Live</div>
                    <div class="label">Collaboration updates</div>
                </div>
                <div class="metric">
                    <div class="value">AI</div>
                    <div class="label">Formula assistant</div>
                </div>
            </div>
            <p class="footer">v{{ app()->version() }} • Built with Laravel + Livewire</p>

            <div class="ambient-stats">
                <article>
                    <div class="stat-value">99.9%</div>
                    <div class="stat-label">Uptime target</div>
                </article>
                <article>
                    <div class="stat-value">12ms</div>
                    <div class="stat-label">Hot-cell response</div>
                </article>
                <article>
                    <div class="stat-value">50+</div>
                    <div class="stat-label">Concurrent editors</div>
                </article>
                <article>
                    <div class="stat-value">∞</div>
                    <div class="stat-label">Ideas per sheet</div>
                </article>
            </div>
        </aside>
    </main>
</div>
</body>
</html>
