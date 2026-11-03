<x-action-section>
    <x-slot name="title">
        {{ __('Delete account') }}
    </x-slot>

    <x-slot name="description">
        {{ __('Removes the account for good.') }}
    </x-slot>

    <x-slot name="content">
        <div class="hint">
            {{ __('This removes the account and everything in it. Download anything you want to keep first.') }}
        </div>

        <div class="deeds">
            <x-danger-button wire:click="confirmUserDeletion" wire:loading.attr="disabled">
                {{ __('Delete account') }}
            </x-danger-button>
        </div>

        <!-- Delete User Confirmation Modal -->
        <x-dialog-modal wire:model.live="confirmingUserDeletion">
            <x-slot name="title">
                {{ __('Delete account') }}
            </x-slot>

            <x-slot name="content">
                {{ __('This removes the account and everything in it, and it cannot be undone. Enter your password to confirm.') }}

                <div  x-data="{}" x-on:confirming-delete-user.window="setTimeout(() => $refs.password.focus(), 250)">
                    <x-input type="password"
                                autocomplete="current-password"
                                placeholder="{{ __('Password') }}"
                                x-ref="password"
                                wire:model="password"
                                wire:keydown.enter="deleteUser" />

                    <x-input-error for="password" />
                </div>
            </x-slot>

            <x-slot name="footer">
                <x-secondary-button wire:click="$toggle('confirmingUserDeletion')" wire:loading.attr="disabled">
                    {{ __('Cancel') }}
                </x-secondary-button>

                <x-danger-button  wire:click="deleteUser" wire:loading.attr="disabled">
                    {{ __('Delete account') }}
                </x-danger-button>
            </x-slot>
        </x-dialog-modal>
    </x-slot>
</x-action-section>
