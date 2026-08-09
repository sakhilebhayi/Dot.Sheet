<?php

namespace App\Livewire;

use App\Models\BackupPruneProposal;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;

class BackupProposalQueue extends Component
{
    public ?int $rejectingProposalId = null;

    public string $rejectReason = '';

    public function mount(): void
    {
        $this->ensureOperator();
    }

    #[Computed]
    public function pendingProposals(): Collection
    {
        return BackupPruneProposal::where('status', 'pending')
            ->latest('backup_created_at')
            ->get();
    }

    public function approve(int $proposalId): void
    {
        $this->ensureOperator();

        $proposal = BackupPruneProposal::find($proposalId);

        if (! $proposal || $proposal->status !== 'pending') {
            return;
        }

        if (File::isDirectory($proposal->backup_path)) {
            File::deleteDirectory($proposal->backup_path);
        }

        $proposal->update([
            'status' => 'approved',
            'reviewed_by' => auth()->id(),
            'reviewed_at' => now(),
        ]);

        unset($this->pendingProposals);
    }

    public function promptReject(int $proposalId): void
    {
        $this->ensureOperator();

        $this->rejectingProposalId = $proposalId;
        $this->rejectReason = '';
    }

    public function cancelReject(): void
    {
        $this->rejectingProposalId = null;
        $this->rejectReason = '';
    }

    public function confirmReject(int $proposalId): void
    {
        $this->ensureOperator();

        if (trim($this->rejectReason) === '') {
            return;
        }

        $proposal = BackupPruneProposal::find($proposalId);

        if (! $proposal || $proposal->status !== 'pending') {
            return;
        }

        $proposal->update([
            'status' => 'rejected',
            'rejected_reason' => $this->rejectReason,
            'reviewed_by' => auth()->id(),
            'reviewed_at' => now(),
        ]);

        $this->rejectingProposalId = null;
        $this->rejectReason = '';
        unset($this->pendingProposals);
    }

    private function ensureOperator(): void
    {
        throw_unless(auth()->user()?->is_platform_operator, AuthorizationException::class);
    }

    public function render(): View
    {
        return view('livewire.backup-proposal-queue');
    }
}
