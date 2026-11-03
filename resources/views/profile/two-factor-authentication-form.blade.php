<x-action-section>
    <x-slot name="title">
        {{ __('Two-step sign-in') }}
    </x-slot>

    <x-slot name="description">
        {{ __('A code from your phone at sign-in, on top of the password.') }}
    </x-slot>

    <x-slot name="content">
        <h3 class="state">
            @if ($this->enabled)
                @if ($showingConfirmation)
                    {{ __('Finish turning on two-step sign-in.') }}
                @else
                    {{ __('Two-step sign-in is on.') }}
                @endif
            @else
                {{ __('Two-step sign-in is off.') }}
            @endif
        </h3>

        <div class="hint">
            <p>
                {{ __('When it is on, you enter a code from an authenticator app on your phone each time you sign in.') }}
            </p>
        </div>

        @if ($this->enabled)
            @if ($showingQrCode)
                <div class="hint">
                    <p class="strong">
                        @if ($showingConfirmation)
                            {{ __('To finish enabling two factor authentication, scan the following QR code using your phone\'s authenticator application or enter the setup key and provide the generated OTP code.') }}
                        @else
                            {{ __('Two factor authentication is now enabled. Scan the following QR code using your phone\'s authenticator application or enter the setup key.') }}
                        @endif
                    </p>
                </div>

                <div class="qr">
                    {!! $this->user->twoFactorQrCodeSvg() !!}
                </div>

                <div class="hint">
                    <p class="strong">
                        {{ __('Setup Key') }}: {{ decrypt($this->user->two_factor_secret) }}
                    </p>
                </div>

                @if ($showingConfirmation)
                    <div>
                        <x-label for="code" value="{{ __('Code') }}" />

                        <x-input id="code" type="text" name="code" inputmode="numeric" autofocus autocomplete="one-time-code"
                            wire:model="code"
                            wire:keydown.enter="confirmTwoFactorAuthentication" />

                        <x-input-error for="code" />
                    </div>
                @endif
            @endif

            @if ($showingRecoveryCodes)
                <div class="hint">
                    <p class="strong">
                        {{ __('Store these recovery codes in a secure password manager. They can be used to recover access to your account if your two factor authentication device is lost.') }}
                    </p>
                </div>

                <div class="codes">
                    @foreach (json_decode(decrypt($this->user->two_factor_recovery_codes), true) as $code)
                        <div>{{ $code }}</div>
                    @endforeach
                </div>
            @endif
        @endif

        <div class="deeds">
            @if (! $this->enabled)
                <x-confirms-password wire:then="enableTwoFactorAuthentication">
                    <x-secondary-button wire:loading.attr="disabled">
                        {{ __('Turn on') }}
                    </x-secondary-button>
                </x-confirms-password>
            @else
                @if ($showingRecoveryCodes)
                    <x-confirms-password wire:then="regenerateRecoveryCodes">
                        <x-secondary-button >
                            {{ __('Regenerate Recovery Codes') }}
                        </x-secondary-button>
                    </x-confirms-password>
                @elseif ($showingConfirmation)
                    <x-confirms-password wire:then="confirmTwoFactorAuthentication">
                        <x-button type="button"  wire:loading.attr="disabled">
                            {{ __('Confirm') }}
                        </x-button>
                    </x-confirms-password>
                @else
                    <x-confirms-password wire:then="showRecoveryCodes">
                        <x-secondary-button >
                            {{ __('Show Recovery Codes') }}
                        </x-secondary-button>
                    </x-confirms-password>
                @endif

                @if ($showingConfirmation)
                    <x-confirms-password wire:then="disableTwoFactorAuthentication">
                        <x-secondary-button wire:loading.attr="disabled">
                            {{ __('Cancel') }}
                        </x-secondary-button>
                    </x-confirms-password>
                @else
                    <x-confirms-password wire:then="disableTwoFactorAuthentication">
                        <x-danger-button wire:loading.attr="disabled">
                            {{ __('Disable') }}
                        </x-danger-button>
                    </x-confirms-password>
                @endif

            @endif
        </div>
    </x-slot>
</x-action-section>
