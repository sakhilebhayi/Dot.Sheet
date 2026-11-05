<?php

namespace App\Services;

use App\Models\Spreadsheet;
use App\Models\User;
use App\Models\WorkbookApproval;
use App\Notifications\ApprovalNeeded;

/**
 * Approval for a controlled workbook. Someone asks, and a keeper other than them decides. An approved
 * workbook is sealed for everyone, keepers included, until a keeper reopens it, so what was approved is
 * what stays. Every step stays on the record.
 */
class WorkbookApprovals
{
    private const LISTED = 20;

    public function __construct(private SheetVersions $versions) {}

    public function latest(int $spreadsheetId): ?WorkbookApproval
    {
        return WorkbookApproval::where('spreadsheet_id', $spreadsheetId)->orderByDesc('id')->first();
    }

    public function isSealed(int $spreadsheetId): bool
    {
        return $this->latest($spreadsheetId)?->state === WorkbookApproval::APPROVED;
    }

    /**
     * @throws ApprovalRefused
     */
    public function request(Spreadsheet $spreadsheet, User $user, ?string $note): void
    {
        $state = $this->latest($spreadsheet->id)?->state;
        if ($state === WorkbookApproval::WAITING) {
            throw new ApprovalRefused('This workbook is already waiting for approval.');
        }
        if ($state === WorkbookApproval::APPROVED) {
            throw new ApprovalRefused('This workbook is already approved. Reopen it first.');
        }

        $keepers = $this->keepers($spreadsheet, $user);
        if ($keepers === []) {
            throw new ApprovalRefused('Nobody else can decide. Give someone the right to edit and share first.');
        }

        WorkbookApproval::create(['spreadsheet_id' => $spreadsheet->id, 'requested_by' => $user->id, 'state' => WorkbookApproval::WAITING, 'note' => $this->clean($note)]);

        foreach ($keepers as $keeper) {
            $keeper->notify(new ApprovalNeeded($spreadsheet, $user));
        }
    }

    /**
     * @throws ApprovalRefused
     */
    public function decide(Spreadsheet $spreadsheet, User $user, bool $approve, ?string $note): void
    {
        $latest = $this->latest($spreadsheet->id);
        if ($latest?->state !== WorkbookApproval::WAITING) {
            throw new ApprovalRefused('Nothing here is waiting for approval.');
        }
        if ($latest->requested_by === $user->id) {
            throw new ApprovalRefused('Someone else decides on a request you made.');
        }

        WorkbookApproval::create(['spreadsheet_id' => $spreadsheet->id, 'requested_by' => $latest->requested_by, 'decided_by' => $user->id, 'state' => $approve ? WorkbookApproval::APPROVED : WorkbookApproval::RETURNED, 'note' => $this->clean($note)]);

        if ($approve) {
            $this->keepWhatWasApproved($spreadsheet, $user);
        }
    }

    /**
     * Take back a request: whoever asked may, and so may a keeper.
     *
     * @throws ApprovalRefused
     */
    public function withdraw(Spreadsheet $spreadsheet, User $user, bool $isKeeper): void
    {
        $latest = $this->latest($spreadsheet->id);
        if ($latest?->state !== WorkbookApproval::WAITING) {
            throw new ApprovalRefused('Nothing here is waiting for approval.');
        }
        if (! $isKeeper && $latest->requested_by !== $user->id) {
            throw new ApprovalRefused('Only whoever asked, or a keeper, can take this back.');
        }

        WorkbookApproval::create(['spreadsheet_id' => $spreadsheet->id, 'decided_by' => $user->id, 'state' => WorkbookApproval::WITHDRAWN]);
    }

    /**
     * @throws ApprovalRefused
     */
    public function reopen(Spreadsheet $spreadsheet, User $user, ?string $note): void
    {
        if (! $this->isSealed($spreadsheet->id)) {
            throw new ApprovalRefused('This workbook is not approved, so there is nothing to reopen.');
        }

        WorkbookApproval::create(['spreadsheet_id' => $spreadsheet->id, 'decided_by' => $user->id, 'state' => WorkbookApproval::REOPENED, 'note' => $this->clean($note)]);
    }

    /**
     * @return array{state: string|null, yours: bool, steps: list<array{state: string, who: string|null, note: string|null, at: string}>}
     */
    public function record(int $spreadsheetId, int $viewerId): array
    {
        $steps = WorkbookApproval::where('spreadsheet_id', $spreadsheetId)->orderByDesc('id')->limit(self::LISTED)->get();
        $names = User::whereIn('id', [...$steps->pluck('requested_by'), ...$steps->pluck('decided_by')])->pluck('name', 'id');

        return [
            'state' => $steps->first()?->state,
            'yours' => $steps->first()?->requested_by === $viewerId,
            'steps' => $steps->map(fn (WorkbookApproval $step) => [
                'state' => $step->state,
                'who' => $names[$step->decided_by ?? $step->requested_by] ?? null,
                'note' => $step->note,
                'at' => (string) $step->created_at?->toDateString(),
            ])->all(),
        ];
    }

    /** What was approved stays on record even after a reopening, sheet by sheet. A sheet too large to copy is left out. */
    private function keepWhatWasApproved(Spreadsheet $spreadsheet, User $user): void
    {
        foreach ($spreadsheet->sheetTabs()->pluck('id') as $tabId) {
            if (SheetVersions::canCopyWhole($tabId)) {
                $this->versions->snapshot($spreadsheet->id, $tabId, $user->id, SheetVersions::MANUAL, 'Approved by '.$user->name);
            }
        }
    }

    /**
     * @return list<User>
     */
    private function keepers(Spreadsheet $spreadsheet, User $asking): array
    {
        $admins = $spreadsheet->sharedUsers()->wherePivot('permission', 'admin')->get()->all();
        $owner = User::find($spreadsheet->owner_id);

        return array_values(array_filter([$owner, ...$admins], fn (?User $user) => $user !== null && $user->id !== $asking->id));
    }

    private function clean(?string $note): ?string
    {
        $note = trim((string) $note);

        return $note === '' ? null : mb_substr($note, 0, 500);
    }
}
