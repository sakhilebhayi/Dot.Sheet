<div class="fixed inset-0 bg-black/50 hidden" id="formulaModalBackdrop" @click="$el.classList.add('hidden')" @keydown.window.escape="$el.classList.add('hidden')">
    <div class="fixed inset-0 flex items-center justify-center">
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow-2xl max-w-2xl w-full mx-4"
             @click.stop
             @show-formula-modal.window="$el.parentElement.classList.remove('hidden')"
             @hide-formula-modal.window="$el.parentElement.classList.add('hidden')">
            
            <!-- Header -->
            <div class="bg-gradient-to-r from-blue-600 to-purple-600 px-6 py-4 text-white rounded-t-lg flex justify-between items-center">
                <div>
                    <h2 class="text-2xl font-bold">✨ AI Formula Generator</h2>
                    <p class="text-blue-100 text-sm">Generate formulas using AI - Cell {{ $cell_reference }}</p>
                </div>
                <button type="button" class="text-white hover:text-blue-200"
                        @click="$el.closest('.fixed').classList.add('hidden')">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>

            <!-- Content -->
            <div class="p-6 space-y-6">
                <!-- Description Input -->
                <div>
                    <label for="description" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                        Describe what you want the formula to do:
                    </label>
                    <textarea id="description" wire:model="description" placeholder="e.g., Sum all values in column A"
                              class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg
                                     bg-white dark:bg-gray-700 text-gray-900 dark:text-white
                                     focus:ring-2 focus:ring-blue-500 focus:border-transparent
                                     resize-none" rows="4">
                    </textarea>
                    @error('description')
                        <p class="text-red-600 text-sm mt-1">{{ $message }}</p>
                    @enderror
                </div>

                <!-- AI Service Status -->
                <div class="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg p-3">
                    <p class="text-sm text-blue-800 dark:text-blue-200">
                        💡 <strong>Tip:</strong> Be specific! Instead of "sum", try "sum all sales values in column A from rows 1 to 10"
                    </p>
                </div>

                <!-- Error Display -->
                @if ($error)
                    <div class="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg p-4">
                        <p class="text-red-800 dark:text-red-200 text-sm">
                            <strong>Error:</strong> {{ $error }}
                        </p>
                    </div>
                @endif

                <!-- Generated Formula Display -->
                @if ($generated_formula)
                    <div class="bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 rounded-lg p-4 space-y-3">
                        <p class="text-green-800 dark:text-green-200 font-medium">✅ Formula Generated:</p>
                        <div class="bg-white dark:bg-gray-700 p-3 rounded font-mono text-sm text-gray-900 dark:text-white break-all">
                            {{ $generated_formula }}
                        </div>
                        @if ($ai_suggestion)
                            <p class="text-sm text-green-700 dark:text-green-300">
                                {{ $ai_suggestion }}
                            </p>
                        @endif
                    </div>
                @endif

                <!-- Loading State -->
                @if ($loading)
                    <div class="flex items-center justify-center py-6">
                        <div class="flex items-center gap-3">
                            <div class="animate-spin">
                                <svg class="w-5 h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path>
                                </svg>
                            </div>
                            <span class="text-gray-700 dark:text-gray-300">Generating formula with AI...</span>
                        </div>
                    </div>
                @endif
            </div>

            <!-- Footer -->
            <div class="bg-gray-100 dark:bg-gray-700 px-6 py-4 rounded-b-lg flex justify-between gap-4">
                <button type="button" class="px-4 py-2 text-gray-700 dark:text-gray-300 border border-gray-300 dark:border-gray-600 
                                           rounded-lg hover:bg-gray-200 dark:hover:bg-gray-600 font-medium"
                        wire:click="resetForm"
                        @click="$el.closest('.fixed').classList.add('hidden')">
                    Cancel
                </button>

                <div class="flex gap-4">
                    @if ($generated_formula)
                        <button type="button" class="px-6 py-2 bg-green-600 hover:bg-green-700 text-white rounded-lg font-medium
                                                   flex items-center gap-2"
                                wire:click="insertFormula"
                                @click="$el.closest('.fixed').classList.add('hidden')">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                            </svg>
                            Insert Formula
                        </button>
                    @else
                        <button type="button" wire:click="generateFormula" :disabled="$wire.loading"
                                class="px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium
                                       flex items-center gap-2 disabled:opacity-50"
                                :class="{ 'opacity-50 cursor-not-allowed': $wire.loading }">
                            <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
                                <path d="M11 3a1 1 0 10-2 0v1a1 1 0 102 0V3zM15.657 5.343a1 1 0 00-1.414-1.414l-.707.707a1 1 0 001.414 1.414l.707-.707zM18 10a1 1 0 01-1 1h-1a1 1 0 110-2h1a1 1 0 011 1zM15.657 14.657a1 1 0 001.414-1.414l-.707-.707a1 1 0 00-1.414 1.414l.707.707zM11 17a1 1 0 110-2v1a1 1 0 01-2 0v-1a1 1 0 112 0v1zM5.343 15.657a1 1 0 01-1.414-1.414l.707-.707a1 1 0 011.414 1.414l-.707.707zM2 10a1 1 0 011-1h1a1 1 0 110 2H3a1 1 0 01-1-1zM5.343 4.343a1 1 0 011.414-1.414l-.707-.707a1 1 0 00-1.414 1.414l.707.707z"></path>
                            </svg>
                            Generate Formula
                        </button>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>
