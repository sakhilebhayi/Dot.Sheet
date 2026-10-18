<div class="w-full h-full flex flex-col bg-white dark:bg-gray-800 rounded-lg shadow-lg">
    
    <!-- Header -->
    <div class="bg-gradient-to-r from-indigo-600 to-blue-600 px-6 py-4 text-white rounded-t-lg flex justify-between items-center">
        <div>
            <h2 class="text-xl font-bold">💬 Ask AI</h2>
            <p class="text-indigo-100 text-sm">Query your spreadsheet data naturally</p>
        </div>
        <button type="button" class="text-indigo-200 hover:text-white" wire:click="clearConversation" title="Clear conversation">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
            </svg>
        </button>
    </div>

    <!-- Conversation Area -->
    <div class="flex-1 overflow-y-auto p-4 space-y-4" id="conversationArea">
        @foreach ($conversation as $index => $msg)
            @if ($msg['role'] === 'user')
                <!-- User Message -->
                <div class="flex justify-end">
                    <div class="max-w-xs bg-blue-600 text-white rounded-lg px-4 py-2 rounded-br-none shadow">
                        <p class="text-sm">{{ $msg['message'] }}</p>
                        <p class="text-xs text-blue-100 mt-1">{{ \Illuminate\Support\Carbon::parse($msg['timestamp'] ?? now())->format('H:i') }}</p>
                    </div>
                </div>
            @else
                <!-- Assistant Message -->
                <div class="flex justify-start">
                    <div class="max-w-md bg-gray-100 dark:bg-gray-700 text-gray-900 dark:text-white rounded-lg px-4 py-2 rounded-bl-none shadow">
                        <p class="text-sm">{{ $msg['message'] }}</p>
                        
                        <!-- Show action indicator if present -->
                        @if (isset($msg['action']) && $msg['action'])
                            <div class="mt-2 text-xs bg-white/20 dark:bg-white/10 px-2 py-1 rounded">
                                💡 Action: {{ ucfirst(str_replace('_', ' ', $msg['action']['type'])) }}
                            </div>
                        @endif
                        
                        <div class="flex items-center justify-between mt-2">
                            <p class="text-xs text-gray-500 dark:text-gray-400">{{ \Illuminate\Support\Carbon::parse($msg['timestamp'] ?? now())->format('H:i') }}</p>
                            
                            <!-- Feedback buttons -->
                            <div class="flex gap-1">
                                @if (!isset($msg['feedback']))
                                    <button wire:click="provideFeedback({{ $index }}, true)" class="text-xs text-gray-400 hover:text-green-600 dark:hover:text-green-400" title="Helpful">
                                        👍
                                    </button>
                                    <button wire:click="provideFeedback({{ $index }}, false)" class="text-xs text-gray-400 hover:text-red-600 dark:hover:text-red-400" title="Not helpful">
                                        👎
                                    </button>
                                @else
                                    <span class="text-xs">{{ $msg['feedback'] === 'positive' ? '👍' : '👎' }}</span>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>
            @endif
        @endforeach

        <!-- Loading Indicator -->
        @if ($loading)
            <div class="flex justify-start">
                <div class="bg-gray-100 dark:bg-gray-700 rounded-lg px-4 py-3 rounded-bl-none">
                    <div class="flex items-center gap-2">
                        <div class="animate-spin">
                            <svg class="w-4 h-4 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path>
                            </svg>
                        </div>
                        <span class="text-sm text-gray-600 dark:text-gray-400">AI is thinking...</span>
                    </div>
                </div>
            </div>
        @endif
    </div>

    <!-- Error Display -->
    @if ($error)
        <div class="mx-4 mb-4 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded p-3 text-red-800 dark:text-red-200 text-sm">
            {{ $error }}
        </div>
    @endif

    <!-- Suggestions (shown when no conversation yet) -->
    @if (count($conversation) <= 1 && !$loading)
        <div class="px-4 pb-4">
            <p class="text-xs text-gray-600 dark:text-gray-400 mb-2">💡 Try asking:</p>
            <div class="space-y-2">
                @foreach ($suggestions as $suggestion)
                    <button wire:click="useSuggestion('{{ $suggestion }}')"
                            class="w-full text-left px-3 py-2 text-sm rounded-lg bg-indigo-50 dark:bg-indigo-900/20 
                                   text-indigo-900 dark:text-indigo-100 hover:bg-indigo-100 dark:hover:bg-indigo-900/40 transition-colors">
                        {{ $suggestion }}
                    </button>
                @endforeach
            </div>
        </div>
    @endif

    <!-- Input Area -->
    <div class="border-t border-gray-200 dark:border-gray-700 p-4 space-y-3 bg-gray-50 dark:bg-gray-700/50 rounded-b-lg">
        <div class="flex gap-2">
            <textarea wire:model="query" 
                      placeholder="Ask a question about your data..."
                      @keydown.enter.meta="$wire.submitQuery()"
                      @keydown.enter.ctrl="$wire.submitQuery()"
                      class="flex-1 px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg
                             bg-white dark:bg-gray-600 text-gray-900 dark:text-white
                             focus:ring-2 focus:ring-indigo-500 focus:border-transparent
                             resize-none" rows="2">
            </textarea>
            <div class="flex flex-col gap-2">
                <button type="button" wire:click="submitQuery" :disabled="$wire.loading"
                        class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg font-medium
                               flex items-center justify-center gap-2 disabled:opacity-50 disabled:cursor-not-allowed h-full">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"></path>
                    </svg>
                </button>
            </div>
        </div>
        <p class="text-xs text-gray-500 dark:text-gray-400">Press Cmd/Ctrl + Enter to send</p>
    </div>
</div>
