<?php

namespace App\Policies;

use App\Models\Spreadsheet;
use App\Models\User;

class SpreadsheetPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Spreadsheet $spreadsheet): bool
    {
        return $this->isOwner($user, $spreadsheet) ||
               $this->isTeamMember($user, $spreadsheet) ||
               $this->isSharedWithPermission($user, $spreadsheet);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Spreadsheet $spreadsheet): bool
    {
        return $this->isOwner($user, $spreadsheet) ||
               $this->isTeamMemberWithEdit($user, $spreadsheet) ||
               $this->isSharedWithPermission($user, $spreadsheet, ['edit', 'admin']);
    }

    /**
     * Determine whether the user can comment on the model.
     */
    public function comment(User $user, Spreadsheet $spreadsheet): bool
    {
        return $this->isOwner($user, $spreadsheet) ||
               $this->isTeamMember($user, $spreadsheet) ||
               $this->isSharedWithPermission($user, $spreadsheet, ['comment', 'edit', 'admin']);
    }

    /**
     * Determine whether the user can manage sharing for the model.
     */
    public function manageSharing(User $user, Spreadsheet $spreadsheet): bool
    {
        return $this->isOwner($user, $spreadsheet) ||
               $this->isSharedWithPermission($user, $spreadsheet, ['admin']);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Spreadsheet $spreadsheet): bool
    {
        return $this->isOwner($user, $spreadsheet);
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Spreadsheet $spreadsheet): bool
    {
        return $this->isOwner($user, $spreadsheet);
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Spreadsheet $spreadsheet): bool
    {
        return $this->isOwner($user, $spreadsheet);
    }

    /**
     * Check if the user is the owner of the spreadsheet.
     */
    protected function isOwner(User $user, Spreadsheet $spreadsheet): bool
    {
        return $user->id === $spreadsheet->owner_id;
    }

    /**
     * Check if the user is a member of the team that owns the spreadsheet.
     */
    protected function isTeamMember(User $user, Spreadsheet $spreadsheet): bool
    {
        if (! $spreadsheet->team_id) {
            return false;
        }

        return $user->teams()->where('id', $spreadsheet->team_id)->exists();
    }

    /**
     * Check if the user is a team member with edit access.
     */
    protected function isTeamMemberWithEdit(User $user, Spreadsheet $spreadsheet): bool
    {
        if (! $spreadsheet->team_id) {
            return false;
        }

        // Check if user is team owner
        if ($user->teams()->where('id', $spreadsheet->team_id)->where('user_id', $user->id)->exists()) {
            return true;
        }

        // Check if user is a team member
        return $user->teams()->where('id', $spreadsheet->team_id)->exists();
    }

    /**
     * Check if the spreadsheet is shared with the user with appropriate permissions.
     */
    protected function isSharedWithPermission(User $user, Spreadsheet $spreadsheet, array $permissions = ['view', 'comment', 'edit', 'admin']): bool
    {
        return $spreadsheet->sharedUsers()
            ->where('user_id', $user->id)
            ->whereIn('permission', $permissions)
            ->exists();
    }
}
