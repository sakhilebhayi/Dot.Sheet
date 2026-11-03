<x-action-section>
    <x-slot name="title">
        {{ __('Delete team') }}
    </x-slot>

    <x-slot name="description">
        {{ __('Removes the team for good.') }}
    </x-slot>

    <x-slot name="content">
        <div class="hint">
            {{ __('This removes the team and everything in it. Download anything you want to keep first.') }}
        </div>

        <div class="deeds">
            <x-danger-button wire:click="$toggle('confirmingTeamDeletion')" wire:loading.attr="disabled">
                {{ __('Delete team') }}
            </x-danger-button>
        </div>

        <!-- Delete Team Confirmation Modal -->
        <x-confirmation-modal wire:model.live="confirmingTeamDeletion">
            <x-slot name="title">
                {{ __('Delete team') }}
            </x-slot>

            <x-slot name="content">
                {{ __('This removes the team and everything in it, and it cannot be undone.') }}
            </x-slot>

            <x-slot name="footer">
                <x-secondary-button wire:click="$toggle('confirmingTeamDeletion')" wire:loading.attr="disabled">
                    {{ __('Cancel') }}
                </x-secondary-button>

                <x-danger-button  wire:click="deleteTeam" wire:loading.attr="disabled">
                    {{ __('Delete team') }}
                </x-danger-button>
            </x-slot>
        </x-confirmation-modal>
    </x-slot>
</x-action-section>
