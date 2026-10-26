<div>
    {{-- Close your eyes. Count to one. That is how long forever feels. --}}
<div class="space-y-4" x-data="{ recording: false }">
    @if ($scriptsEnabled)
    <div class="rounded-lg border border-gray-200 bg-white p-3">
        <div class="mb-2 flex items-center justify-between">
            <h3 class="text-sm font-semibold text-gray-900">Scripts</h3>
            <button
                type="button"
                wire:click="newScript"
                class="rounded border border-gray-300 px-2 py-1 text-xs text-gray-700 hover:bg-gray-50"
            >
                New
            </button>
        </div>

        <div class="mb-3 max-h-40 space-y-1 overflow-auto rounded border border-gray-100 p-2">
            @forelse ($scripts as $script)
                <div class="flex items-center justify-between gap-2 rounded px-2 py-1 hover:bg-gray-50">
                    <button
                        type="button"
                        wire:click="loadScript('{{ $script['id'] }}')"
                        class="truncate text-left text-xs text-gray-700"
                    >
                        {{ $script['name'] }}
                    </button>
                    <button
                        type="button"
                        wire:click="deleteScript('{{ $script['id'] }}')"
                        class="text-xs text-red-600 hover:text-red-700"
                    >
                        Delete
                    </button>
                </div>
            @empty
                <p class="text-xs text-gray-500">No scripts yet.</p>
            @endforelse
        </div>

        <div class="space-y-2">
            <input aria-label="Script name"
                type="text"
                wire:model.defer="scriptName"
                placeholder="Script name"
                class="w-full rounded border border-gray-300 px-2 py-1.5 text-sm focus:border-indigo-500 focus:outline-none"
            />

            <textarea aria-label="Scriptbody"
                wire:model.defer="scriptBody"
                rows="10"
                class="w-full rounded border border-gray-300 px-2 py-1.5 font-mono text-xs focus:border-indigo-500 focus:outline-none"
            ></textarea>

            @error('scriptName')
                <p class="text-xs text-red-600">{{ $message }}</p>
            @enderror
            @error('scriptBody')
                <p class="text-xs text-red-600">{{ $message }}</p>
            @enderror

            <div class="flex items-center gap-2">
                <button
                    type="button"
                    wire:click="saveScript"
                    class="rounded bg-gray-900 px-3 py-1.5 text-xs font-medium text-white hover:bg-black"
                >
                    Save Script
                </button>
                <button
                    type="button"
                    wire:click="dispatchRunScript"
                    class="rounded bg-indigo-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-indigo-700"
                >
                    Run in Sandbox
                </button>
            </div>
        </div>
    </div>
    @endif

    <div class="rounded-lg border border-gray-200 bg-white p-3">
        <div class="mb-2 flex items-center justify-between">
            <h3 class="text-sm font-semibold text-gray-900">Macros</h3>
            <div class="flex items-center gap-2">
                <input aria-label="Macro name"
                    type="text"
                    wire:model.defer="macroName"
                    placeholder="Macro name"
                    class="w-28 rounded border border-gray-300 px-2 py-1 text-xs focus:border-indigo-500 focus:outline-none"
                />
                <button
                    type="button"
                    x-show="!recording"
                    @click="recording = true; window.dispatchEvent(new CustomEvent('spreadsheet-start-macro-recording'))"
                    class="rounded bg-emerald-600 px-2 py-1 text-xs font-medium text-white hover:bg-emerald-700"
                >
                    Start
                </button>
                <button
                    type="button"
                    x-show="recording"
                    @click="recording = false; window.dispatchEvent(new CustomEvent('spreadsheet-stop-macro-recording'))"
                    class="rounded bg-amber-500 px-2 py-1 text-xs font-medium text-white hover:bg-amber-600"
                >
                    Stop
                </button>
            </div>
        </div>

        <div class="max-h-44 space-y-1 overflow-auto rounded border border-gray-100 p-2">
            @forelse ($scriptMacros as $macro)
                <div class="flex items-center justify-between gap-2 rounded px-2 py-1 hover:bg-gray-50">
                    <div class="min-w-0">
                        <p class="truncate text-xs font-medium text-gray-800">{{ $macro['name'] }}</p>
                        <p class="text-[11px] text-gray-500">{{ count($macro['actions'] ?? []) }} actions</p>
                    </div>
                    <div class="flex items-center gap-2">
                        <button
                            type="button"
                            wire:click="playMacro('{{ $macro['id'] }}')"
                            class="text-xs text-indigo-600 hover:text-indigo-700"
                        >
                            Play
                        </button>
                        <button
                            type="button"
                            wire:click="deleteMacro('{{ $macro['id'] }}')"
                            class="text-xs text-red-600 hover:text-red-700"
                        >
                            Delete
                        </button>
                    </div>
                </div>
            @empty
                <p class="text-xs text-gray-500">No macros yet.</p>
            @endforelse
        </div>
    </div>

    @if ($status)
        <div class="rounded border border-emerald-200 bg-emerald-50 px-2 py-1 text-xs text-emerald-700">{{ $status }}</div>
    @endif
</div>
