<?php

namespace App\Livewire\Profile;

use App\Support\CentralReconfirm;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Laravel\Jetstream\Contracts\DeletesUsers;
use Laravel\Jetstream\Http\Livewire\DeleteUserForm as JetstreamDeleteUserForm;

/**
 * Jetstream's form, plus a way through for people who sign in through InfoDot: a sign-in through InfoDot in
 * the last few minutes and their email address typed out, in place of a password they never had.
 */
class DeleteUserForm extends JetstreamDeleteUserForm
{
    public string $confirmation = '';

    public function confirmUserDeletion()
    {
        $this->confirmation = '';

        parent::confirmUserDeletion();
    }

    public function deleteUser(Request $request, DeletesUsers $deleter, StatefulGuard $auth)
    {
        if (! CentralReconfirm::signedInThroughInfoDot()) {
            return parent::deleteUser($request, $deleter, $auth);
        }

        $this->resetErrorBag();

        if (! CentralReconfirm::isRecent()) {
            throw ValidationException::withMessages([
                'confirmation' => [__('Sign in again through InfoDot first, then delete the account within :minutes minutes.', ['minutes' => CentralReconfirm::WINDOW_MINUTES])],
            ]);
        }

        if (strcasecmp(trim($this->confirmation), (string) Auth::user()->email) !== 0) {
            throw ValidationException::withMessages([
                'confirmation' => [__('Type your email address exactly as it appears on this page.')],
            ]);
        }

        $deleter->delete(Auth::user()->fresh());

        $auth->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return redirect(config('fortify.redirects.logout') ?? '/');
    }

    public function render()
    {
        return view('profile.delete-user-form', [
            'central' => CentralReconfirm::signedInThroughInfoDot(),
            'recent' => CentralReconfirm::isRecent(),
        ]);
    }
}
