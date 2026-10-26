{{-- Chart Builder Panel --}}
<div>
    <!-- Saved Charts Badges -->
    @if (count($charts))
        <div class="flex flex-wrap gap-2 mb-3">
            @foreach ($charts as $chart)
                <div class="flex items-center gap-1 px-2 py-1 bg-purple-50 dark:bg-purple-900/20
                            border border-purple-200 dark:border-purple-700 rounded-full text-xs">
                    <button wire:click="renderChart({{ $chart['id'] }})"
                            class="text-purple-800 dark:text-purple-200 hover:underline font-medium">
                        📊 {{ $chart['title'] }}
                    </button>
                    @if ($canEdit)
                        <button wire:click="deleteChart({{ $chart['id'] }})"
                                class="text-purple-400 hover:text-red-500 ml-1">✕</button>
                    @endif
                </div>
            @endforeach
        </div>
    @endif

    <!-- Insert Chart Button -->
    @if ($canEdit)
    <button wire:click="openModal"
            class="flex items-center gap-2 px-3 py-1.5 text-sm bg-purple-600 hover:bg-purple-700
                   text-white rounded-lg font-medium">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                  d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
        </svg>
        Insert Chart
    </button>
    @endif

    <!-- Chart Builder Modal -->
    @if ($showModal)
        <div class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center">
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-2xl w-full max-w-lg mx-4">
                <div class="bg-gradient-to-r from-purple-600 to-pink-600 px-6 py-4 text-white rounded-t-xl flex justify-between">
                    <h2 class="text-xl font-bold">📊 Insert Chart</h2>
                    <button wire:click="closeModal" class="hover:text-purple-200">✕</button>
                </div>

                <div class="p-6 space-y-4">
                    <!-- Chart Title -->
                    <div>
                        <label for="livewire-chart-builder-title" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Chart Title</label>
                        <input id="livewire-chart-builder-title" type="text" wire:model="title" placeholder="My Chart"
                               class="w-full border border-gray-300 dark:border-gray-600 rounded-lg px-3 py-2 text-sm
                                      bg-white dark:bg-gray-700 text-gray-900 dark:text-white
                                      focus:ring-2 focus:ring-purple-500">
                        @error('title') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                    </div>

                    <!-- Chart Type -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Chart Type</label>
                        <div class="grid grid-cols-3 gap-2">
                            @foreach ($chartTypes as $value => $label)
                                <button wire:click="$set('type', '{{ $value }}')"
                                        class="p-2 text-xs rounded-lg border-2 text-center font-medium
                                               {{ $type === $value
                                                    ? 'border-purple-600 bg-purple-50 dark:bg-purple-900/30 text-purple-700 dark:text-purple-300'
                                                    : 'border-gray-200 dark:border-gray-600 text-gray-700 dark:text-gray-300 hover:border-purple-300' }}">
                                    {{ $label }}
                                </button>
                            @endforeach
                        </div>
                        @error('type') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                    </div>

                    <!-- Data Range -->
                    <div>
                        <label for="livewire-chart-builder-data-range" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                            Data Range <span class="text-gray-400 font-normal">(e.g. B1:D10)</span>
                        </label>
                        <input id="livewire-chart-builder-data-range" type="text" wire:model="data_range" placeholder="B1:D10"
                               class="w-full border border-gray-300 dark:border-gray-600 rounded-lg px-3 py-2 text-sm
                                      font-mono bg-white dark:bg-gray-700 text-gray-900 dark:text-white
                                      focus:ring-2 focus:ring-purple-500">
                        @error('data_range') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                    </div>

                    <!-- Labels Range (optional) -->
                    <div>
                        <label for="livewire-chart-builder-labels-range" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                            Labels Range <span class="text-gray-400 font-normal">(optional, e.g. A1:A10)</span>
                        </label>
                        <input id="livewire-chart-builder-labels-range" type="text" wire:model="labels_range" placeholder="A1:A10"
                               class="w-full border border-gray-300 dark:border-gray-600 rounded-lg px-3 py-2 text-sm
                                      font-mono bg-white dark:bg-gray-700 text-gray-900 dark:text-white
                                      focus:ring-2 focus:ring-purple-500">
                        @error('labels_range') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div class="bg-gray-50 dark:bg-gray-700/50 px-6 py-4 rounded-b-xl flex justify-between">
                    <button wire:click="closeModal"
                            class="px-4 py-2 text-gray-600 dark:text-gray-400 border border-gray-300 dark:border-gray-600
                                   rounded-lg hover:bg-gray-100 dark:hover:bg-gray-600 text-sm">
                        Cancel
                    </button>
                    <button wire:click="saveChart"
                            class="px-6 py-2 bg-purple-600 hover:bg-purple-700 text-white rounded-lg text-sm font-medium">
                        Insert Chart
                    </button>
                </div>
            </div>
        </div>
    @endif

    <!-- Chart Canvas Container (outside spreadsheet, full-width overlay) -->
    <div id="chartContainer"
         class="hidden fixed inset-0 bg-black/60 z-40 flex items-center justify-center"
         onclick="if(event.target===this) this.classList.add('hidden')">
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-2xl p-6 w-full max-w-3xl mx-4">
            <div class="flex justify-between items-center mb-4">
                <h3 id="chartTitle" class="text-xl font-bold text-gray-900 dark:text-white"></h3>
                <button onclick="document.getElementById('chartContainer').classList.add('hidden')"
                        class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200">✕</button>
            </div>
            <div class="relative" style="height:400px">
                <canvas id="mainChartCanvas"></canvas>
            </div>
        </div>
    </div>
</div>

<script>
    let chartInstance = null;

    document.addEventListener('livewire:initialized', () => {
        Livewire.on('render-chart', (payload) => {
            const { id, title, type, fill, data } = Array.isArray(payload) ? payload[0] : payload;

            const container = document.getElementById('chartContainer');
            const titleEl   = document.getElementById('chartTitle');
            const canvas    = document.getElementById('mainChartCanvas');

            if (!container || !canvas) return;

            titleEl.textContent = title;
            container.classList.remove('hidden');

            // Destroy previous chart instance
            if (chartInstance) {
                chartInstance.destroy();
            }

            // Apply fill to all datasets if area chart
            if (fill && data.datasets) {
                data.datasets = data.datasets.map(ds => ({ ...ds, fill: true }));
            }

            const ctx = canvas.getContext('2d');
            chartInstance = new Chart(ctx, {
                type: type,
                data: data,
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { position: 'top' },
                        title: { display: false },
                    },
                    scales: type !== 'pie' && type !== 'doughnut' ? {
                        x: { grid: { color: 'rgba(156,163,175,0.2)' } },
                        y: { grid: { color: 'rgba(156,163,175,0.2)' } },
                    } : {},
                },
            });
        });

        Livewire.on('chart-deleted', () => {
            if (chartInstance) {
                chartInstance.destroy();
                chartInstance = null;
            }
            document.getElementById('chartContainer').classList.add('hidden');
        });
    });
</script>

