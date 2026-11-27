<?php

namespace App\Services;

use App\Models\Spreadsheet;
use App\Models\Team;
use App\Models\User;
use App\Notifications\ActivityNotice;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Who is told what when access, approvals, companies or security settings change (EA-2, E-07). A notice that
 * cannot be queued never breaks the change that raised it.
 */
final class AccountNotices
{
    private const WORDS = ['view' => 'view it', 'comment' => 'view and comment', 'edit' => 'edit it', 'admin' => 'edit and share it'];

    public function shared(Spreadsheet $spreadsheet, User $by, User $with, string $permission): void
    {
        $this->tell($with, new ActivityNotice('shared', 'collaboration', 'A workbook was shared with you',
            $by->name.' shared "'.$spreadsheet->name.'" with you. You can '.(self::WORDS[$permission] ?? 'view it').'.',
            route('spreadsheets.show', $spreadsheet), 'Open the workbook'));
    }

    public function accessChanged(Spreadsheet $spreadsheet, ?User $by, int $userId, ?string $permission): void
    {
        $person = User::find($userId);
        if ($person === null || $person->id === $by?->id) {
            return;
        }

        $who = $by !== null ? $by->name : 'Someone';
        $this->tell($person, $permission === null
            ? new ActivityNotice('access_removed', 'collaboration', 'Your access to a workbook ended',
                $who.' removed your access to "'.$spreadsheet->name.'".', route('dashboard'), 'Open your workbooks')
            : new ActivityNotice('access_changed', 'collaboration', 'What you can do in a workbook changed',
                $who.' changed what you can do in "'.$spreadsheet->name.'". You can now '.(self::WORDS[$permission] ?? 'view it').'.',
                route('spreadsheets.show', $spreadsheet), 'Open the workbook'));
    }

    public function approvalDecided(Spreadsheet $spreadsheet, User $by, ?int $requesterId, bool $approved, ?string $note): void
    {
        $requester = $requesterId === null ? null : User::find($requesterId);
        if ($requester === null || $requester->id === $by->id) {
            return;
        }

        $this->tell($requester, new ActivityNotice($approved ? 'approval_approved' : 'approval_returned', 'collaboration',
            $approved ? 'Your workbook was approved' : 'Your workbook was sent back',
            $by->name.($approved ? ' approved "' : ' sent back "').$spreadsheet->name.'"'.(filled($note) ? ' with a note: '.$note : '.'),
            route('spreadsheets.show', $spreadsheet), 'Open the workbook'));
    }

    public function teamRole(Team $team, User $member, ?string $role): void
    {
        $this->tell($member, $role === null
            ? new ActivityNotice('team_removed', 'team', 'You were removed from a company',
                'You are no longer a member of '.$team->name.' in Dot.Sheet. Its workbooks stay with the company.', route('dashboard'))
            : new ActivityNotice('team_role', 'team', 'Your role in a company changed',
                'Your role in '.$team->name.' is now '.$role.'.', route('dashboard')));
    }

    public function teamAdded(Team $team, User $member): void
    {
        $this->tell($member, new ActivityNotice('team_added', 'team', 'You were added to a company',
            'You are now a member of '.$team->name.' in Dot.Sheet.', route('dashboard')));
    }

    public function security(User $user, string $what): void
    {
        $this->tell($user, new ActivityNotice('security', 'security', 'A security setting changed',
            $what.' on your Dot.Sheet account at '.now()->toDayDateTimeString().' (UTC).', route('profile.show'), 'Review your profile'));
    }

    public function accountDeleted(string $email, string $name): void
    {
        try {
            Notification::route('mail', [$email => $name])->notify(new ActivityNotice('account_deleted', 'security', 'Your account was deleted',
                'Your Dot.Sheet account and the workbooks only you owned were deleted. Workbooks you made in a company stay with that company. Your InfoDot account is not affected.',
                null, mailOnly: true));
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function tell(User $user, ActivityNotice $notice): void
    {
        try {
            $user->notify($notice);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
