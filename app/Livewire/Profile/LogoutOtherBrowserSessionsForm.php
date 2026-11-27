<?php

namespace App\Livewire\Profile;

use App\Support\CentralReconfirm;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Validation\ValidationException;
use Laravel\Jetstream\Http\Livewire\LogoutOtherBrowserSessionsForm as JetstreamForm;

/**
 * Jetstream's form, plus a way through for people who sign in through InfoDot: a recent sign-in through
 * InfoDot instead of a password they never had. Their other sessions here are ended; InfoDot's own
 * sessions are managed in InfoDot.
 */
class LogoutOtherBrowserSessionsForm extends JetstreamForm
{
    public function logoutOtherBrowserSessions(StatefulGuard $guard)
    {
        if (! CentralReconfirm::signedInThroughInfoDot()) {
            parent::logoutOtherBrowserSessions($guard);

            return;
        }

        if (config('session.driver') !== 'database') {
            return;
        }

        $this->resetErrorBag();

        if (! CentralReconfirm::isRecent()) {
            throw ValidationException::withMessages([
                'password' => [__('Sign in again through InfoDot first, then try within :minutes minutes.', ['minutes' => CentralReconfirm::WINDOW_MINUTES])],
            ]);
        }

        $this->deleteOtherSessionRecords();

        $this->confirmingLogout = false;

        $this->dispatch('loggedOut');
    }

    public function render()
    {
        return view('profile.logout-other-browser-sessions-form', [
            'central' => CentralReconfirm::signedInThroughInfoDot(),
            'recent' => CentralReconfirm::isRecent(),
        ]);
    }
}
