<x-dot-layout title="Profile" description="Your details, your password and where you are signed in." page="profile" :livewire="true">
    <div class="parts">
        @if (Laravel\Fortify\Features::canUpdateProfileInformation())
            @livewire('profile.update-profile-information-form')
        @endif

        @if (\App\Support\CentralReconfirm::signedInThroughInfoDot())
            {{-- No password here: a random one, never shown. Password and two-step sign-in live in InfoDot. --}}
            <p class="hint">{{ __('You sign in through InfoDot, so your password and two-step sign-in are managed in your InfoDot account.') }}</p>
        @else
            @if (Laravel\Fortify\Features::enabled(Laravel\Fortify\Features::updatePasswords()))
                @livewire('profile.update-password-form')
            @endif

            @if (Laravel\Fortify\Features::canManageTwoFactorAuthentication())
                @livewire('profile.two-factor-authentication-form')
            @endif
        @endif

        @livewire(\App\Livewire\Profile\EmailPreferencesForm::class)

        @livewire('profile.logout-other-browser-sessions-form')

        @if (Laravel\Jetstream\Jetstream::hasAccountDeletionFeatures())
            @livewire('profile.delete-user-form')
        @endif
    </div>
</x-dot-layout>
