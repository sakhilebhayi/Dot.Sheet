{{--
  The one signed-in header of Dot.Sheet: the shared Dot UI header (dot-ui 0.6.0, docs/HEADER.md) with this platform's own mark, pages and account.
  Used by the signed-in pages (<x-dot-layout>), the editor (spreadsheets/grid) and the classic editor. R-52.

  Props
    page     the key of the current page: sheets, team, activity, profile, tokens, integrations, backups (null in the editors)
    title    the spreadsheet's name in the editors (a readout beside the mark, truncated); null elsewhere (the page prints its own title)
    editor   true in the editors: no primary nav in the bar (the name has the room), every page is in the account menu and the phone menu
--}}
@props(['page' => null, 'title' => null, 'editor' => false])
@php
    $user = auth()->user();
    $teams = Laravel\Jetstream\Jetstream::hasTeamFeatures();
    $currentTeam = $teams ? $user->currentTeam : null;
    $oversees = App\Http\Controllers\IntegrationsController::oversees($user, $currentTeam);
    $hasTokens = Laravel\Jetstream\Jetstream::hasApiFeatures();

    $pages = array_values(array_filter([
        ['key' => 'sheets', 'label' => 'Spreadsheets', 'href' => route('dashboard')],
        $currentTeam ? ['key' => 'team', 'label' => 'Team', 'href' => route('teams.show', $currentTeam)] : null,
        ['key' => 'activity', 'label' => 'Activity', 'href' => route('activity')],
        $oversees ? ['key' => 'integrations', 'label' => 'Integrations', 'href' => route('integrations.index')] : null,
        $hasTokens ? ['key' => 'tokens', 'label' => 'API tokens', 'href' => route('api-tokens.index')] : null,
        $user->is_platform_operator ? ['key' => 'backups', 'label' => 'Backups', 'href' => route('operator.backups.index')] : null,
    ]));
    $item = fn (array $p) => ['label' => $p['label'], 'href' => $p['href'], 'current' => $p['key'] === $page];

    // Daily pages stay in the bar; the rest wait in one named group. Profile is the account menu's.
    $primary = array_values(array_filter($pages, fn ($p) => in_array($p['key'], ['sheets', 'team', 'activity'], true)));
    $workspace = array_values(array_filter($pages, fn ($p) => in_array($p['key'], ['integrations', 'tokens', 'backups'], true)));
    $nav = array_map($item, $primary);
    if ($workspace) {
        $nav[] = ['label' => 'Workspace', 'items' => array_map($item, $workspace)];
    }

    // The search's page and action list: the same pages the nav reaches, plus Profile and starting a spreadsheet.
    $commands = [
        ['label' => 'New spreadsheet', 'href' => route('spreadsheets.create'), 'group' => 'Do'],
        ...array_map(fn (array $p) => ['label' => $p['label'], 'href' => $p['href'], 'group' => 'Go to'], $pages),
        ['label' => 'Profile', 'href' => route('profile.show'), 'group' => 'Go to'],
    ];

    $accountLinks = [];
    if ($editor) {
        foreach ($pages as $p) {
            $accountLinks[] = ['label' => $p['label'], 'href' => $p['href']];
        }
        $accountLinks[] = ['rule' => true];
    }
    $accountLinks[] = ['label' => 'Profile', 'href' => route('profile.show'), 'current' => $page === 'profile'];
    if ($teams && $user->allTeams()->count() > 1) {
        $accountLinks[] = ['heading' => 'Switch team'];
        foreach ($user->allTeams() as $team) {
            $accountLinks[] = ['label' => $team->name, 'current' => $user->isCurrentTeam($team), 'form' => ['action' => route('current-team.update'), 'method' => 'PUT', 'fields' => ['team_id' => $team->id]]];
        }
    }
    if ($teams && $currentTeam && Laravel\Jetstream\Jetstream::userHasTeamFeatures($user)) {
        $accountLinks[] = ['label' => 'New team', 'href' => route('teams.create')];
    }

    $company = $currentTeam;
    $companyUuid = $company && ! $company->personal_team && filled($company->infodot_team_uuid) ? (string) $company->infodot_team_uuid : null;
    try {
        $switcherModel = Dot\Ui\PlatformSwitcher::fromConfig('sheet')->for($companyUuid);
    } catch (Throwable) {
        // The switcher must never take the page down or print nothing: the honest fallback links to InfoDot.
        $switcherModel = Dot\Ui\PlatformSwitcher::plain('sheet');
    }
@endphp
<x-dot.header
    brand-href="{{ route('dashboard') }}" brand-label="Dot.Sheet: all spreadsheets"
    :nav="$editor ? [] : $nav" :account="[
        'name' => $user->name, 'email' => $user->email, 'team' => $company && ! $company->personal_team ? $company->name : null,
        'links' => $accountLinks, 'signOut' => ['action' => route('logout')],
    ]"
    :context="$company && ! $company->personal_team ? $company->name : null"
    skip="#sheet" class="sheet-header">
    <x-slot:brand><img src="{{ Illuminate\Support\Facades\Vite::asset('resources/images/dot-sheet-mark.png') }}" alt="" width="42" height="28"></x-slot:brand>
    @if ($title !== null)
        <x-slot:status><h1 class="dh-status__text sheet-name">{{ $title }}</h1></x-slot:status>
    @endif
    <x-slot:search>
        <x-dot.search
            name="find"
            placeholder="Search spreadsheets and pages"
            label="Search spreadsheets, tabs, pages and actions"
            :hint="$editor ? null : 'Ctrl K'"
            :hint-key="! $editor"
            results-nav enter-first escape-clears
            loading-text="Looking in your spreadsheets..."
            :root-attributes="['data-search-url' => route('search')]" />
    </x-slot:search>
    <x-slot:tools>
        <x-dot.switcher :model="$switcherModel" platform="sheet" :company="$companyUuid" :company-name="$companyUuid ? $company->name : null" logos="/dot-ui/img/platforms" />
    </x-slot:tools>
</x-dot.header>
<script type="application/json" id="commands">@json(array_values($commands), JSON_HEX_TAG | JSON_HEX_AMP)</script>
