{{-- R-54: the claim page, in Dot.Sheet's own sign-in look (the guest layout's ds-* parts). Shown when an account here already
     uses the email address of the InfoDot sign-in. Nothing on the account is replaced or detached; the code proves the
     address. Variables come from the shared package. --}}
<x-guest-layout>
    <x-authentication-card>
        <x-slot name="logo">
            <x-authentication-card-logo />
        </x-slot>

        <h1 class="auth-heading">Confirm it is you.</h1>
        <p class="auth-sub">An account here already uses <strong>{{ $maskedEmail }}</strong>. We sent a {{ $codeLength }}-digit code to that address. Enter it to connect the two. Your password, your spreadsheets and your teams stay as they are.</p>

        @if (session('dotsso_status'))
            <div class="ds-status" role="status">{{ session('dotsso_status') }}</div>
        @endif

        <form method="POST" action="{{ $verifyUrl }}" novalidate>
            @csrf
            <div class="ds-field">
                <label class="ds-label" for="code">Code from the email</label>
                <input class="ds-input ds-code" id="code" name="code" type="text" inputmode="numeric" pattern="[0-9]*"
                       autocomplete="one-time-code" maxlength="{{ $codeLength }}" required autofocus
                       aria-describedby="code-help code-error" @if ($errors->has('code')) aria-invalid="true" @endif />
                <p id="code-help" class="ds-help">It works for {{ $minutes }} minutes. You have {{ $maxAttempts }} tries.</p>
                <p id="code-error" class="ds-fielderror" role="alert">{{ $errors->first('code') }}</p>
            </div>
            <button type="submit" class="ds-btn-primary">Confirm and continue</button>
        </form>

        <div class="ds-footer">
            <form method="POST" action="{{ $resendUrl }}">@csrf<button type="submit" class="ds-link ds-linkbtn">Send a new code</button></form>
            <form method="POST" action="{{ $cancelUrl }}">@csrf<button type="submit" class="ds-link ds-linkbtn">Cancel</button></form>
        </div>
    </x-authentication-card>
</x-guest-layout>
