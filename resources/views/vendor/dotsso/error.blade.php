{{-- R-54: every sign-in failure ends here, in words, with one next step and no automatic redirect. --}}
<x-guest-layout>
    <x-authentication-card>
        <x-slot name="logo">
            <x-authentication-card-logo />
        </x-slot>

        <div role="alert">
            <h1 class="auth-heading">{{ $headline }}</h1>
            <p class="auth-sub">{{ $body }}</p>
            @if ($retry)<a class="ds-btn-primary" href="{{ $retryUrl }}">Start again</a>@endif
            <a class="ds-btn-secondary" href="{{ $homeUrl }}">Back to Dot.Sheet</a>
            <p class="ds-help">Reference: {{ $code }}</p>
        </div>
    </x-authentication-card>
</x-guest-layout>
