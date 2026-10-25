<div class="space-y-3">
    <div class="flex items-center justify-between">
        <h3 class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Comments</h3>
        <span class="text-xs font-mono text-gray-600 dark:text-gray-300 bg-gray-100 dark:bg-gray-700 px-2 py-0.5 rounded">
            {{ \App\Livewire\ShowSpreadsheet::colIndexToLetter($selectedCol) }}{{ $selectedRow + 1 }}
        </span>
    </div>

    <div class="space-y-2 max-h-64 overflow-y-auto pr-1">
        @forelse($threads as $thread)
            <div class="border border-gray-200 dark:border-gray-700 rounded-lg p-2 {{ $thread['resolved'] ? 'opacity-70' : '' }}">
                <div class="flex items-start justify-between gap-2">
                    <div>
                        <div class="text-xs text-gray-500 dark:text-gray-400">
                            {{ $thread['user']['name'] ?? 'Unknown' }}
                            · {{ \Carbon\Carbon::parse($thread['created_at'])->diffForHumans() }}
                        </div>
                        <p class="text-sm text-gray-800 dark:text-gray-200 whitespace-pre-wrap">{{ $thread['content'] }}</p>
                    </div>
                    <div class="flex gap-1">
                        @if($canComment)
                        <button wire:click="toggleResolved({{ $thread['id'] }})"
                                class="text-xs px-2 py-0.5 rounded border border-gray-300 dark:border-gray-600 text-gray-600 dark:text-gray-300">
                            {{ $thread['resolved'] ? 'Reopen' : 'Resolve' }}
                        </button>
                        @endif
                        @if($canComment && ($thread['user_id'] ?? null) === auth()->id())
                            <button wire:click="deleteComment({{ $thread['id'] }})"
                                    class="text-xs px-2 py-0.5 rounded border border-red-300 text-red-600">Del</button>
                        @endif
                    </div>
                </div>

                @if(!empty($thread['replies']))
                    <div class="mt-2 space-y-1 pl-3 border-l-2 border-gray-200 dark:border-gray-700">
                        @foreach($thread['replies'] as $reply)
                            <div class="text-sm text-gray-700 dark:text-gray-300">
                                <span class="text-xs text-gray-500 dark:text-gray-400">{{ $reply['user']['name'] ?? 'Unknown' }}:</span>
                                {{ $reply['content'] }}
                            </div>
                        @endforeach
                    </div>
                @endif

                @if($canComment)
                <div class="mt-2 flex gap-1">
                    <input type="text"
                           wire:model.defer="replyDrafts.{{ $thread['id'] }}"
                           wire:keydown.enter="addReply({{ $thread['id'] }})"
                           placeholder="Reply (use @name to mention)"
                           class="flex-1 px-2 py-1 text-xs border border-gray-300 dark:border-gray-600 rounded bg-white dark:bg-gray-700 text-gray-900 dark:text-white">
                    <button wire:click="addReply({{ $thread['id'] }})"
                            class="px-2 py-1 text-xs bg-gray-100 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded text-gray-700 dark:text-gray-300">
                        Reply
                    </button>
                </div>
                @endif
            </div>
        @empty
            <div class="text-xs text-gray-500 dark:text-gray-400 bg-gray-50 dark:bg-gray-700/40 rounded p-2">
                No comments on this cell yet.
            </div>
        @endforelse
    </div>

    @if($canComment)
    <div class="space-y-1">
        <textarea
            wire:model.defer="newComment"
            wire:keydown.enter.prevent="addComment"
            rows="2"
            placeholder="Add a comment... Use @name to mention"
            class="w-full px-2 py-1.5 text-sm border border-gray-300 dark:border-gray-600 rounded bg-white dark:bg-gray-700 text-gray-900 dark:text-white"></textarea>
        @error('newComment')
            <p class="text-xs text-red-600">{{ $message }}</p>
        @enderror
        <div class="flex justify-end">
            <button wire:click="addComment"
                    class="px-3 py-1.5 text-xs font-medium bg-indigo-600 hover:bg-indigo-700 text-white rounded">
                Add Comment
            </button>
        </div>
    </div>
    @endif
</div>
