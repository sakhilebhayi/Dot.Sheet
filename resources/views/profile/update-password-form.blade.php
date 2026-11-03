<x-form-section submit="updatePassword">
    <x-slot name="title">
        {{ __('Password') }}
    </x-slot>

    <x-slot name="description">
        {{ __('A long, random password keeps the account safe.') }}
    </x-slot>

    <x-slot name="form">
        <div class="col-span-6 sm:col-span-4">
            <x-label for="current_password" value="{{ __('Current password') }}" />
            <x-input id="current_password" type="password" wire:model="state.current_password" autocomplete="current-password" />
            <x-input-error for="current_password" />
        </div>

        <div class="col-span-6 sm:col-span-4">
            <x-label for="password" value="{{ __('New password') }}" />
            <x-input id="password" type="password" wire:model="state.password" autocomplete="new-password" />
            <x-input-error for="password" />
        </div>

        <div class="col-span-6 sm:col-span-4">
            <x-label for="password_confirmation" value="{{ __('Repeat new password') }}" />
            <x-input id="password_confirmation" type="password" wire:model="state.password_confirmation" autocomplete="new-password" />
            <x-input-error for="password_confirmation" />
        </div>
    </x-slot>

    <x-slot name="actions">
        <x-action-message  on="saved">
            {{ __('Saved.') }}
        </x-action-message>

        <x-button>
            {{ __('Save') }}
        </x-button>
    </x-slot>
</x-form-section>
