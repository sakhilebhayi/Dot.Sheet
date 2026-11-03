<x-form-section submit="createTeam">
    <x-slot name="title">
        {{ __('Team Details') }}
    </x-slot>

    <x-slot name="description">
        {{ __('A team shares spreadsheets between the people in it.') }}
    </x-slot>

    <x-slot name="form">
        <div class="col-span-6">
            <x-label value="{{ __('Owner') }}" />

            <div class="owner">

                <div>
                    <div>{{ $this->user->name }}</div>
                    <div class="soft">{{ $this->user->email }}</div>
                </div>
            </div>
        </div>

        <div class="col-span-6 sm:col-span-4">
            <x-label for="name" value="{{ __('Team name') }}" />
            <x-input id="name" type="text" wire:model="state.name" autofocus />
            <x-input-error for="name" />
        </div>
    </x-slot>

    <x-slot name="actions">
        <x-button>
            {{ __('Create') }}
        </x-button>
    </x-slot>
</x-form-section>
