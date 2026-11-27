<x-action-section>
    <x-slot name="title">
        {{ __('Delete account') }}
    </x-slot>

    <x-slot name="description">
        {{ __('Removes the account for good.') }}
    </x-slot>

    <x-slot name="content">
        <div class="hint">
            {{ __('This removes your account, your own workbooks and any company you own with its workbooks. Workbooks you made in a company you belong to stay with that company and pass to its owner; your comments and edits on shared workbooks stay, without your name. Download anything you want to keep first.') }}
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
                @if ($central ?? false)
                    @if ($recent ?? false)
                        {{ __('This cannot be undone. Type your email address, :email, to confirm.', ['email' => Auth::user()->email]) }}

                        <div x-data="{}" x-on:confirming-delete-user.window="setTimeout(() => $refs.confirmation.focus(), 250)">
                            <x-input type="email"
                                        id="delete-confirmation"
                                        autocomplete="off"
                                        aria-label="{{ __('Your email address') }}"
                                        placeholder="{{ __('Your email address') }}"
                                        x-ref="confirmation"
                                        wire:model="confirmation"
                                        wire:keydown.enter="deleteUser" />

                            <x-input-error for="confirmation" />
                        </div>
                    @else
                        <p>{{ __('You sign in through InfoDot, so there is no password to enter here. For your safety, sign in again through InfoDot, then come back and delete the account within :minutes minutes.', ['minutes' => \App\Support\CentralReconfirm::WINDOW_MINUTES]) }}</p>
                        <x-input-error for="confirmation" />
                    @endif
                @else
                    {{ __('This cannot be undone. Enter your password to confirm.') }}

                    <div  x-data="{}" x-on:confirming-delete-user.window="setTimeout(() => $refs.password.focus(), 250)">
                        <x-input type="password"
                                    autocomplete="current-password"
                                    aria-label="{{ __('Password') }}"
                                    placeholder="{{ __('Password') }}"
                                    x-ref="password"
                                    wire:model="password"
                                    wire:keydown.enter="deleteUser" />

                        <x-input-error for="password" />
                    </div>
                @endif
            </x-slot>

            <x-slot name="footer">
                <x-secondary-button wire:click="$toggle('confirmingUserDeletion')" wire:loading.attr="disabled">
                    {{ __('Cancel') }}
                </x-secondary-button>

                @if (($central ?? false) && ! ($recent ?? false))
                    <form method="POST" action="{{ route('profile.reconfirm') }}" class="inline">
                        @csrf
                        <x-button type="submit">{{ __('Sign in again through InfoDot') }}</x-button>
                    </form>
                @else
                    <x-danger-button  wire:click="deleteUser" wire:loading.attr="disabled">
                        {{ __('Delete account') }}
                    </x-danger-button>
                @endif
            </x-slot>
        </x-dialog-modal>
    </x-slot>
</x-action-section>
