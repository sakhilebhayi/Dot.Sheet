<?php

namespace App\Policies;

use App\Models\Membership;
use App\Models\Spreadsheet;
use App\Models\User;
use Laravel\Jetstream\Jetstream;

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
               $this->teamRoleAllows($user, $spreadsheet, 'update') ||
               $this->isSharedWithPermission($user, $spreadsheet, ['edit', 'admin']);
    }

    /**
     * Determine whether the user can comment on the model.
     */
    public function comment(User $user, Spreadsheet $spreadsheet): bool
    {
        return $this->isOwner($user, $spreadsheet) ||
               $this->teamRoleAllows($user, $spreadsheet, 'create') ||
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

        // Qualified: team_user has an id column too, and the bare `id` made this query fail for every member.
        return $user->teams()->where('teams.id', $spreadsheet->team_id)->exists();
    }

    /**
     * Check if the user is a member of the sheet's team whose Jetstream role grants the permission
     * ('create' to add content such as comments, 'update' to change the sheet). A member whose role
     * is missing or unknown gets nothing: the roles are admin and editor (create + update) and
     * viewer (read only), so a viewer reads and exports the team's sheets but cannot write to them.
     */
    protected function teamRoleAllows(User $user, Spreadsheet $spreadsheet, string $permission): bool
    {
        if (! $spreadsheet->team_id) {
            return false;
        }

        // The membership row, read directly: one query, and a team's owner (who has no row) gets nothing here.
        $role = Membership::query()
            ->where('team_id', $spreadsheet->team_id)
            ->where('user_id', $user->id)
            ->value('role');

        $permissions = Jetstream::$roles[(string) $role]->permissions ?? [];

        return in_array($permission, $permissions, true) || in_array('*', $permissions, true);
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
