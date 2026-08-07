<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="scroll-smooth">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Dot.Sheet — A spreadsheet grid built for many hands at once</title>
        <meta name="description" content="Formulas that run client and server side, comments and version history on every cell, and a grid that stays in sync while more than one person edits it. Dot.Sheet is the collaborative spreadsheet in the Dot ecosystem.">

        <!-- Favicon -->
        <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
        <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon-16x16.png') }}">
        <link rel="apple-touch-icon" sizes="180x180" href="{{ asset('apple-touch-icon.png') }}">

        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Fraunces:wght@600;700;800&family=Sora:wght@400;500;600;700&family=Space+Mono:wght@400;700&display=swap" rel="stylesheet">

        @vite(['resources/css/app.css', 'resources/js/app.js'])

        <!-- Livewire (bundles and starts Alpine.js, used by the nav below) -->
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
            html { background: var(--paper); }
            body { font-family: var(--font-body); background: var(--paper); color: var(--ink); }
            .font-display { font-family: var(--font-display); }
            .font-mono { font-family: var(--font-mono); }

            .press { transition: transform 160ms var(--ease-out); }
            .press:active { transform: scale(0.97); }

            @media (prefers-reduced-motion: no-preference) {
                .reveal {
                    opacity: 0;
                    transform: translateY(14px);
                    transition: opacity 600ms var(--ease-out), transform 600ms var(--ease-out);
                }
                .reveal.is-visible { opacity: 1; transform: translateY(0); }
            }
            @media (prefers-reduced-motion: reduce) {
                .reveal { opacity: 1; transform: none; }
            }

            @media (hover: hover) and (pointer: fine) {
                .row-hover:hover { background: rgba(18, 35, 31, 0.035); }
                .link-underline { background-size: 0% 1px; }
                .link-underline:hover { background-size: 100% 1px; }
            }
            .link-underline {
                background-image: linear-gradient(currentColor, currentColor);
                background-position: 0 100%;
                background-repeat: no-repeat;
                transition: background-size 220ms var(--ease-out);
            }

            .nav-top a, .nav-top button { color: var(--paper); }
            .nav-top .nav-cream { color: rgba(250, 247, 236, 0.82); }
            .nav-scrolled a, .nav-scrolled button { color: var(--ink); }
            .nav-scrolled .nav-cream { color: var(--slate); }
        </style>
    </head>
    <body class="antialiased">

        <!-- Nav -->
        <header
            x-data="{ scrolled: false, mobileMenuOpen: false }"
            @scroll.window="scrolled = window.pageYOffset > 24"
            :class="scrolled ? 'bg-[var(--paper)]/95 backdrop-blur-md border-b border-[var(--line)] nav-scrolled' : 'border-b border-transparent nav-top'"
            class="fixed top-0 left-0 right-0 z-50 transition-colors duration-300"
        >
            <nav class="max-w-[1400px] mx-auto px-5 sm:px-8 py-3 flex items-center justify-between">
                <a href="/" class="flex items-center gap-2.5 press">
                    <img src="{{ asset('dot_sheet.png') }}" alt="Dot.Sheet" class="h-16 sm:h-20 w-auto">
                </a>

                <div class="hidden md:flex items-center gap-8 font-mono text-[13px] tracking-wide uppercase nav-cream">
                    <a href="#capabilities" class="link-underline pb-0.5">Product</a>
                    <a href="#features" class="link-underline pb-0.5">Features</a>
                </div>

                @if (Route::has('login'))
                    <div class="flex items-center gap-3">
                        @auth
                            <a href="{{ url('/dashboard') }}" class="press flex items-center gap-2 px-5 py-2.5 bg-[var(--gold)] hover:bg-[var(--gold-soft)] text-[#1c1204] text-sm font-display font-semibold rounded-lg transition-colors">
                                Dashboard
                            </a>
                        @else
                            <a href="{{ route('login') }}" class="hidden sm:block text-sm font-medium nav-cream transition-colors">
                                Sign in
                            </a>
                            @if (Route::has('register'))
                                <a href="{{ route('register') }}" class="press px-5 py-2.5 bg-[var(--gold)] hover:bg-[var(--gold-soft)] text-[#1c1204] text-sm font-display font-semibold rounded-lg transition-colors">
                                    Create account
                                </a>
                            @endif
                        @endauth

                        <button @click="mobileMenuOpen = !mobileMenuOpen" class="md:hidden press p-2 -mr-2" aria-label="Toggle menu">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path x-show="!mobileMenuOpen" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M4 7h16M4 12h16M4 17h16"></path>
                                <path x-show="mobileMenuOpen" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M6 18L18 6M6 6l12 12"></path>
                            </svg>
                        </button>
                    </div>
                @endif
            </nav>

            <div x-show="mobileMenuOpen"
                 x-transition:enter="transition ease-out duration-150"
                 x-transition:enter-start="opacity-0"
                 x-transition:enter-end="opacity-100"
                 x-transition:leave="transition ease-in duration-100"
                 x-transition:leave-start="opacity-100"
                 x-transition:leave-end="opacity-0"
                 class="md:hidden border-t border-[var(--line)] bg-[var(--paper)]"
                 style="display: none;">
                <div class="flex flex-col px-5 py-4 gap-1 font-mono text-sm uppercase tracking-wide">
                    <a href="#capabilities" class="px-3 py-2.5 text-[var(--slate)] hover:text-[var(--ink)]">Product</a>
                    <a href="#features" class="px-3 py-2.5 text-[var(--slate)] hover:text-[var(--ink)]">Features</a>
                    @guest
                        <a href="{{ route('login') }}" class="px-3 py-2.5 text-[var(--slate)] hover:text-[var(--ink)]">Sign in</a>
                    @endguest
                </div>
            </div>
        </header>

        <!-- Hero -->
        <section class="relative min-h-[100dvh] flex items-end overflow-hidden">
            <!-- Photo: laptop computer on glass-top table, by Carlos Muza (@kmuza), unsplash.com/photos/hpjSkU2UYSU -->
            <div class="absolute inset-0 bg-cover bg-center" style="background-image: url('https://images.unsplash.com/photo-1460925895917-afdab827c52f?q=80&w=2400&auto=format&fit=crop');"></div>
            <div class="absolute inset-0" style="background: linear-gradient(180deg, rgba(18,35,31,0.58) 0%, rgba(18,35,31,0.76) 45%, #12231f 92%);"></div>
            <div class="absolute inset-0" style="background: linear-gradient(90deg, #12231f 0%, rgba(18,35,31,0.55) 38%, transparent 68%);"></div>

            <!-- Signature element — line-art grid + active cell + calculator mark + arrow, echoing the real logo's circle/math-symbols and green chevron -->
            <svg class="hidden lg:block absolute right-[6%] bottom-[8%] h-[68%] w-auto opacity-[0.16] pointer-events-none" viewBox="0 0 220 320" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                <path d="M20 20H190M20 90H190M20 160H190M20 230H190M20 300H190" stroke="#faf7ec" stroke-width="1.5"/>
                <path d="M20 20V300M75 20V300M130 20V300M190 20V300" stroke="#faf7ec" stroke-width="1.5"/>
                <rect x="75" y="90" width="55" height="70" stroke="#faf7ec" stroke-width="3"/>
                <rect x="72" y="87" width="6" height="6" fill="#faf7ec"/>
                <rect x="127" y="87" width="6" height="6" fill="#faf7ec"/>
                <rect x="72" y="157" width="6" height="6" fill="#faf7ec"/>
                <rect x="127" y="157" width="6" height="6" fill="#faf7ec"/>
                <circle cx="190" cy="20" r="16" stroke="#faf7ec" stroke-width="3"/>
                <path d="M184 20H196M190 14V26" stroke="#faf7ec" stroke-width="2"/>
                <path d="M20 245L38 262L20 279" stroke="#faf7ec" stroke-width="3"/>
            </svg>

            <div class="relative z-10 max-w-[1400px] mx-auto px-5 sm:px-8 pt-32 pb-16 sm:pb-20 w-full">
                <div class="max-w-2xl reveal" data-reveal>
                    <p class="font-mono text-xs tracking-[0.18em] uppercase text-[var(--gold-soft)] mb-6">
                        Collaborative spreadsheets
                    </p>

                    <h1 class="font-display font-bold text-4xl sm:text-5xl lg:text-6xl leading-[1.05] tracking-tight text-[var(--paper)] mb-6">
                        Row by row.<br>Together, in real time.
                    </h1>

                    <p class="text-lg text-[rgba(250,247,236,0.82)] leading-relaxed max-w-xl mb-10">
                        Dot.Sheet is a real grid of rows and columns — formulas, comments, charts, and version history — built to hold more than one editor in the same sheet without waiting for a full reload.
                    </p>

                    @guest
                        <div class="flex flex-wrap items-center gap-4">
                            <a href="{{ route('register') }}" class="press px-7 py-3.5 bg-[var(--gold)] hover:bg-[var(--gold-soft)] text-[#1c1204] font-display font-semibold rounded-lg transition-colors">
                                Create account
                            </a>
                            <a href="#features" class="press flex items-center gap-2 px-7 py-3.5 text-[var(--paper)] font-medium rounded-lg border border-[var(--line-dark)] hover:border-[rgba(250,247,236,0.4)] transition-colors">
                                See what's on the grid
                            </a>
                        </div>
                    @endguest
                </div>
            </div>

            <!-- Live data strip — instrumentation-styled, not a fabricated metric -->
            <div class="relative z-10 w-full border-t border-[var(--line-dark)] bg-[#12231f]/60 backdrop-blur-sm">
                <div class="max-w-[1400px] mx-auto px-5 sm:px-8 py-4 flex flex-wrap gap-x-8 gap-y-2 font-mono text-[11px] tracking-[0.14em] uppercase text-[rgba(250,247,236,0.75)]">
                    <span>Sparse cell storage</span>
                    <span class="text-[var(--gold-soft)]">·</span>
                    <span>Client + server formulas</span>
                    <span class="text-[var(--gold-soft)]">·</span>
                    <span>Realtime presence</span>
                    <span class="text-[var(--gold-soft)]">·</span>
                    <span>Version history</span>
                </div>
            </div>
        </section>

        <!-- Features -->
        <section id="features" class="py-24 sm:py-28 px-5 sm:px-8 bg-[var(--paper)]">
            <div class="max-w-[1400px] mx-auto">
                <div class="max-w-xl mb-16 reveal" data-reveal>
                    <p class="font-mono text-xs tracking-[0.18em] uppercase text-[var(--green)] mb-4">What it does</p>
                    <h2 class="font-display font-semibold text-3xl sm:text-4xl text-[var(--ink)] leading-tight">
                        Everything a sheet needs, none of it bolted on after
                    </h2>
                </div>

                <div class="grid md:grid-cols-2 border-t border-[var(--line)]">
                    @php
                        $features = [
                            ['tag' => 'Grid', 'title' => 'A grid that loads what you see', 'body' => 'Cells are stored sparsely — only the ones with something in them — and the viewport loads with prefetch instead of pulling the whole sheet at once.'],
                            ['tag' => 'Formulas', 'title' => 'Formulas run twice, on purpose', 'body' => 'A client-side parser evaluates as you type; the same formula is re-checked by a server-side PHP evaluator, so every collaborator sees the same result.'],
                            ['tag' => 'Realtime', 'title' => 'See who\'s editing, live', 'body' => 'Cell edits and cursor moves broadcast over a presence channel, gated by the same permission check as every other action on the sheet.'],
                            ['tag' => 'History', 'title' => 'Comments and version history', 'body' => 'Threaded, cell-anchored comments with @mention notifications, plus full-grid snapshots you can roll back to.'],
                            ['tag' => 'Import / export', 'title' => 'CSV and XLSX, both ways', 'body' => 'Large imports and pastes are chunked into queued jobs, so one big file doesn\'t lock up the grid for everyone else in it.'],
                            ['tag' => 'AI', 'title' => 'AI panels, your provider', 'body' => 'Formula generation, natural-language queries, data cleaning, OCR, and sentiment analysis — running on whichever provider you configure, not one fixed model.'],
                        ];
                    @endphp
                    @foreach ($features as $i => $f)
                        <div class="row-hover border-b border-[var(--line)] {{ $i % 2 === 0 ? 'md:border-r' : '' }} px-1 py-8 sm:py-10 transition-colors reveal" data-reveal>
                            <p class="font-mono text-[11px] tracking-[0.14em] uppercase text-[var(--green)] mb-3">{{ $f['tag'] }}</p>
                            <h3 class="font-display font-semibold text-xl text-[var(--ink)] mb-2.5">{{ $f['title'] }}</h3>
                            <p class="text-[var(--slate)] leading-relaxed max-w-md">{{ $f['body'] }}</p>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>

        <!-- Capabilities -->
        <section id="capabilities" class="py-24 sm:py-28 px-5 sm:px-8 bg-[var(--paper-soft)] border-y border-[var(--line)]">
            <div class="max-w-[1400px] mx-auto">
                <div class="grid lg:grid-cols-[minmax(0,1fr)_minmax(0,1.4fr)] gap-12 lg:gap-20">
                    <div class="reveal" data-reveal>
                        <p class="font-mono text-xs tracking-[0.18em] uppercase text-[var(--green)] mb-4">Built for shared sheets</p>
                        <h2 class="font-display font-semibold text-3xl sm:text-4xl text-[var(--ink)] leading-tight mb-5">
                            Owned by one person, shared with as many as it takes
                        </h2>
                        <p class="text-[var(--slate)] leading-relaxed max-w-sm">
                            A sheet always has an owner. Sharing, teams, and public links sit on top of that — never instead of it.
                        </p>
                    </div>

                    <div class="grid sm:grid-cols-2 gap-x-10">
                        @php
                            $capabilities = [
                                ['title' => 'Four sharing tiers', 'body' => 'View, comment, edit, and admin permissions, enforced by one policy class that every controller and Livewire panel checks.'],
                                ['title' => 'Owner first, team optional', 'body' => 'A spreadsheet belongs to its owner. Attaching it to a team is optional, and never the only way in.'],
                                ['title' => 'Public read-only links', 'body' => 'Publish a bounded window of a sheet for anyone with the link — no account required to view it.'],
                                ['title' => 'Charts from a range', 'body' => 'Pick a cell range and a chart type, and Dot.Sheet builds and saves the chart definition against your data.'],
                                ['title' => 'Automation rules', 'body' => 'Workflow rules can watch a column and act when it crosses a threshold, with every run kept in an audit log.'],
                                ['title' => 'One sign-in, one ecosystem', 'body' => 'A one-time token handoff from the Dot ecosystem hub signs you in here without a separate password.'],
                            ];
                        @endphp
                        @foreach ($capabilities as $c)
                            <div class="py-6 border-t border-[var(--line)] reveal" data-reveal>
                                <h3 class="font-display font-medium text-base text-[var(--ink)] mb-1.5">{{ $c['title'] }}</h3>
                                <p class="text-sm text-[var(--slate)] leading-relaxed">{{ $c['body'] }}</p>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </section>

        <!-- CTA -->
        <section class="relative py-28 sm:py-36 px-5 sm:px-8 overflow-hidden">
            <!-- Photo: person gesturing during meeting with laptop, by Headway (@headwayio), unsplash.com/photos/5QgIuuBxKwM -->
            <div class="absolute inset-0 bg-cover bg-center" style="background-image: url('https://images.unsplash.com/photo-1517245386807-bb43f82c33c4?q=80&w=2400&auto=format&fit=crop');"></div>
            <div class="absolute inset-0" style="background: linear-gradient(180deg, #12231f 0%, rgba(18,35,31,0.82) 50%, #12231f 100%);"></div>

            <div class="relative z-10 max-w-2xl mx-auto text-center reveal" data-reveal>
                <h2 class="font-display font-semibold text-3xl sm:text-4xl text-[var(--paper)] leading-tight mb-5">
                    Open a sheet. Watch the grid catch up to you.
                </h2>
                <p class="text-[rgba(250,247,236,0.82)] leading-relaxed mb-10 max-w-lg mx-auto">
                    Formulas, comments, and version history are already there. Bring your data — the grid keeps everyone's changes in sync as you go.
                </p>

                @guest
                    <div class="flex flex-wrap justify-center gap-4">
                        <a href="{{ route('register') }}" class="press px-8 py-3.5 bg-[var(--gold)] hover:bg-[var(--gold-soft)] text-[#1c1204] font-display font-semibold rounded-lg transition-colors">
                            Create account
                        </a>
                        <a href="{{ route('login') }}" class="press px-8 py-3.5 text-[var(--paper)] font-medium rounded-lg border border-[var(--line-dark)] hover:border-[rgba(250,247,236,0.4)] transition-colors">
                            Sign in
                        </a>
                    </div>
                @endguest
            </div>
        </section>

        <!-- Footer -->
        <footer class="py-14 px-5 sm:px-8 border-t border-[var(--line)] bg-[var(--paper)]">
            <div class="max-w-[1400px] mx-auto flex flex-col sm:flex-row items-center justify-between gap-6">
                <a href="/" class="flex items-center gap-2.5">
                    <img src="{{ asset('dot_sheet.png') }}" alt="Dot.Sheet" class="h-11 w-auto opacity-90">
                </a>
                <div class="flex items-center gap-6 font-mono text-xs tracking-wide uppercase text-[var(--slate)]">
                    <a href="{{ route('policy.show') }}" class="hover:text-[var(--ink)] transition-colors">Privacy</a>
                    <a href="{{ route('cookies') }}" class="hover:text-[var(--ink)] transition-colors">Cookies</a>
                    <a href="{{ route('terms.show') }}" class="hover:text-[var(--ink)] transition-colors">Terms</a>
                </div>
                <p class="font-mono text-xs tracking-wide text-[var(--slate)]">
                    &copy; {{ date('Y') }} Dot.Sheet. Collaborative spreadsheets for the Dot ecosystem.
                </p>
            </div>
        </footer>

        <script>
            if (window.matchMedia('(prefers-reduced-motion: no-preference)').matches && 'IntersectionObserver' in window) {
                const io = new IntersectionObserver((entries) => {
                    entries.forEach((entry) => {
                        if (entry.isIntersecting) {
                            entry.target.classList.add('is-visible');
                            io.unobserve(entry.target);
                        }
                    });
                }, { threshold: 0.15, rootMargin: '0px 0px -40px 0px' });
                document.querySelectorAll('[data-reveal]').forEach((el) => io.observe(el));
            } else {
                document.querySelectorAll('[data-reveal]').forEach((el) => el.classList.add('is-visible'));
            }
        </script>

        @livewireScripts
    </body>
</html>
