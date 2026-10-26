{{-- Named Ranges Panel --}}
<div>
    @if (count($ranges))
        <div class="flex flex-wrap gap-2 mb-3">
            @foreach ($ranges as $range)
                <div class="flex items-center gap-1 px-2 py-1 bg-teal-50 dark:bg-teal-900/20
                            border border-teal-200 dark:border-teal-700 rounded-full text-xs">
                    <span class="text-teal-800 dark:text-teal-200 font-medium" title="{{ $range['reference'] }}">
                        🏷️ {{ $range['name'] }}
                    </span>
                    <span class="text-teal-500 dark:text-teal-400 font-mono">{{ $range['reference'] }}</span>
                    @if ($canEdit)
                        <button wire:click="deleteRange({{ $range['id'] }})"
                                class="text-teal-400 hover:text-red-500 ml-1">✕</button>
                    @endif
                </div>
            @endforeach
        </div>
    @endif

    @if ($canEdit)
    <div class="flex gap-1.5">
        <input aria-label="Name" type="text" wire:model="name" placeholder="Name (e.g. Revenue)"
               class="w-28 border border-gray-300 dark:border-gray-600 rounded-lg px-2 py-1 text-xs
                      bg-white dark:bg-gray-700 text-gray-900 dark:text-white
                      focus:ring-2 focus:ring-teal-500">
        <input aria-label="A1:B10" type="text" wire:model="rangeInput" placeholder="A1:B10"
               class="w-24 border border-gray-300 dark:border-gray-600 rounded-lg px-2 py-1 text-xs font-mono
                      bg-white dark:bg-gray-700 text-gray-900 dark:text-white
                      focus:ring-2 focus:ring-teal-500">
        <button wire:click="createRange"
                class="px-2.5 py-1 text-xs bg-teal-600 hover:bg-teal-700 text-white rounded-lg font-medium">
            Add
        </button>
    </div>
    @endif
    @if ($error)
        <p class="text-red-500 text-xs mt-1">{{ $error }}</p>
    @endif
</div>
