<?php

namespace App\Livewire;

use App\Livewire\Concerns\GuardsSheetState;
use App\Models\Spreadsheet;
use App\Models\User;
use App\Services\ShareRefused;
use App\Services\SheetSharing;
use App\Support\WorkbookAudit;
use Carbon\Carbon;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Component;

class SpreadsheetSharingPanel extends Component
{
    use AuthorizesRequests;
    use GuardsSheetState;

    #[Locked]
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

    protected function clientWritable(): array
    {
        return [
            'inviteEmail',
            'inviteExpiryDays',
            'invitePermission',
            'publicEnabled',
            'publicExpiresAt',
        ];
    }

    protected function sheetAbility(): string
    {
        return 'manageSharing';
    }

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

            // Checked on every request, not only at mount: sharing rights can be taken away while the panel is open.
            $this->authorize('manageSharing', $this->spreadsheet);
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
        $user = Auth::user();
        abort_unless($user instanceof User, 401);

        try {
            $shared = app(SheetSharing::class)->share($this->spreadsheet, $user, $email, $this->invitePermission, (int) $this->inviteExpiryDays);
        } catch (ShareRefused $refused) {
            $this->flash($refused->getMessage(), 'error');

            return;
        }

        WorkbookAudit::record($this->spreadsheet, $user, 'Shared with someone', 'email: '.$email.', permission: '.$this->invitePermission);
        $this->flash($shared ? 'Shared with '.$email.'.' : $email.' has no account here yet. Copy their link below and send it to them.', 'success');
        $this->inviteEmail = '';
        $this->refreshData();
    }

    public function updatePermission($userId, $permission): void
    {
        if (! in_array($permission, ['view', 'comment', 'edit', 'admin'], true)) {
            return;
        }

        try {
            app(SheetSharing::class)->setPermission($this->spreadsheet, (int) $userId, $permission, (int) Auth::id());
        } catch (ShareRefused $refused) {
            $this->flash($refused->getMessage(), 'error');

            return;
        }

        WorkbookAudit::record($this->spreadsheet, Auth::user(), 'Changed what someone can do', 'permission: '.$permission);
        $this->flash('Permission updated.', 'success');
        $this->refreshData();
    }

    public function revokeUser($userId): void
    {
        try {
            app(SheetSharing::class)->remove($this->spreadsheet, (int) $userId, (int) Auth::id());
        } catch (ShareRefused $refused) {
            $this->flash($refused->getMessage(), 'error');

            return;
        }

        WorkbookAudit::record($this->spreadsheet, Auth::user(), 'Took away someone\'s access');
        $this->flash('User access revoked.', 'success');
        $this->refreshData();
    }

    public function revokeInvitation($inviteId): void
    {
        if ($this->spreadsheet->invitations()->where('id', $inviteId)->delete() > 0) {
            WorkbookAudit::record($this->spreadsheet, Auth::user(), 'Cancelled an invitation');
        }
        $this->flash('Invitation revoked.', 'success');
        $this->refreshData();
    }

    public function savePublicLinkSettings(): void
    {
        $this->validate(['publicExpiresAt' => 'nullable|date']);

        app(SheetSharing::class)->setLink($this->spreadsheet, (bool) $this->publicEnabled, $this->publicExpiresAt ? Carbon::parse($this->publicExpiresAt) : null);

        WorkbookAudit::record($this->spreadsheet, Auth::user(), 'Changed the link for anyone', 'enabled: '.($this->publicEnabled ? 'yes' : 'no'));
        $this->flash('Public link settings saved.', 'success');
        $this->refreshData();
    }

    public function regeneratePublicToken(): void
    {
        app(SheetSharing::class)->newLink($this->spreadsheet);

        WorkbookAudit::record($this->spreadsheet, Auth::user(), 'Made a new link');
        $this->flash('Public link regenerated.', 'success');
        $this->refreshData();
    }

    public function disablePublicLink(): void
    {
        $this->spreadsheet->public_enabled = false;
        $this->spreadsheet->save();

        WorkbookAudit::record($this->spreadsheet, Auth::user(), 'Changed the link for anyone', 'enabled: no');
        $this->flash('Public link disabled.', 'success');
        $this->refreshData();
    }

    protected function flash(string $message, string $type = 'info'): void
    {
        $this->statusMessage = $message;
        $this->statusType = $type;
    }
}
