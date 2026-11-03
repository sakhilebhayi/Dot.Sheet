<section class="part">
    <div class="part-title">
        <h2>Past their retention window</h2>
        <p>Nothing is deleted until you approve it here.</p>
    </div>

    <div class="part-body">
        @if($this->pendingProposals->isEmpty())
            <p class="hint">No backups are waiting for a decision.</p>
        @endif

        <div class="entries">
            @foreach($this->pendingProposals as $proposal)
                <div class="proposal">
                    <p class="strong">{{ basename($proposal->backup_path) }}</p>
                    <p class="soft">Made {{ $proposal->backup_created_at->format('j M Y H:i') }}, {{ $proposal->backup_created_at->diffInDays(now()) }} days old</p>
                    <p class="soft path">{{ $proposal->backup_path }}</p>

                    @if($rejectingProposalId === $proposal->id)
                        <div class="deeds">
                            <input class="dot-field" aria-label="Reason for keeping this backup" type="text" wire:model="rejectReason" placeholder="Reason for keeping this backup">
                            <button class="button" type="button" wire:click="confirmReject({{ $proposal->id }})">Keep it</button>
                            <button class="button quiet" type="button" wire:click="cancelReject">Cancel</button>
                        </div>
                    @else
                        <div class="deeds">
                            <button class="button" type="button" wire:click="approve({{ $proposal->id }})">Approve deletion</button>
                            <button class="button quiet" type="button" wire:click="promptReject({{ $proposal->id }})">Keep this backup</button>
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    </div>
</section>
