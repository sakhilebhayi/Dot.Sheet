<x-dot-layout :title="$team->name" description="The people in this team and what they may do." page="team" :livewire="true">
    <div class="parts">
        @livewire('teams.update-team-name-form', ['team' => $team])

        @livewire('teams.team-member-manager', ['team' => $team])

        @if (Gate::check('delete', $team) && ! $team->personal_team)
            @livewire('teams.delete-team-form', ['team' => $team])
        @endif
    </div>
</x-dot-layout>
