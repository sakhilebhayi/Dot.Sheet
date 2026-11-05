@props(['title', 'description' => null, 'page' => null, 'livewire' => false])
@php
    $user = auth()->user();
    $teams = Laravel\Jetstream\Jetstream::hasTeamFeatures();
    $tabs = array_filter([
        ['key' => 'sheets', 'label' => 'Spreadsheets', 'href' => route('dashboard')],
        $teams && $user->currentTeam ? ['key' => 'team', 'label' => 'Team', 'href' => route('teams.show', $user->currentTeam)] : null,
        App\Http\Controllers\IntegrationsController::oversees($user, $user->currentTeam) ? ['key' => 'integrations', 'label' => 'Integrations', 'href' => route('integrations.index')] : null,
        ['key' => 'activity', 'label' => 'Activity', 'href' => route('activity')],
        ['key' => 'profile', 'label' => 'Profile', 'href' => route('profile.show')],
        Laravel\Jetstream\Jetstream::hasApiFeatures() ? ['key' => 'tokens', 'label' => 'API tokens', 'href' => route('api-tokens.index')] : null,
        $user->is_platform_operator ? ['key' => 'backups', 'label' => 'Backups', 'href' => route('operator.backups.index')] : null,
    ]);
    $commands = [
        ['label' => 'New spreadsheet', 'href' => route('spreadsheets.create'), 'group' => 'Do'],
        ...array_map(fn (array $tab) => ['label' => $tab['label'], 'href' => $tab['href'], 'group' => 'Go to'], $tabs),
    ];
    $told = session('flash.banner') ?? session('success') ?? session('status') ?? session('error') ?? $errors->first();
@endphp
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex">
    <title>{{ $title }}: Dot.Sheets</title>
    @if ($description)
        <meta name="description" content="{{ $description }}">
    @endif
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
    <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
    @vite(['resources/css/dot-pages.css', 'resources/js/pages/shell.js'])
    {{ $head ?? '' }}
    @if ($livewire)
        @livewireStyles
    @endif
</head>
<body class="shell pages">
    <a class="skip" href="#sheet">Skip to the page</a>

    <header class="bar">
        <a class="mark" href="{{ route('dashboard') }}" aria-label="All spreadsheets"></a>
        <h1>{{ $title }}</h1>
        {{ $bar ?? '' }}
        <span class="grow"></span>
        <button class="tool" id="open-palette" type="button">Find anything<kbd>⌘K</kbd></button>
        <button class="tool quiet who" type="button" popovertarget="account">{{ $user->name }}</button>
    </header>

    <div class="account" id="account" popover>
        <p>{{ $user->email }}</p>
        <a href="{{ route('profile.show') }}">Profile</a>
        @if ($teams && $user->allTeams()->count() > 1)
            <h2>Team</h2>
            @foreach ($user->allTeams() as $team)
                <form method="POST" action="{{ route('current-team.update') }}">
                    @method('PUT')
                    @csrf
                    <input type="hidden" name="team_id" value="{{ $team->id }}">
                    <button type="submit" @if ($user->isCurrentTeam($team)) aria-current="true" @endif>{{ $team->name }}</button>
                </form>
            @endforeach
        @endif
        @if ($teams && $user->currentTeam && Laravel\Jetstream\Jetstream::userHasTeamFeatures($user))
            <a href="{{ route('teams.create') }}">New team</a>
        @endif
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit">Sign out</button>
        </form>
        <p class="soft">Dot.Sheets</p>
    </div>

    <main class="sheet page" id="sheet">{{ $slot }}</main>

    <footer class="foot">
        <nav class="tabs" aria-label="Pages">
            @foreach ($tabs as $tab)
                <a href="{{ $tab['href'] }}" @if ($tab['key'] === $page) aria-current="page" @endif>{{ $tab['label'] }}</a>
            @endforeach
        </nav>
        <p class="note" role="status">{{ $told }}</p>
        @isset($status)
            <div class="stats">{{ $status }}</div>
        @endisset
    </footer>

    <dialog id="palette" aria-label="Find anything">
        <input id="find" placeholder="An action, a page or a spreadsheet" autocomplete="off" aria-label="Find an action, a page or a spreadsheet">
        <ul id="found"></ul>
    </dialog>

    <script type="application/json" id="commands">@json(array_values($commands), JSON_HEX_TAG | JSON_HEX_AMP)</script>
    @if ($livewire)
        @livewireScripts
    @endif
</body>
</html>
