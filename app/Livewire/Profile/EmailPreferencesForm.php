<?php

namespace App\Livewire\Profile;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * Which optional emails a person gets (EA-2, E-10). Security and account emails are always sent, and every
 * notice still shows in the notification centre whatever is chosen here.
 */
class EmailPreferencesForm extends Component
{
    /** @var array<string, bool> */
    public array $email = [];

    public function mount(): void
    {
        $user = Auth::user();
        foreach (array_keys(User::OPTIONAL_EMAIL) as $category) {
            $this->email[$category] = $user instanceof User && $user->wantsEmailAbout($category);
        }
    }

    public function save(): void
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 401);

        $preferences = (array) ($user->notification_preferences ?? []);
        foreach (array_keys(User::OPTIONAL_EMAIL) as $category) {
            $preferences[$category.'_email'] = (bool) ($this->email[$category] ?? true);
        }
        $user->forceFill(['notification_preferences' => $preferences])->save();

        $this->dispatch('saved');
    }

    public function render()
    {
        return view('profile.email-preferences-form', ['options' => User::OPTIONAL_EMAIL]);
    }
}
