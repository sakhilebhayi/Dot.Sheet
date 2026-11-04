<?php

namespace App\Services;

use App\Models\Spreadsheet;
use App\Models\SpreadsheetInvitation;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;

/**
 * Who can reach a spreadsheet, in both editors. An account with a proven address gets access at once.
 * Anyone else gets an invitation, a link that someone has to pass on: nothing is sent by email.
 */
class SheetSharing
{
    public const PERMISSIONS = ['view', 'comment', 'edit', 'admin'];

    public const OWNER_MESSAGE = 'Cannot share with the owner account.';

    public const SELF_MESSAGE = 'You cannot change your own access. Ask the owner.';

    /**
     * Everything the panel shows. The links in it are only for people who may manage sharing.
     *
     * @return array<string, mixed>
     */
    public function state(Spreadsheet $spreadsheet, int $viewerId): array
    {
        $owner = User::find($spreadsheet->owner_id);
        $members = $spreadsheet->team?->users()->count() ?? 0;

        return [
            'owner' => ['name' => $owner?->name, 'email' => $owner?->email, 'you' => $spreadsheet->owner_id === $viewerId],
            'team' => $members > 0 ? ['name' => $spreadsheet->team->name, 'members' => $members] : null,
            'people' => $spreadsheet->sharedUsers()->orderBy('users.name')->get(['users.id', 'users.name', 'users.email'])
                ->map(fn (User $user) => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'permission' => $user->getRelationValue('pivot')->permission,
                    'you' => $user->id === $viewerId,
                ])->all(),
            'waiting' => $spreadsheet->invitations()->whereNull('accepted_at')->latest()->get()
                ->map(fn (SpreadsheetInvitation $invite) => [
                    'id' => $invite->id,
                    'email' => $invite->email,
                    'permission' => $invite->permission,
                    'expires_at' => $invite->expires_at?->toIso8601String(),
                    'expired' => $invite->isExpired(),
                    'link' => route('spreadsheets.invitations.accept', $invite->token),
                ])->all(),
            'link' => [
                'enabled' => (bool) $spreadsheet->public_enabled,
                'active' => $spreadsheet->publicLinkIsActive(),
                'url' => $spreadsheet->public_enabled && $spreadsheet->public_token ? route('spreadsheets.public', $spreadsheet->public_token) : null,
                'expires_at' => $spreadsheet->public_expires_at?->toIso8601String(),
            ],
        ];
    }

    /**
     * @return bool true when they have access now, false when an invitation waits for them
     *
     * @throws ShareRefused
     */
    public function share(Spreadsheet $spreadsheet, User $by, string $email, string $permission, int $days): bool
    {
        $email = strtolower(trim($email));

        if (strcasecmp($email, (string) User::whereKey($spreadsheet->owner_id)->value('email')) === 0) {
            throw new ShareRefused(self::OWNER_MESSAGE);
        }
        if (strcasecmp($email, (string) $by->email) === 0) {
            throw new ShareRefused(self::SELF_MESSAGE);
        }

        // An account whose address is proven gets access at once. Anyone else, including an account that
        // changed to this address and has not proven it, accepts an invitation sent to the address.
        $existingUser = User::whereRaw('lower(email) = ?', [$email])->whereNotNull('email_verified_at')->first();
        if ($existingUser) {
            $spreadsheet->sharedUsers()->syncWithoutDetaching([$existingUser->id => ['permission' => $permission]]);

            return true;
        }

        SpreadsheetInvitation::updateOrCreate(
            ['spreadsheet_id' => $spreadsheet->id, 'email' => $email, 'accepted_at' => null],
            ['invited_by' => $by->id, 'permission' => $permission, 'token' => Str::random(48), 'expires_at' => now()->addDays($days)],
        );

        return false;
    }

    /**
     * @throws ShareRefused
     */
    public function setPermission(Spreadsheet $spreadsheet, int $userId, string $permission, int $byId): void
    {
        $this->refuseOwnAccess($userId, $byId);
        $spreadsheet->sharedUsers()->updateExistingPivot($userId, ['permission' => $permission]);
    }

    /**
     * @throws ShareRefused
     */
    public function remove(Spreadsheet $spreadsheet, int $userId, int $byId): void
    {
        $this->refuseOwnAccess($userId, $byId);
        $spreadsheet->sharedUsers()->detach($userId);
    }

    /**
     * Turning the link off keeps its address, so turning it on again brings back the same link. Only a new
     * link retires the old one.
     */
    public function setLink(Spreadsheet $spreadsheet, bool $enabled, ?CarbonInterface $expiresAt): void
    {
        if ($enabled && ! $spreadsheet->public_token) {
            $spreadsheet->public_token = Str::random(40);
        }

        $spreadsheet->public_enabled = $enabled;
        // A time keeps its moment: Eloquent writes the clock digits as they stand, in whatever zone they came.
        $spreadsheet->public_expires_at = $expiresAt === null ? null : Carbon::instance($expiresAt)->setTimezone((string) config('app.timezone'));
        $spreadsheet->save();
    }

    public function newLink(Spreadsheet $spreadsheet): void
    {
        $spreadsheet->public_token = Str::random(40);
        $spreadsheet->public_enabled = true;
        $spreadsheet->save();
    }

    /** One slip here locks them out, and only someone else can let them back in. */
    private function refuseOwnAccess(int $userId, int $byId): void
    {
        if ($userId === $byId) {
            throw new ShareRefused(self::SELF_MESSAGE);
        }
    }
}
