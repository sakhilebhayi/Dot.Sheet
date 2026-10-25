<div>
    @if ($open)
        <div class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center" wire:click="closeModal">
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-2xl w-full max-w-2xl mx-4" wire:click.stop>
                <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700 flex items-center justify-between">
                    <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Version History</h2>
                    <button wire:click="closeModal" class="text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200">✕</button>
                </div>

                @if ($canEdit)
                <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                    <div class="flex gap-2">
                        <input type="text" wire:model.defer="snapshotLabel" placeholder="Snapshot label (optional)"
                               class="flex-1 px-3 py-2 text-sm border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white">
                        <button wire:click="createSnapshot"
                                class="px-3 py-2 text-sm bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg font-medium">
                            Create Snapshot
                        </button>
                    </div>
                </div>
                @endif

                <div class="max-h-[420px] overflow-y-auto p-4 space-y-2">
                    @forelse ($versions as $version)
                        <div class="border border-gray-200 dark:border-gray-700 rounded-lg p-3 flex items-center justify-between">
                            <div>
                                <div class="text-sm font-medium text-gray-900 dark:text-white">{{ $version['label'] }}</div>
                                <div class="text-xs text-gray-500 dark:text-gray-400">
                                    {{ \Carbon\Carbon::parse($version['created_at'])->format('Y-m-d H:i:s') }}
                                    @if(!empty($version['user']['name']))
                                        · by {{ $version['user']['name'] }}
                                    @endif
                                    · {{ count($version['cells_snapshot'] ?? []) }} cells
                                </div>
                            </div>
                            @if ($canEdit)
                                <button wire:click="restoreVersion({{ $version['id'] }})"
                                        class="px-3 py-1.5 text-xs font-medium bg-amber-500 hover:bg-amber-600 text-white rounded">
                                    Restore
                                </button>
                            @endif
                        </div>
                    @empty
                        <div class="text-sm text-gray-500 dark:text-gray-400">No snapshots yet.</div>
                    @endforelse
                </div>
            </div>
        </div>
    @endif
</div>
