{{-- AI Analysis Panel Sidebar --}}
<div class="w-80 bg-white dark:bg-gray-800 border-l border-gray-200 dark:border-gray-700 flex flex-col h-screen overflow-hidden">
    
    <!-- Header -->
    <div class="bg-gradient-to-r from-green-600 to-emerald-600 px-6 py-4 text-white">
        <div class="flex items-center justify-between mb-2">
            <h2 class="text-xl font-bold flex items-center gap-2">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
                </svg>
                Analysis
            </h2>
            @if ($selected_range)
                <span class="text-sm bg-white/20 px-2 py-1 rounded">{{ $selected_range }}</span>
            @endif
        </div>
        <p class="text-green-100 text-xs">Data insights, cleaning, OCR, sentiment, and workflows</p>
    </div>

    <div class="px-4 pt-3 pb-2 border-b border-gray-200 dark:border-gray-700 space-y-2">
        <label class="text-xs text-gray-600 dark:text-gray-300">Active Range</label>
        <div class="flex gap-2">
            <input
                type="text"
                wire:model.defer="selected_range"
                placeholder="A1:B20"
                class="flex-1 rounded border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 px-2 py-1.5 text-xs text-gray-900 dark:text-gray-100"
            >
            <button
                wire:click="analyzeSelectedRange"
                class="rounded bg-green-600 hover:bg-green-700 text-white text-xs px-2 py-1.5"
            >
                Analyze
            </button>
        </div>
    </div>

    <!-- Alert Messages -->
    <div class="px-4 py-2 space-y-2">
        @if ($success_message)
            <div class="bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 rounded p-3 text-green-800 dark:text-green-200 text-sm flex items-start gap-2">
                <svg class="w-4 h-4 flex-shrink-0 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                </svg>
                <div>{{ $success_message }}</div>
            </div>
        @endif

        @if ($error)
            <div class="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded p-3 text-red-800 dark:text-red-200 text-sm flex items-start gap-2">
                <svg class="w-4 h-4 flex-shrink-0 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"></path>
                </svg>
                <div>{{ $error }}</div>
            </div>
        @endif
    </div>

    <!-- Tab Navigation -->
    <div class="flex gap-1 px-4 pt-3 border-b border-gray-200 dark:border-gray-700 overflow-x-auto">
        <button wire:click="switchTab('insights')" 
                :class="{ 'text-green-600 dark:text-green-400 border-b-2 border-green-600 dark:border-green-400' : $this->active_tab === 'insights' }"
                class="px-3 py-2 text-sm font-medium text-gray-600 dark:text-gray-400 border-b-2 border-transparent hover:text-gray-900 dark:hover:text-gray-300">
            💡 Insights
        </button>
        <button wire:click="switchTab('cleaning')"
                :class="{ 'text-green-600 dark:text-green-400 border-b-2 border-green-600 dark:border-green-400' : $this->active_tab === 'cleaning' }"
                class="px-3 py-2 text-sm font-medium text-gray-600 dark:text-gray-400 border-b-2 border-transparent hover:text-gray-900 dark:hover:text-gray-300">
            🧹 Cleaning
        </button>
        <button wire:click="switchTab('charts')"
                :class="{ 'text-green-600 dark:text-green-400 border-b-2 border-green-600 dark:border-green-400' : $this->active_tab === 'charts' }"
                class="px-3 py-2 text-sm font-medium text-gray-600 dark:text-gray-400 border-b-2 border-transparent hover:text-gray-900 dark:hover:text-gray-300">
            📊 Charts
        </button>
        <button wire:click="switchTab('sentiment')"
                :class="{ 'text-green-600 dark:text-green-400 border-b-2 border-green-600 dark:border-green-400' : $this->active_tab === 'sentiment' }"
                class="px-3 py-2 text-sm font-medium text-gray-600 dark:text-gray-400 border-b-2 border-transparent hover:text-gray-900 dark:hover:text-gray-300 whitespace-nowrap">
            🙂 Sentiment
        </button>
        <button wire:click="switchTab('ocr')"
                :class="{ 'text-green-600 dark:text-green-400 border-b-2 border-green-600 dark:border-green-400' : $this->active_tab === 'ocr' }"
                class="px-3 py-2 text-sm font-medium text-gray-600 dark:text-gray-400 border-b-2 border-transparent hover:text-gray-900 dark:hover:text-gray-300 whitespace-nowrap">
            🖼 OCR
        </button>
        <button wire:click="switchTab('workflows')"
                :class="{ 'text-green-600 dark:text-green-400 border-b-2 border-green-600 dark:border-green-400' : $this->active_tab === 'workflows' }"
                class="px-3 py-2 text-sm font-medium text-gray-600 dark:text-gray-400 border-b-2 border-transparent hover:text-gray-900 dark:hover:text-gray-300 whitespace-nowrap">
            ⚙ Workflows
        </button>
    </div>

    <!-- Tab Content -->
    <div class="flex-1 overflow-y-auto p-4 space-y-4">

        <!-- Insights Tab -->
        @if ($active_tab === 'insights')
            @if ($loading)
                <div class="flex items-center justify-center py-8">
                    <div class="text-center">
                        <div class="animate-spin inline-block">
                            <svg class="w-8 h-8 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path>
                            </svg>
                        </div>
                        <p class="text-sm text-gray-600 dark:text-gray-400 mt-2">Analyzing data...</p>
                    </div>
                </div>
            @elseif ($insights)
                <div class="space-y-3">
                    <div class="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg p-4">
                        <p class="text-sm text-blue-900 dark:text-blue-100">{{ $insights['summary'] ?? 'No insights available' }}</p>
                    </div>
                    <p class="text-xs text-gray-500 dark:text-gray-400">Generated at {{ $insights['generated_at'] ?? '' }}</p>
                </div>
            @else
                <div class="bg-gray-50 dark:bg-gray-700/50 border border-gray-200 dark:border-gray-600 rounded-lg p-4 text-center">
                    <p class="text-sm text-gray-600 dark:text-gray-400">Select a data range to analyze</p>
                </div>
            @endif
        @endif

        <!-- Cleaning Tab -->
        @if ($active_tab === 'cleaning')
            @if ($loading)
                <div class="flex items-center justify-center py-8">
                    <div class="text-center">
                        <div class="animate-spin inline-block">
                            <svg class="w-8 h-8 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path>
                            </svg>
                        </div>
                        <p class="text-sm text-gray-600 dark:text-gray-400 mt-2">Analyzing data...</p>
                    </div>
                </div>
            @elseif ($cleaning_suggestions)
                <div class="space-y-3">
                    @foreach ($cleaning_suggestions as $suggestion)
                        <div class="bg-yellow-50 dark:bg-yellow-900/20 border border-yellow-200 dark:border-yellow-800 rounded-lg p-3 space-y-2">
                            <p class="font-medium text-sm text-yellow-900 dark:text-yellow-100">{{ $suggestion['issue'] }}</p>
                            <p class="text-xs text-yellow-800 dark:text-yellow-200">{{ $suggestion['description'] }}</p>
                            <button class="text-xs bg-yellow-600 hover:bg-yellow-700 text-white px-2 py-1 rounded">
                                {{ $suggestion['action'] }}
                            </button>
                        </div>
                    @endforeach
                    
                    @if ($selected_range && count($cleaning_suggestions) > 0)
                        <button wire:click="cleanData" class="w-full px-4 py-2 bg-green-600 hover:bg-green-700 text-white rounded-lg font-medium text-sm"
                                :disabled="$loading">
                            Apply AI Cleaning Fixes
                        </button>
                    @endif
                </div>
            @else
                <div class="bg-gray-50 dark:bg-gray-700/50 border border-gray-200 dark:border-gray-600 rounded-lg p-4 text-center">
                    <p class="text-sm text-gray-600 dark:text-gray-400">No cleaning suggestions found</p>
                </div>
            @endif
        @endif

        <!-- Charts Tab -->
        @if ($active_tab === 'charts')
            @if ($loading)
                <div class="flex items-center justify-center py-8">
                    <div class="text-center">
                        <div class="animate-spin inline-block">
                            <svg class="w-8 h-8 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path>
                            </svg>
                        </div>
                        <p class="text-sm text-gray-600 dark:text-gray-400 mt-2">Analyzing data...</p>
                    </div>
                </div>
            @elseif ($chart_recommendations)
                <div class="space-y-3">
                    <p class="text-sm font-medium text-gray-700 dark:text-gray-300">Recommended chart types:</p>
                    @foreach ($chart_recommendations as $rec)
                        <button wire:click="generateChart('{{ $rec['type'] }}')"
                                class="w-full text-left p-3 bg-gradient-to-r from-purple-50 dark:from-purple-900/20 to-pink-50 dark:to-pink-900/20 
                                       border border-purple-200 dark:border-purple-800 rounded-lg hover:shadow-md transition-shadow">
                            <p class="font-medium text-sm text-purple-900 dark:text-purple-100 capitalize">{{ $rec['type'] }} Chart</p>
                            <p class="text-xs text-purple-700 dark:text-purple-300">{{ $rec['reason'] }}</p>
                        </button>
                    @endforeach
                </div>
            @else
                <div class="bg-gray-50 dark:bg-gray-700/50 border border-gray-200 dark:border-gray-600 rounded-lg p-4 text-center">
                    <p class="text-sm text-gray-600 dark:text-gray-400">No chart recommendations available</p>
                </div>
            @endif
        @endif

        <!-- Sentiment Tab -->
        @if ($active_tab === 'sentiment')
            <div class="space-y-3">
                <button
                    wire:click="runSentimentAnalysis"
                    class="w-full rounded bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium px-3 py-2"
                >
                    Analyze Sentiment For Range
                </button>

                @if (!empty($sentiment_result))
                    <div class="rounded-lg border border-indigo-200 dark:border-indigo-700 bg-indigo-50 dark:bg-indigo-900/20 p-3">
                        <p class="text-sm font-semibold text-indigo-900 dark:text-indigo-100 capitalize">{{ $sentiment_result['label'] ?? 'neutral' }}</p>
                        <p class="text-xs text-indigo-700 dark:text-indigo-300">Score: {{ $sentiment_result['score'] ?? 0 }}</p>
                        <p class="text-xs text-indigo-700 dark:text-indigo-300">Samples: {{ $sentiment_result['samples'] ?? 0 }}</p>
                    </div>
                @else
                    <div class="bg-gray-50 dark:bg-gray-700/50 border border-gray-200 dark:border-gray-600 rounded-lg p-4 text-center">
                        <p class="text-sm text-gray-600 dark:text-gray-400">Run sentiment analysis on a text-heavy range.</p>
                    </div>
                @endif
            </div>
        @endif

        <!-- OCR Tab -->
        @if ($active_tab === 'ocr')
            <div class="space-y-3">
                <input
                    type="file"
                    wire:model="ocrImage"
                    accept="image/*"
                    class="w-full text-xs text-gray-700 dark:text-gray-300"
                >
                @error('ocrImage')
                    <p class="text-xs text-red-600">{{ $message }}</p>
                @enderror
                <button
                    wire:click="extractOcrText"
                    class="w-full rounded bg-amber-500 hover:bg-amber-600 text-white text-sm font-medium px-3 py-2"
                >
                    Extract Text (OCR)
                </button>

                @if ($ocrText)
                    <textarea
                        wire:model="ocrText"
                        rows="7"
                        class="w-full rounded border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 px-2 py-1.5 text-xs text-gray-900 dark:text-gray-100"
                    ></textarea>
                    <button
                        wire:click="insertOcrTextIntoSelectedCell"
                        class="w-full rounded bg-green-600 hover:bg-green-700 text-white text-sm font-medium px-3 py-2"
                    >
                        Insert OCR Text Into Selected Cell
                    </button>
                @endif
            </div>
        @endif

        <!-- Workflows Tab -->
        @if ($active_tab === 'workflows')
            <div class="space-y-3">
                <div class="space-y-2 rounded border border-gray-200 dark:border-gray-700 p-3">
                    <input
                        type="text"
                        wire:model.defer="workflow_name"
                        placeholder="Rule name"
                        class="w-full rounded border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 px-2 py-1.5 text-xs text-gray-900 dark:text-gray-100"
                    >
                    <div class="grid grid-cols-2 gap-2">
                        <input
                            type="text"
                            wire:model.defer="workflow_column"
                            placeholder="Column"
                            class="rounded border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 px-2 py-1.5 text-xs text-gray-900 dark:text-gray-100"
                        >
                        <select
                            wire:model.defer="workflow_operator"
                            class="rounded border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 px-2 py-1.5 text-xs text-gray-900 dark:text-gray-100"
                        >
                            <option value="<">&lt;</option>
                            <option value="<=">&lt;=</option>
                            <option value=">">&gt;</option>
                            <option value=">=">&gt;=</option>
                            <option value="==">==</option>
                            <option value="!=">!=</option>
                        </select>
                    </div>
                    <input
                        type="text"
                        wire:model.defer="workflow_value"
                        placeholder="Trigger value"
                        class="w-full rounded border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 px-2 py-1.5 text-xs text-gray-900 dark:text-gray-100"
                    >
                    <select
                        wire:model.defer="workflow_action"
                        class="w-full rounded border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 px-2 py-1.5 text-xs text-gray-900 dark:text-gray-100"
                    >
                        <option value="email_owner">Email owner</option>
                        <option value="highlight_cell">Highlight cell</option>
                    </select>
                    <div class="space-y-1">
                        <label class="flex items-center gap-2 text-xs text-gray-700 dark:text-gray-200">
                            <input type="checkbox" wire:model.defer="workflow_notify_email" class="rounded border-gray-300 text-green-600 focus:ring-green-500">
                            Email notification
                        </label>
                        <label class="flex items-center gap-2 text-xs text-gray-700 dark:text-gray-200">
                            <input type="checkbox" wire:model.defer="workflow_notify_database" class="rounded border-gray-300 text-green-600 focus:ring-green-500">
                            Database notification
                        </label>
                    </div>
                    <button
                        wire:click="addWorkflowRule"
                        class="w-full rounded bg-gray-900 hover:bg-black text-white text-sm font-medium px-3 py-2"
                    >
                        Save Workflow Rule
                    </button>
                </div>

                @if (!empty($workflow_rules))
                    <div class="space-y-2">
                        @foreach ($workflow_rules as $rule)
                            <div class="rounded border border-gray-200 dark:border-gray-700 p-3">
                                <p class="text-sm font-semibold text-gray-800 dark:text-gray-100">{{ $rule['name'] }}</p>
                                <p class="text-xs text-gray-600 dark:text-gray-300">If {{ $rule['column'] }} {{ $rule['operator'] }} {{ $rule['value'] }} then {{ $rule['action'] }}</p>
                                <p class="text-[11px] text-gray-500 dark:text-gray-400 mt-1">
                                    Notify: {{ implode(', ', $rule['notify_channels'] ?? ['email']) }}
                                </p>
                                <button
                                    wire:click="deleteWorkflowRule('{{ $rule['id'] }}')"
                                    class="mt-2 text-xs text-red-600 hover:text-red-700"
                                >
                                    Delete
                                </button>
                            </div>
                        @endforeach
                    </div>
                @else
                    <div class="bg-gray-50 dark:bg-gray-700/50 border border-gray-200 dark:border-gray-600 rounded-lg p-4 text-center">
                        <p class="text-sm text-gray-600 dark:text-gray-400">No automated workflows configured yet.</p>
                    </div>
                @endif
            </div>
        @endif

    </div>

    <!-- Footer Info -->
    <div class="border-t border-gray-200 dark:border-gray-700 p-4 text-xs text-gray-500 dark:text-gray-400">
        <p>Use range notation like A1:B20 for AI operations.</p>
    </div>
</div>
