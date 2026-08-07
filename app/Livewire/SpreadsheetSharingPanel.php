<?php

namespace App\Livewire;

use App\Models\Spreadsheet;
use App\Models\SpreadsheetInvitation;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Component;

class SpreadsheetSharingPanel extends Component
{
    use AuthorizesRequests;

    public $spreadsheet_id;

    protected ?Spreadsheet $spreadsheet = null;

    public $inviteEmail = '';

    public $invitePermission = 'view';

    public $inviteExpiryDays = 7;

    public $sharedUsers = [];

    public $pendingInvites = [];

    public $publicEnabled = false;

    public $publicExpiresAt = '';

    public $publicUrl = '';

    public $statusMessage = '';

    public $statusType = 'info';

    public function mount($spreadsheet_id)
    {
        $this->spreadsheet_id = $spreadsheet_id;
        $this->spreadsheet = Spreadsheet::findOrFail($spreadsheet_id);
        $this->authorize('manageSharing', $this->spreadsheet);
        $this->refreshData();
    }

    public function hydrate(): void
    {
        if ($this->spreadsheet_id) {
            $this->spreadsheet = Spreadsheet::findOrFail($this->spreadsheet_id);
        }
    }

    public function render()
    {
        return view('livewire.spreadsheet-sharing-panel');
    }

    public function refreshData(): void
    {
        $this->spreadsheet->refresh();

        $this->sharedUsers = $this->spreadsheet->sharedUsers()
            ->select('users.id', 'users.name', 'users.email')
            ->withPivot('permission', 'updated_at')
            ->get()
            ->toArray();

        $this->pendingInvites = $this->spreadsheet->invitations()
            ->whereNull('accepted_at')
            ->latest()
            ->get()
            ->map(function ($invite) {
                return [
                    'id' => $invite->id,
                    'email' => $invite->email,
                    'permission' => $invite->permission,
                    'expires_at' => optional($invite->expires_at)?->toDateTimeString(),
                    'accept_url' => route('spreadsheets.invitations.accept', $invite->token),
                ];
            })
            ->toArray();

        $this->publicEnabled = (bool) $this->spreadsheet->public_enabled;
        $this->publicExpiresAt = optional($this->spreadsheet->public_expires_at)?->format('Y-m-d\TH:i');
        $this->publicUrl = $this->spreadsheet->public_token
            ? route('spreadsheets.public', $this->spreadsheet->public_token)
            : '';
    }

    public function inviteByEmail(): void
    {
        $this->validate([
            'inviteEmail' => 'required|email|max:255',
            'invitePermission' => 'required|in:view,comment,edit,admin',
            'inviteExpiryDays' => 'required|integer|min:1|max:90',
        ]);

        $email = strtolower(trim($this->inviteEmail));

        // If user exists, directly attach with permission.
        $existingUser = User::where('email', $email)->first();
        if ($existingUser) {
            if ((int) $existingUser->id === (int) $this->spreadsheet->owner_id) {
                $this->flash('Cannot share with the owner account.', 'error');

                return;
            }

            $this->spreadsheet->sharedUsers()->syncWithoutDetaching([
                $existingUser->id => ['permission' => $this->invitePermission],
            ]);

            $this->flash('Access granted to existing user.', 'success');
            $this->inviteEmail = '';
            $this->refreshData();

            return;
        }

        // External email flow: create invitation record.
        $invite = SpreadsheetInvitation::updateOrCreate(
            [
                'spreadsheet_id' => $this->spreadsheet->id,
                'email' => $email,
                'accepted_at' => null,
            ],
            [
                'invited_by' => Auth::id(),
                'permission' => $this->invitePermission,
                'token' => Str::random(48),
                'expires_at' => now()->addDays((int) $this->inviteExpiryDays),
            ]
        );

        $this->flash('Invitation created. Share the acceptance link below.', 'success');
        $this->inviteEmail = '';
        $this->refreshData();
    }

    public function updatePermission($userId, $permission): void
    {
        if (! in_array($permission, ['view', 'comment', 'edit', 'admin'], true)) {
            return;
        }

        $this->spreadsheet->sharedUsers()->updateExistingPivot($userId, [
            'permission' => $permission,
        ]);

        $this->flash('Permission updated.', 'success');
        $this->refreshData();
    }

    public function revokeUser($userId): void
    {
        $this->spreadsheet->sharedUsers()->detach($userId);
        $this->flash('User access revoked.', 'success');
        $this->refreshData();
    }

    public function revokeInvitation($inviteId): void
    {
        $this->spreadsheet->invitations()->where('id', $inviteId)->delete();
        $this->flash('Invitation revoked.', 'success');
        $this->refreshData();
    }

    public function savePublicLinkSettings(): void
    {
        $expiresAt = null;
        if ($this->publicExpiresAt) {
            $expiresAt = Carbon::parse($this->publicExpiresAt);
        }

        if ($this->publicEnabled && ! $this->spreadsheet->public_token) {
            $this->spreadsheet->public_token = Str::random(40);
        }

        $this->spreadsheet->public_enabled = (bool) $this->publicEnabled;
        $this->spreadsheet->public_expires_at = $expiresAt;
        $this->spreadsheet->save();

        $this->flash('Public link settings saved.', 'success');
        $this->refreshData();
    }

    public function regeneratePublicToken(): void
    {
        $this->spreadsheet->public_token = Str::random(40);
        $this->spreadsheet->public_enabled = true;
        $this->spreadsheet->save();

        $this->flash('Public link regenerated.', 'success');
        $this->refreshData();
    }

    public function disablePublicLink(): void
    {
        $this->spreadsheet->public_enabled = false;
        $this->spreadsheet->save();

        $this->flash('Public link disabled.', 'success');
        $this->refreshData();
    }

    protected function flash(string $message, string $type = 'info'): void
    {
        $this->statusMessage = $message;
        $this->statusType = $type;
    }
}
