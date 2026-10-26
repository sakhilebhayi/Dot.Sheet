<div class="max-w-3xl">
    @if($this->pendingProposals->isEmpty())
        <div class="bg-white rounded-xl shadow p-5 text-sm text-gray-400">
            No backups awaiting pruning review.
        </div>
    @endif

    @foreach($this->pendingProposals as $proposal)
        <div class="bg-white rounded-xl shadow p-5 mb-4">
            <div class="flex items-center justify-between mb-2">
                <div>
                    <p class="text-sm font-medium text-gray-800">{{ basename($proposal->backup_path) }}</p>
                    <p class="text-xs text-gray-400">
                        Created {{ $proposal->backup_created_at->format('M d, Y H:i') }}
                        &middot; {{ $proposal->backup_created_at->diffInDays(now()) }} days old
                    </p>
                </div>
                <span class="text-xs px-2 py-0.5 rounded-full bg-yellow-100 text-yellow-700">
                    Past retention
                </span>
            </div>

            <p class="text-xs text-gray-400 mb-3 break-all">{{ $proposal->backup_path }}</p>

            @if($rejectingProposalId === $proposal->id)
                <div class="flex items-center gap-2">
                    <input aria-label="Reason for keeping this backup" type="text" wire:model="rejectReason" placeholder="Reason for keeping this backup"
                        class="flex-1 border border-gray-300 rounded px-3 py-1.5 text-sm focus:ring-2 focus:ring-indigo-500 focus:outline-none" />
                    <button wire:click="confirmReject({{ $proposal->id }})" class="text-xs px-3 py-1.5 rounded bg-red-600 hover:bg-red-700 text-white font-medium">
                        Confirm Reject
                    </button>
                    <button wire:click="cancelReject" class="text-xs px-3 py-1.5 rounded bg-gray-200 hover:bg-gray-300 text-gray-700 font-medium">
                        Cancel
                    </button>
                </div>
            @else
                <div class="flex items-center gap-2">
                    <button wire:click="approve({{ $proposal->id }})" class="text-xs px-3 py-1.5 rounded bg-green-600 hover:bg-green-700 text-white font-medium">
                        Approve Deletion
                    </button>
                    <button wire:click="promptReject({{ $proposal->id }})" class="text-xs px-3 py-1.5 rounded bg-red-600 hover:bg-red-700 text-white font-medium">
                        Reject
                    </button>
                </div>
            @endif
        </div>
    @endforeach
</div>
