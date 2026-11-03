<x-form-section submit="updateTeamName">
    <x-slot name="title">
        {{ __('Team name') }}
    </x-slot>

    <x-slot name="description">
        {{ __('What the team is called and who owns it.') }}
    </x-slot>

    <x-slot name="form">
        <!-- Team Owner Information -->
        <div class="col-span-6">
            <x-label value="{{ __('Owner') }}" />

            <div class="owner">

                <div>
                    <div>{{ $team->owner->name }}</div>
                    <div class="soft">{{ $team->owner->email }}</div>
                </div>
            </div>
        </div>

        <!-- Team Name -->
        <div class="col-span-6 sm:col-span-4">
            <x-label for="name" value="{{ __('Team name') }}" />

            <x-input id="name"
                        type="text"
                       
                        wire:model="state.name"
                        :disabled="! Gate::check('update', $team)" />

            <x-input-error for="name" />
        </div>
    </x-slot>

    @if (Gate::check('update', $team))
        <x-slot name="actions">
            <x-action-message  on="saved">
                {{ __('Saved.') }}
            </x-action-message>

            <x-button>
                {{ __('Save') }}
            </x-button>
        </x-slot>
    @endif
</x-form-section>
