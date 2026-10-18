{{-- Pivot Table Builder Panel --}}
<div>
    @if (count($pivots))
        <div class="flex flex-wrap gap-2 mb-3">
            @foreach ($pivots as $pivot)
                <div class="flex items-center gap-1 px-2 py-1 bg-indigo-50 dark:bg-indigo-900/20
                            border border-indigo-200 dark:border-indigo-700 rounded-full text-xs">
                    <button wire:click="openPivot({{ $pivot['id'] }})"
                            class="text-indigo-800 dark:text-indigo-200 hover:underline font-medium">
                        🧮 {{ $pivot['title'] }}
                    </button>
                    <button wire:click="deletePivot({{ $pivot['id'] }})"
                            class="text-indigo-400 hover:text-red-500 ml-1">✕</button>
                </div>
            @endforeach
        </div>
    @endif

    <button wire:click="openModal"
            class="flex items-center gap-2 px-3 py-1.5 text-sm bg-indigo-600 hover:bg-indigo-700
                   text-white rounded-lg font-medium">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                  d="M4 6h16M4 10h16M4 14h7m-7 4h7m5-4v6m0-6l-3 3m3-3l3 3"/>
        </svg>
        New Pivot Table
    </button>

    <!-- Pivot Builder Modal -->
    @if ($showModal)
        <div class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center">
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-2xl w-full max-w-lg mx-4">
                <div class="bg-gradient-to-r from-indigo-600 to-blue-600 px-6 py-4 text-white rounded-t-xl flex justify-between">
                    <h2 class="text-xl font-bold">🧮 New Pivot Table</h2>
                    <button wire:click="closeModal" class="hover:text-indigo-200">✕</button>
                </div>

                <div class="p-6 space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Title</label>
                        <input type="text" wire:model="title"
                               class="w-full border border-gray-300 dark:border-gray-600 rounded-lg px-3 py-2 text-sm
                                      bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:ring-2 focus:ring-indigo-500">
                        @error('title') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                            Source Range <span class="text-gray-400 font-normal">(first row = headers, e.g. A1:D50)</span>
                        </label>
                        <input type="text" wire:model="source_range" placeholder="A1:D50"
                               class="w-full border border-gray-300 dark:border-gray-600 rounded-lg px-3 py-2 text-sm font-mono
                                      bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:ring-2 focus:ring-indigo-500">
                        @error('source_range') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                Rows <span class="text-gray-400 font-normal">(column letter)</span>
                            </label>
                            <input type="text" wire:model="row_field" placeholder="A"
                                   class="w-full border border-gray-300 dark:border-gray-600 rounded-lg px-3 py-2 text-sm font-mono
                                          bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:ring-2 focus:ring-indigo-500">
                            @error('row_field') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                Columns <span class="text-gray-400 font-normal">(optional)</span>
                            </label>
                            <input type="text" wire:model="column_field" placeholder="B"
                                   class="w-full border border-gray-300 dark:border-gray-600 rounded-lg px-3 py-2 text-sm font-mono
                                          bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:ring-2 focus:ring-indigo-500">
                            @error('column_field') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                Values <span class="text-gray-400 font-normal">(column letter)</span>
                            </label>
                            <input type="text" wire:model="value_field" placeholder="C"
                                   class="w-full border border-gray-300 dark:border-gray-600 rounded-lg px-3 py-2 text-sm font-mono
                                          bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:ring-2 focus:ring-indigo-500">
                            @error('value_field') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Aggregation</label>
                            <select wire:model="aggregation"
                                    class="w-full border border-gray-300 dark:border-gray-600 rounded-lg px-3 py-2 text-sm
                                           bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:ring-2 focus:ring-indigo-500">
                                <option value="sum">Sum</option>
                                <option value="count">Count</option>
                                <option value="average">Average</option>
                                <option value="min">Min</option>
                                <option value="max">Max</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="bg-gray-50 dark:bg-gray-700/50 px-6 py-4 rounded-b-xl flex justify-between">
                    <button wire:click="closeModal"
                            class="px-4 py-2 text-gray-600 dark:text-gray-400 border border-gray-300 dark:border-gray-600
                                   rounded-lg hover:bg-gray-100 dark:hover:bg-gray-600 text-sm">
                        Cancel
                    </button>
                    <button wire:click="savePivot"
                            class="px-6 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg text-sm font-medium">
                        Create Pivot Table
                    </button>
                </div>
            </div>
        </div>
    @endif

    <!-- Pivot Result Overlay -->
    @if ($openPivotId && $result)
        <div class="fixed inset-0 bg-black/60 z-40 flex items-center justify-center"
             wire:click.self="closePivot">
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-2xl p-6 w-full max-w-3xl mx-4 max-h-[80vh] overflow-auto">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="text-lg font-bold text-gray-900 dark:text-white">🧮 Pivot Table</h3>
                    <button wire:click="closePivot" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200">✕</button>
                </div>

                @if (empty($result['rowLabels']))
                    <p class="text-sm text-gray-500 dark:text-gray-400">No data found in the source range.</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm border-collapse">
                            <thead>
                                <tr>
                                    <th class="border border-gray-200 dark:border-gray-600 px-3 py-2 bg-gray-100 dark:bg-gray-700 text-left"></th>
                                    @foreach ($result['columnLabels'] as $columnLabel)
                                        <th class="border border-gray-200 dark:border-gray-600 px-3 py-2 bg-gray-100 dark:bg-gray-700 text-right font-semibold">
                                            {{ $columnLabel }}
                                        </th>
                                    @endforeach
                                    <th class="border border-gray-200 dark:border-gray-600 px-3 py-2 bg-gray-200 dark:bg-gray-600 text-right font-bold">Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($result['rowLabels'] as $rowLabel)
                                    <tr>
                                        <td class="border border-gray-200 dark:border-gray-600 px-3 py-2 bg-gray-50 dark:bg-gray-700/50 font-medium">
                                            {{ $rowLabel }}
                                        </td>
                                        @foreach ($result['columnLabels'] as $columnLabel)
                                            <td class="border border-gray-200 dark:border-gray-600 px-3 py-2 text-right text-gray-700 dark:text-gray-300">
                                                {{ number_format($result['matrix'][$rowLabel][$columnLabel] ?? 0, 2) }}
                                            </td>
                                        @endforeach
                                        <td class="border border-gray-200 dark:border-gray-600 px-3 py-2 text-right font-semibold bg-gray-50 dark:bg-gray-700/50">
                                            {{ number_format($result['rowTotals'][$rowLabel] ?? 0, 2) }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                            <tfoot>
                                <tr>
                                    <td class="border border-gray-200 dark:border-gray-600 px-3 py-2 bg-gray-200 dark:bg-gray-600 font-bold">Total</td>
                                    @foreach ($result['columnLabels'] as $columnLabel)
                                        <td class="border border-gray-200 dark:border-gray-600 px-3 py-2 text-right font-semibold bg-gray-200 dark:bg-gray-600">
                                            {{ number_format($result['columnTotals'][$columnLabel] ?? 0, 2) }}
                                        </td>
                                    @endforeach
                                    <td class="border border-gray-200 dark:border-gray-600 px-3 py-2 text-right font-bold bg-gray-300 dark:bg-gray-500">
                                        {{ number_format($result['grandTotal'] ?? 0, 2) }}
                                    </td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                @endif
            </div>
        </div>
    @endif
</div>
