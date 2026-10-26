<div class="w-full h-screen flex flex-col bg-gray-50 dark:bg-gray-900">
    @if ($structureNotice !== '')
        <div role="status" class="flex items-center justify-between gap-3 bg-amber-100 dark:bg-amber-900/40 text-amber-900 dark:text-amber-100 border-b border-amber-300 dark:border-amber-700 px-4 py-2 text-sm">
            <span>{{ $structureNotice }}</span>
            <button type="button" wire:click="dismissStructureNotice" class="text-xs underline">Dismiss</button>
        </div>
    @endif
    <!-- Header Bar -->
    <div class="bg-white dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700 shadow-sm">
        <!-- Top Row: Name + Actions -->
        <div class="flex items-center justify-between px-4 py-3">
            <div>
                <h1 class="text-xl font-bold text-gray-900 dark:text-white leading-tight">
                    {{ $spreadsheet->name }}
                </h1>
                <p class="text-xs text-gray-500 dark:text-gray-400">
                    Last edited {{ $spreadsheet->updated_at->diffForHumans() }}
                </p>
                <div id="activeUsers" wire:ignore class="mt-1 flex items-center gap-1"></div>
            </div>
            <div class="flex items-center gap-2">
                <!-- Formula Bar -->
                <div class="flex gap-1.5 items-center">
                    <span class="text-xs font-mono bg-gray-100 dark:bg-gray-700 px-2 py-1.5 rounded border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 min-w-[48px] text-center"
                          id="cellRef">A1</span>
                    <input aria-label="Enter formula or value" type="text" id="formulaInput" placeholder="Enter formula or value..."
                        class="w-64 px-3 py-1.5 border border-gray-300 dark:border-gray-600 rounded-lg text-sm
                               bg-white dark:bg-gray-700 text-gray-900 dark:text-white
                               focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                    <button class="px-3 py-1.5 bg-green-600 hover:bg-green-700 text-white rounded-lg text-sm font-medium"
                            onclick="saveCurrentCell()">Save</button>
                </div>

                <!-- Separator -->
                <div class="w-px h-6 bg-gray-300 dark:bg-gray-600 mx-1"></div>

                <!-- Import / Export -->
                <div class="flex gap-1.5">
                    @if ($canEdit)
                    <label class="px-2.5 py-1.5 bg-gray-100 hover:bg-gray-200 dark:bg-gray-700 dark:hover:bg-gray-600
                                  text-gray-700 dark:text-gray-300 rounded-lg text-xs font-medium cursor-pointer border
                                  border-gray-300 dark:border-gray-600 flex items-center gap-1" title="Import CSV">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                  d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/>
                        </svg>
                        Import
                        <input type="file" accept=".csv,.xlsx,.xls" class="hidden"
                               onchange="handleImport(event)">
                    </label>
                    @endif
                    <div class="relative" x-data="{ open: false }">
                        <button @click="open = !open"
                                class="px-2.5 py-1.5 bg-gray-100 hover:bg-gray-200 dark:bg-gray-700 dark:hover:bg-gray-600
                                       text-gray-700 dark:text-gray-300 rounded-lg text-xs font-medium border
                                       border-gray-300 dark:border-gray-600 flex items-center gap-1">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                      d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                            </svg>
                            Export ▾
                        </button>
                        <div x-show="open" @click.away="open = false"
                             class="absolute right-0 top-full mt-1 bg-white dark:bg-gray-800 border border-gray-200
                                    dark:border-gray-700 rounded-lg shadow-lg z-20 py-1 min-w-[120px]">
                            <a href="/spreadsheets/{{ $spreadsheet->id }}/export/csv?sheet_tab_id={{ $activeSheetTabId }}"
                               class="block px-4 py-2 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700">
                                Export CSV
                            </a>
                            <a href="/spreadsheets/{{ $spreadsheet->id }}/export/excel?sheet_tab_id={{ $activeSheetTabId }}"
                               class="block px-4 py-2 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700">
                                Export Excel
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Separator -->
                <div class="w-px h-6 bg-gray-300 dark:bg-gray-600 mx-1"></div>

                <!-- AI Actions -->
                <div class="flex gap-1.5">
                    <button onclick="startSpreadsheetTour()"
                            class="px-2.5 py-1.5 bg-cyan-600 hover:bg-cyan-700 text-white rounded-lg text-xs font-medium
                                   flex items-center gap-1" title="Getting Started Tour">
                        🧭 Tour
                    </button>
                    <button onclick="toggleHelpModal(true)"
                            class="px-2.5 py-1.5 bg-slate-600 hover:bg-slate-700 text-white rounded-lg text-xs font-medium
                                   flex items-center gap-1" title="Help">
                        ❔ Help
                    </button>
                    <button onclick="toggleShortcutsModal(true)"
                            class="px-2.5 py-1.5 bg-gray-700 hover:bg-gray-800 text-white rounded-lg text-xs font-medium
                                   flex items-center gap-1" title="Keyboard Shortcuts">
                        ⌨ Shortcuts
                    </button>
                    <button onclick="Livewire.dispatch('open-version-history')"
                            class="px-2.5 py-1.5 bg-amber-500 hover:bg-amber-600 text-white rounded-lg text-xs font-medium
                                   flex items-center gap-1" title="Version History">
                        🕘 History
                    </button>
                    @if ($canEdit)
                    <button onclick="document.dispatchEvent(new CustomEvent('open-formula-modal'))"
                            class="px-2.5 py-1.5 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-xs font-medium
                                   flex items-center gap-1" title="AI Formula">
                        ✨ Formula
                    </button>
                    @endif
                    <button onclick="document.getElementById('analysisPanel').classList.toggle('hidden')"
                            class="px-2.5 py-1.5 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg text-xs font-medium
                                   flex items-center gap-1" title="AI Analyze">
                        📊 Analyze
                    </button>
                    <button onclick="document.getElementById('nlqModal').classList.remove('hidden')"
                            class="px-2.5 py-1.5 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg text-xs font-medium
                                   flex items-center gap-1" title="Ask AI">
                        💬 Ask AI
                    </button>
                </div>
            </div>
        </div>

        <!-- Formatting Toolbar Row -->
        @if ($canEdit)
            @livewire('spreadsheet-toolbar', ['spreadsheet_id' => $spreadsheet->id])
        @endif
    </div>

    <!-- Main Content Area -->
    <div class="flex-1 flex gap-0 overflow-hidden">
        <!-- Spreadsheet Grid Container -->
        <div class="flex-1 flex flex-col bg-white dark:bg-gray-800 min-w-0">
            <!-- Row Header with Column Letters -->
            <div class="flex border-b border-gray-200 dark:border-gray-700">
                <!-- Corner (Row/Col selector) -->
                <div class="w-12 h-8 bg-gray-100 dark:bg-gray-700 border-r border-gray-200 dark:border-gray-600
                            flex items-center justify-center flex-shrink-0">
                    <button class="w-full h-full text-xs font-bold text-gray-600 dark:text-gray-400
                                   hover:bg-gray-200 dark:hover:bg-gray-600"
                            title="Select all cells">
                        ⊞
                    </button>
                </div>

                <!-- Column Headers -->
                <div class="flex-1 overflow-x-auto" id="colHeaderScroll">
                    <div class="flex">
                        @for ($col = $scrollColOffset; $col < min($scrollColOffset + $viewportCols, $totalCols); $col++)
                            @continue(in_array($col, $hiddenCols, true))
                            @php $colPixelWidth = (int) ($colWidths[$col] ?? $colWidth); @endphp
                            <div class="h-8 relative bg-gray-100 dark:bg-gray-700 border-r border-gray-200 dark:border-gray-600
                                        flex items-center justify-center flex-shrink-0 select-none">
                                <div style="width: {{ $colPixelWidth }}px" class="flex items-center justify-center">
                                <span class="text-xs font-bold text-gray-600 dark:text-gray-400">
                                    {{ \App\Livewire\ShowSpreadsheet::colIndexToLetter($col) }}
                                </span>
                                </div>
                                <div class="absolute right-0 top-0 h-full w-1 cursor-col-resize bg-transparent hover:bg-blue-400/30"
                                     data-col-resize-handle
                                     data-col="{{ $col }}"
                                     data-width="{{ $colPixelWidth }}"></div>
                            </div>
                        @endfor
                    </div>
                </div>
            </div>

            <!-- Grid Container with Virtual Scrolling -->
            <div class="flex-1 flex overflow-hidden">
                <!-- Row Headers -->
                <div class="w-12 flex-shrink-0 bg-gray-100 dark:bg-gray-700 border-r border-gray-200 dark:border-gray-600
                            overflow-y-auto" id="rowHeaderScroll">
                    @php
                        $visibleEndRow = min($scrollRowOffset + $viewportRows, $totalRows);
                        $topSpacerRows = $scrollRowOffset;
                        $bottomSpacerRows = max(0, $totalRows - $visibleEndRow);
                    @endphp

                    @if ($topSpacerRows > 0)
                        <div style="height: {{ $topSpacerRows * $rowHeight }}px"></div>
                    @endif

                    @for ($row = $scrollRowOffset; $row < min($scrollRowOffset + $viewportRows, $totalRows); $row++)
                        @continue(in_array($row, $hiddenRows, true))
                        @php $rowPixelHeight = (int) ($rowHeights[$row] ?? $rowHeight); @endphp
                        <div class="relative bg-gray-100 dark:bg-gray-700 border-b border-gray-200 dark:border-gray-600
                                    flex items-center justify-center flex-shrink-0 select-none">
                            <div style="height: {{ $rowPixelHeight }}px" class="w-full flex items-center justify-center">
                            <span class="text-xs font-bold text-gray-600 dark:text-gray-400">
                                {{ $row + 1 }}
                            </span>
                            </div>
                            <div class="absolute left-0 bottom-0 h-1 w-full cursor-row-resize bg-transparent hover:bg-blue-400/30"
                                 data-row-resize-handle
                                 data-row="{{ $row }}"
                                 data-height="{{ $rowPixelHeight }}"></div>
                        </div>
                    @endfor

                    @if ($bottomSpacerRows > 0)
                        <div style="height: {{ $bottomSpacerRows * $rowHeight }}px"></div>
                    @endif
                </div>

                <!-- Grid Content with Virtual Scrolling -->
                <div class="flex-1 overflow-auto relative" id="gridContainer" 
                     @scroll="$wire.handleScroll(Math.floor($el.scrollTop / {{ $rowHeight }}), Math.floor($el.scrollLeft / {{ $colWidth }}))"
                     x-data="gridController()"
                     @keydown.arrow-up.prevent="navigate('up')"
                     @keydown.arrow-down.prevent="navigate('down')"
                     @keydown.arrow-left.prevent="navigate('left')"
                     @keydown.arrow-right.prevent="navigate('right')"
                     @keydown.enter.prevent="startEditing()"
                     @keydown.escape.prevent="escapeEditing()"
                     tabindex="0">
                    
                    <table class="border-collapse border border-gray-200 dark:border-gray-600">
                        <tbody>
                            @if ($topSpacerRows > 0)
                                <tr style="height: {{ $topSpacerRows * $rowHeight }}px">
                                    <td colspan="{{ max(1, $viewportCols) }}" class="p-0 border-0"></td>
                                </tr>
                            @endif

                            @foreach ($viewportData as $row => $cells)
                                @continue(in_array($row, $hiddenRows, true))
                                @php $rowPixelHeight = (int) ($rowHeights[$row] ?? $rowHeight); @endphp
                                <tr style="height: {{ $rowPixelHeight }}px">
                                    @foreach ($cells as $col => $cell)
                                        @continue(in_array($col, $hiddenCols, true))
                                        @php
                                            $colPixelWidth = (int) ($colWidths[$col] ?? $colWidth);
                                            $fmt = $cell['formatting'] ?? [];
                                            $fontWeight = !empty($fmt['bold']) ? '700' : '400';
                                            $fontStyle = !empty($fmt['italic']) ? 'italic' : 'normal';
                                            $decorations = [];
                                            if (!empty($fmt['underline'])) $decorations[] = 'underline';
                                            if (!empty($fmt['strike'])) $decorations[] = 'line-through';
                                            $textDecoration = empty($decorations) ? 'none' : implode(' ', $decorations);
                                            $fontSize = (int) ($fmt['font_size'] ?? 12);
                                            $fontColor = $fmt['font_color'] ?? '#111827';
                                            $bgColor = $fmt['bg_color'] ?? 'transparent';
                                            $align = $fmt['align'] ?? 'left';
                                        @endphp
                                        <td class="border border-gray-200 dark:border-gray-600 p-0 
                                                   {{ $selectedRow === $row && $selectedCol === $col ? 'ring-2 ring-blue-500' : '' }}"
                                            style="width: {{ $colPixelWidth }}px; height: {{ $rowPixelHeight }}px;"
                                            wire:key="cell-{{ $row }}-{{ $col }}"
                                            @click="selectCell({{ $row }}, {{ $col }})"
                                            @contextmenu.prevent="openContextMenu($event, {{ $row }}, {{ $col }})"
                                            @dblclick="startEditing()">
                                            
                                            @if ($editingCell === "{$row}_{$col}")
                                                <!-- Edit Mode -->
                                                <input aria-label="Editingvalue" type="text" 
                                                    wire:model="editingValue"
                                                    wire:key="edit-input-{{ $row }}-{{ $col }}"
                                                    @keydown.enter="saveCell()"
                                                    @keydown.escape="cancelEdit()"
                                                    @blur="saveCell()"
                                                    autofocus
                                                    class="w-full h-full px-1 border-0 text-xs bg-white dark:bg-gray-700
                                                           text-gray-900 dark:text-white focus:outline-none focus:ring-0"
                                                    style="font-weight: {{ $fontWeight }}; font-style: {{ $fontStyle }}; text-decoration: {{ $textDecoration }}; font-size: {{ $fontSize }}px; color: {{ $fontColor }}; background-color: {{ $bgColor }}; text-align: {{ $align }};"
                                                    value="{{ $cell['raw_value'] }}">
                                            @else
                                                <!-- Display Mode -->
                                                <div class="w-full h-full px-1 flex items-center text-xs
                                                            overflow-hidden text-ellipsis whitespace-nowrap
                                                            {{ $cell['formula'] ? 'italic text-blue-600 dark:text-blue-400' : '' }}">
                                                    <div class="w-full"
                                                        style="font-weight: {{ $fontWeight }}; font-style: {{ $fontStyle }}; text-decoration: {{ $textDecoration }}; font-size: {{ $fontSize }}px; color: {{ $fontColor }}; background-color: {{ $bgColor }}; text-align: {{ $align }};">
                                                    {{ $cell['value'] ?: '' }}
                                                    </div>
                                                </div>
                                            @endif
                                        </td>
                                    @endforeach
                                </tr>
                            @endforeach

                            @if ($bottomSpacerRows > 0)
                                <tr style="height: {{ $bottomSpacerRows * $rowHeight }}px">
                                    <td colspan="{{ max(1, $viewportCols) }}" class="p-0 border-0"></td>
                                </tr>
                            @endif
                        </tbody>
                    </table>

                    <!-- Scrollbar Indicators -->
                    <div class="absolute bottom-2 right-2 text-xs text-gray-400 dark:text-gray-600 pointer-events-none">
                        {{ $scrollRowOffset + 1 }}-{{ min($scrollRowOffset + $viewportRows, $totalRows) }} of {{ number_format($totalRows) }} rows
                    </div>

                    <div id="bulkPasteProgress" wire:ignore class="absolute bottom-2 left-2 hidden text-xs px-2 py-1 rounded bg-indigo-600 text-white pointer-events-none"></div>
                    <div id="importProgress" wire:ignore class="absolute bottom-8 left-2 hidden text-xs px-2 py-1 rounded bg-emerald-600 text-white pointer-events-none"></div>

                    <!-- Remote collaborator cursors -->
                    <div id="remoteCursorsLayer" wire:ignore class="absolute inset-0 pointer-events-none z-10"></div>
                </div>
            </div>

            <!-- Sheet Tabs -->
            <div class="flex items-center gap-1 px-2 py-1 bg-gray-100 dark:bg-gray-900 border-t border-gray-200 dark:border-gray-700 overflow-x-auto flex-shrink-0">
                @foreach ($sheetTabs as $tab)
                    <div class="group flex items-center">
                        <button type="button"
                                wire:click="switchTab({{ $tab['id'] }})"
                                @if ($canEdit)
                                @dblclick="const name = prompt('Rename sheet', '{{ addslashes($tab['name']) }}'); if (name) $wire.renameTab({{ $tab['id'] }}, name)"
                                title="Double-click to rename"
                                @endif
                                class="px-3 py-1 text-xs font-medium rounded-t-md border-t border-l border-r
                                       {{ (int) $tab['id'] === (int) $activeSheetTabId
                                            ? 'bg-white dark:bg-gray-800 border-gray-200 dark:border-gray-700 text-blue-600 dark:text-blue-400'
                                            : 'bg-gray-200 dark:bg-gray-800/40 border-transparent text-gray-600 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-700' }}">
                            {{ $tab['name'] }}
                        </button>
                        @if (count($sheetTabs) > 1 && $canEdit)
                            <button type="button" wire:click="deleteTab({{ $tab['id'] }})"
                                    title="Delete sheet"
                                    class="opacity-0 group-hover:opacity-100 -ml-4 mr-1 text-gray-400 hover:text-red-500 text-xs px-1">
                                ✕
                            </button>
                        @endif
                    </div>
                @endforeach
                @if ($canEdit)
                <button type="button" wire:click="createTab()" title="Add sheet"
                        class="px-2 py-1 text-sm font-bold text-gray-500 dark:text-gray-400 hover:bg-gray-200 dark:hover:bg-gray-700 rounded">
                    +
                </button>
                @endif
            </div>
        </div>

        <!-- Right Sidebar - Analysis Panel (AI) -->
        <aside id="analysisPanel" wire:ignore class="w-80 bg-white dark:bg-gray-800 border-l border-gray-200 dark:border-gray-700 overflow-y-auto hidden flex-shrink-0">
            @livewire('ai-analysis-panel', ['spreadsheet_id' => $spreadsheet->id])
        </aside>

        <!-- Cell Properties Sidebar (default) -->
        <aside class="w-56 bg-white dark:bg-gray-800 border-l border-gray-200 dark:border-gray-700 flex flex-col flex-shrink-0" id="cellPropertiesPanel">
            <!-- Cell Info -->
            <div class="p-3 border-b border-gray-200 dark:border-gray-700 space-y-2">
                <h3 class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Cell</h3>
                @php $selectedCell = $viewportData[$selectedRow][$selectedCol] ?? null; @endphp

                <div class="flex items-center gap-2">
                    <span class="font-mono text-sm font-bold text-blue-600 dark:text-blue-400 bg-blue-50 dark:bg-blue-900/20 px-2 py-0.5 rounded">
                        {{ \App\Livewire\ShowSpreadsheet::colIndexToLetter($selectedCol) }}{{ $selectedRow + 1 }}
                    </span>
                    <span class="text-sm text-gray-700 dark:text-gray-300 truncate">
                        {{ $selectedCell['value'] ?? '' }}
                    </span>
                </div>

                @if ($selectedCell && $selectedCell['formula'])
                    <div class="bg-blue-50 dark:bg-blue-900/20 p-2 rounded font-mono text-xs text-blue-900 dark:text-blue-100 break-all">
                        {{ $selectedCell['formula'] }}
                    </div>
                @endif
            </div>

            <!-- Charts Section -->
            <div class="p-3 border-b border-gray-200 dark:border-gray-700">
                <h3 class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-2">Charts</h3>
                {{-- wire:key forces a full remount (fresh mount() call) when the
                     active tab changes, so this panel's own charts stay scoped
                     to whichever tab is currently open. --}}
                @livewire('chart-builder', ['spreadsheet_id' => $spreadsheet->id, 'sheet_tab_id' => $activeSheetTabId], key('chart-builder-'.$activeSheetTabId))
            </div>

            <!-- Pivot Tables Section -->
            <div class="p-3 border-b border-gray-200 dark:border-gray-700">
                <h3 class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-2">Pivot Tables</h3>
                @livewire('pivot-table-builder', ['spreadsheet_id' => $spreadsheet->id, 'sheet_tab_id' => $activeSheetTabId], key('pivot-table-builder-'.$activeSheetTabId))
            </div>

            <!-- Named Ranges Section -->
            <div class="p-3 border-b border-gray-200 dark:border-gray-700">
                <h3 class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-2">Named Ranges</h3>
                @livewire('named-ranges-panel', ['spreadsheet_id' => $spreadsheet->id, 'sheet_tab_id' => $activeSheetTabId], key('named-ranges-panel-'.$activeSheetTabId))
            </div>

            <!-- Comments Section -->
            <div class="p-3 border-b border-gray-200 dark:border-gray-700">
                @livewire('cell-comments-panel', ['spreadsheet_id' => $spreadsheet->id, 'sheet_tab_id' => $activeSheetTabId], key('cell-comments-panel-'.$activeSheetTabId))
            </div>

            <!-- Sharing Section -->
            @can('manageSharing', $spreadsheet)
                <div class="p-3 border-b border-gray-200 dark:border-gray-700">
                    @livewire('spreadsheet-sharing-panel', ['spreadsheet_id' => $spreadsheet->id])
                </div>
            @endcan

            <!-- Scripts & Macros Section (editing: a read-only member cannot even mount it) -->
            @if ($canEdit)
                <div class="p-3 border-b border-gray-200 dark:border-gray-700">
                    @livewire('scripts-editor', ['spreadsheet_id' => $spreadsheet->id])
                </div>
            @endif

            <!-- Spacer -->
            <div class="flex-1"></div>

            <!-- Quick Stats -->
            <div class="p-3 border-t border-gray-200 dark:border-gray-700 text-xs text-gray-500 dark:text-gray-400 space-y-1">
                <div>Rows: {{ $totalRows }}</div>
                <div>Cols: {{ $totalCols }}</div>
                <div>View: {{ $scrollRowOffset + 1 }}–{{ min($scrollRowOffset + $viewportRows, $totalRows) }}</div>
            </div>
        </aside>
    </div>

<div id="spreadsheetContextMenu" wire:ignore class="fixed z-50 hidden min-w-[180px] rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 shadow-xl">
    @if (config('sheet.structure_ops_enabled'))
    <button type="button" class="w-full text-left px-3 py-2 text-xs text-gray-700 dark:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-700" onclick="Livewire.dispatch('insert-row-selected'); closeSpreadsheetContextMenu();">Insert Row</button>
    <button type="button" class="w-full text-left px-3 py-2 text-xs text-gray-700 dark:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-700" onclick="Livewire.dispatch('delete-row-selected'); closeSpreadsheetContextMenu();">Delete Row</button>
    <button type="button" class="w-full text-left px-3 py-2 text-xs text-gray-700 dark:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-700" onclick="Livewire.dispatch('insert-column-selected'); closeSpreadsheetContextMenu();">Insert Column</button>
    <button type="button" class="w-full text-left px-3 py-2 text-xs text-gray-700 dark:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-700" onclick="Livewire.dispatch('delete-column-selected'); closeSpreadsheetContextMenu();">Delete Column</button>
    @endif
    <button type="button" class="w-full text-left px-3 py-2 text-xs text-gray-700 dark:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-700" onclick="Livewire.dispatch('toggle-hide-row-selected'); closeSpreadsheetContextMenu();">Hide/Unhide Row</button>
    <button type="button" class="w-full text-left px-3 py-2 text-xs text-gray-700 dark:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-700" onclick="Livewire.dispatch('toggle-hide-column-selected'); closeSpreadsheetContextMenu();">Hide/Unhide Column</button>
</div>

<script>
    function gridController() {
        return {
            selectedRow: @js($selectedRow),
            selectedCol: @js($selectedCol),
            
            selectCell(row, col) {
                @this.call('selectCell', row, col);
            },
            
            startEditing() {
                @this.call('startEditing', this.selectedRow, this.selectedCol);
            },
            
            navigate(direction) {
                @this.call('navigate', direction);
            },

            openContextMenu(event, row, col) {
                @this.call('selectCell', row, col);
                if (window.openSpreadsheetContextMenu) {
                    window.openSpreadsheetContextMenu(event.clientX, event.clientY);
                }
            },
            
            saveCell() {
                const input = document.querySelector('input[autofocus]');
                if (input) {
                    @this.call('saveCell', input.value);
                }
            },
            
            cancelEdit() {
                @this.set('editingCell', null);
            },
            
            escapeEditing() {
                @this.set('editingCell', null);
            }
        }
    }

    const spreadsheetId = {{ $spreadsheet->id }};
    // The realtime presence channel is shared by every tab in the
    // spreadsheet (see SpreadsheetCellUpdated/SpreadsheetCursorMoved), so
    // this tracks which tab is active client-side to filter out broadcasts
    // for a tab the user isn't currently looking at.
    let activeSheetTabId = {{ $activeSheetTabId ?? 'null' }};

    document.addEventListener('livewire:init', () => {
        Livewire.on('sheet-tab-switched', (payload) => {
            const data = Array.isArray(payload) ? payload[0] : payload;
            activeSheetTabId = data?.sheetTabId ?? null;
            collaborationCursors = {};
            renderRemoteCursors(collaborationCursors);
        });
    });

    function colToLetter(col) {
        let c = col + 1;
        let out = '';
        while (c > 0) {
            const rem = (c - 1) % 26;
            out = String.fromCharCode(65 + rem) + out;
            c = Math.floor((c - 1) / 26);
        }
        return out;
    }

    function renderActiveUsers(users) {
        const host = document.getElementById('activeUsers');
        if (!host) return;

        host.innerHTML = '';
        const list = Object.values(users);
        if (!list.length) return;

        list.slice(0, 6).forEach((u) => {
            const initials = (u.name || 'U').split(' ').map(p => p[0]).join('').slice(0, 2).toUpperCase();
            const el = document.createElement('div');
            el.className = 'w-6 h-6 rounded-full bg-indigo-600 text-white text-[10px] font-bold flex items-center justify-center border border-white dark:border-gray-800';
            el.title = u.name || u.email || `User ${u.id}`;
            el.textContent = initials;
            host.appendChild(el);
        });

        const count = document.createElement('span');
        count.className = 'text-[11px] text-gray-500 dark:text-gray-400 ml-1';
        count.textContent = `${list.length} active`;
        host.appendChild(count);
    }

    function renderRemoteCursors(cursors) {
        const layer = document.getElementById('remoteCursorsLayer');
        const grid = document.getElementById('gridContainer');
        if (!layer || !grid) return;

        const rowOffset = Math.floor(grid.scrollTop / 28);
        const colOffset = Math.floor(grid.scrollLeft / 96);
        const visibleRows = {{ $viewportRows }};
        const visibleCols = {{ $viewportCols }};

        layer.innerHTML = '';
        Object.values(cursors).forEach((cursor) => {
            const row = cursor.row;
            const col = cursor.col;
            if (row < rowOffset || col < colOffset) return;
            if (row >= rowOffset + visibleRows || col >= colOffset + visibleCols) return;

            const y = (row - rowOffset) * 28;
            const x = (col - colOffset) * 96;

            const marker = document.createElement('div');
            marker.className = 'absolute';
            marker.style.left = `${x}px`;
            marker.style.top = `${y}px`;
            marker.innerHTML = `
                <div class="w-24 h-7 border-2 border-orange-500 bg-orange-500/10 rounded-sm"><\/div>
                <div class="absolute -top-4 left-0 text-[10px] px-1 py-0.5 bg-orange-500 text-white rounded">${cursor.user_name}<\/div>
            `;

            layer.appendChild(marker);
        });
    }

    let collaborationUsers = {};
    let collaborationCursors = {};
    let bulkPastePollHandle = null;
    let importPollHandle = null;
    let currentImportBatchId = null;
    let currentSelection = { row: @js($selectedRow), col: @js($selectedCol) };
    let isMacroRecording = false;
    let recordedMacroActions = [];

    function pushMacroAction(action) {
        if (!isMacroRecording) return;
        if (!action || typeof action !== 'object') return;
        if (recordedMacroActions.length >= 1000) return;
        recordedMacroActions.push(action);
    }

    function normalizeLivewirePayload(payload) {
        if (Array.isArray(payload)) {
            return payload[0] || {};
        }
        return payload || {};
    }

    function initResizeHandles() {
        const colHandles = document.querySelectorAll('[data-col-resize-handle]');
        const rowHandles = document.querySelectorAll('[data-row-resize-handle]');

        colHandles.forEach((handle) => {
            handle.onmousedown = (event) => {
                event.preventDefault();
                const startX = event.clientX;
                const baseWidth = parseInt(handle.dataset.width || '96', 10);
                const col = parseInt(handle.dataset.col || '0', 10);

                const onMove = (moveEvent) => {
                    const delta = moveEvent.clientX - startX;
                    const next = Math.max(48, Math.min(420, baseWidth + delta));
                    handle.style.backgroundColor = 'rgba(59,130,246,0.35)';
                    handle.dataset.previewWidth = String(next);
                };

                const onUp = () => {
                    document.removeEventListener('mousemove', onMove);
                    document.removeEventListener('mouseup', onUp);
                    handle.style.backgroundColor = '';
                    const next = parseInt(handle.dataset.previewWidth || String(baseWidth), 10);
                    Livewire.dispatch('resize-column-selected', { col, width: next });
                };

                document.addEventListener('mousemove', onMove);
                document.addEventListener('mouseup', onUp);
            };
        });

        rowHandles.forEach((handle) => {
            handle.onmousedown = (event) => {
                event.preventDefault();
                const startY = event.clientY;
                const baseHeight = parseInt(handle.dataset.height || '28', 10);
                const row = parseInt(handle.dataset.row || '0', 10);

                const onMove = (moveEvent) => {
                    const delta = moveEvent.clientY - startY;
                    const next = Math.max(18, Math.min(120, baseHeight + delta));
                    handle.style.backgroundColor = 'rgba(59,130,246,0.35)';
                    handle.dataset.previewHeight = String(next);
                };

                const onUp = () => {
                    document.removeEventListener('mousemove', onMove);
                    document.removeEventListener('mouseup', onUp);
                    handle.style.backgroundColor = '';
                    const next = parseInt(handle.dataset.previewHeight || String(baseHeight), 10);
                    Livewire.dispatch('resize-row-selected', { row, height: next });
                };

                document.addEventListener('mousemove', onMove);
                document.addEventListener('mouseup', onUp);
            };
        });
    }

    function openSpreadsheetContextMenu(x, y) {
        const menu = document.getElementById('spreadsheetContextMenu');
        if (!menu) return;

        menu.classList.remove('hidden');
        menu.style.left = `${x}px`;
        menu.style.top = `${y}px`;
    }

    function closeSpreadsheetContextMenu() {
        const menu = document.getElementById('spreadsheetContextMenu');
        if (!menu) return;
        menu.classList.add('hidden');
    }

    window.openSpreadsheetContextMenu = openSpreadsheetContextMenu;
    window.closeSpreadsheetContextMenu = closeSpreadsheetContextMenu;

    // Focus grid and wire realtime on load
    document.addEventListener('DOMContentLoaded', () => {
        const grid = document.getElementById('gridContainer');
        if (grid) {
            grid.focus();
        }

        initResizeHandles();

        if (window.Echo) {
            window.Echo.join(`spreadsheet.${spreadsheetId}`)
                .here((members) => {
                    collaborationUsers = {};
                    members.forEach(m => collaborationUsers[m.id] = m);
                    renderActiveUsers(collaborationUsers);
                })
                .joining((member) => {
                    collaborationUsers[member.id] = member;
                    renderActiveUsers(collaborationUsers);
                })
                .leaving((member) => {
                    delete collaborationUsers[member.id];
                    delete collaborationCursors[member.id];
                    renderActiveUsers(collaborationUsers);
                    renderRemoteCursors(collaborationCursors);
                })
                .listen('.cursor.moved', (payload) => {
                    // Every tab shares this one presence channel -- ignore
                    // cursor moves for a tab the viewer isn't currently on.
                    if (payload.sheet_tab_id != null && payload.sheet_tab_id != activeSheetTabId) {
                        return;
                    }
                    collaborationCursors[payload.user_id] = payload;
                    renderRemoteCursors(collaborationCursors);
                })
                .listen('.cell.updated', (payload) => {
                    if (payload.sheet_tab_id != null && payload.sheet_tab_id != activeSheetTabId) {
                        return;
                    }
                    Livewire.dispatch('external-cell-updated');
                });
        }

        // Global keyboard shortcuts
        document.addEventListener('keydown', async (e) => {
            const isCmd = e.metaKey || e.ctrlKey;
            if (!isCmd) return;

            const key = e.key.toLowerCase();
            const formulaInput = document.getElementById('formulaInput');

            if (key === 's') {
                e.preventDefault();
                saveCurrentCell();
                return;
            }

            if (key === 'f') {
                e.preventDefault();
                if (formulaInput) {
                    formulaInput.focus();
                    formulaInput.select();
                }
                return;
            }

            if (key === 'z' && !e.shiftKey) {
                e.preventDefault();
                @this.call('undoAction');
                return;
            }

            if (key === 'y' || (key === 'z' && e.shiftKey)) {
                e.preventDefault();
                @this.call('redoAction');
                return;
            }

            if (key === 'c') {
                e.preventDefault();
                const selectedCell = document.querySelector('td.ring-2.ring-blue-500 div');
                const text = selectedCell ? selectedCell.textContent.trim() : '';
                if (text && navigator.clipboard) {
                    await navigator.clipboard.writeText(text);
                }
                return;
            }

            if (key === 'v') {
                e.preventDefault();
                if (navigator.clipboard) {
                    const text = await navigator.clipboard.readText();
                    if (text !== null && text !== undefined) {
                        if (formulaInput) {
                            formulaInput.value = text;
                        }

                        const isMatrixPaste = text.includes('\n') || text.includes('\t');
                        if (!isMatrixPaste) {
                            pushMacroAction({ type: 'set', row: currentSelection.row, col: currentSelection.col, value: text });
                            @this.call('pasteIntoSelectedCell', text);
                            return;
                        }

                        const result = await @this.call('bulkPasteFromText', text);
                        if (result && result.started) {
                            startBulkPastePolling();
                        }
                    }
                }
            }
        });

        document.addEventListener('click', () => closeSpreadsheetContextMenu());
        document.addEventListener('contextmenu', (e) => {
            if (!e.target.closest('td')) {
                closeSpreadsheetContextMenu();
            }
        });
    });

    async function startBulkPastePolling() {
        const progressEl = document.getElementById('bulkPasteProgress');
        if (!progressEl) return;

        if (bulkPastePollHandle) {
            clearInterval(bulkPastePollHandle);
            bulkPastePollHandle = null;
        }

        progressEl.classList.remove('hidden');
        progressEl.textContent = 'Bulk paste queued...';

        bulkPastePollHandle = setInterval(async () => {
            const status = await @this.call('getBulkPasteBatchStatus');
            if (!status) return;

            if (status.status === 'running') {
                progressEl.textContent = `Bulk paste ${status.progress}% (${status.processed}/${status.total} jobs)`;
                return;
            }

            if (status.status === 'finished') {
                progressEl.textContent = 'Bulk paste complete';
                clearInterval(bulkPastePollHandle);
                bulkPastePollHandle = null;
                setTimeout(() => progressEl.classList.add('hidden'), 1400);
                return;
            }

            if (status.status === 'cancelled' || status.status === 'failed' || status.status === 'missing') {
                progressEl.textContent = status.message || 'Bulk paste stopped';
                clearInterval(bulkPastePollHandle);
                bulkPastePollHandle = null;
                if (!status.message) {
                    setTimeout(() => progressEl.classList.add('hidden'), 1400);
                }
            }
        }, 900);
    }

    async function startImportPolling(batchId, queuedMessage) {
        const progressEl = document.getElementById('importProgress');
        if (!progressEl || !batchId) return;

        currentImportBatchId = batchId;

        if (importPollHandle) {
            clearInterval(importPollHandle);
            importPollHandle = null;
        }

        // The sentence the server sent when it queued the file stays on screen while the chunks run.
        const queuedNotice = queuedMessage || 'Import queued...';
        progressEl.classList.remove('hidden');
        progressEl.textContent = queuedNotice;

        const poll = async () => {
            try {
                const response = await fetch(`/spreadsheets/${spreadsheetId}/import/batches/${currentImportBatchId}`, {
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                });

                if (!response.ok) {
                    progressEl.textContent = 'Import status unavailable';
                    clearInterval(importPollHandle);
                    importPollHandle = null;
                    setTimeout(() => progressEl.classList.add('hidden'), 1600);
                    return;
                }

                const status = await response.json();
                if (status.status === 'running') {
                    progressEl.textContent = `${queuedNotice} (${status.progress}%)`;
                    return;
                }

                if (status.status === 'finished') {
                    progressEl.textContent = `Import complete${status.failed ? ` (${status.failed} failed)` : ''}`;
                    clearInterval(importPollHandle);
                    importPollHandle = null;
                    Livewire.dispatch('external-cell-updated');
                    setTimeout(() => window.location.reload(), 900);
                    return;
                }

                // cancelled or failed: say why and leave the message on screen
                progressEl.textContent = status.message || 'Import stopped';
                clearInterval(importPollHandle);
                importPollHandle = null;
                if (status.message) {
                    alert(status.message);
                } else {
                    setTimeout(() => progressEl.classList.add('hidden'), 1600);
                }
            } catch (_) {
                progressEl.textContent = 'Import poll error';
                clearInterval(importPollHandle);
                importPollHandle = null;
                setTimeout(() => progressEl.classList.add('hidden'), 1600);
            }
        };

        await poll();
        importPollHandle = setInterval(poll, 1000);
    }

    function saveCurrentCell() {
        const input = document.getElementById('formulaInput');
        const value = input ? input.value : '';
        pushMacroAction({ type: 'set', row: currentSelection.row, col: currentSelection.col, value });
        @this.call('saveFromFormulaBar', value);
    }

    function toggleShortcutsModal(open) {
        const modal = document.getElementById('shortcutsModal');
        if (!modal) return;
        if (open) {
            modal.classList.remove('hidden');
        } else {
            modal.classList.add('hidden');
        }
    }

    function toggleHelpModal(open) {
        const modal = document.getElementById('helpModal');
        if (!modal) return;
        if (open) {
            modal.classList.remove('hidden');
        } else {
            modal.classList.add('hidden');
        }
    }

    function startSpreadsheetTour() {
        if (!window.Shepherd) {
            alert('Guided tour library is not loaded.');
            return;
        }

        const tour = new window.Shepherd.Tour({
            useModalOverlay: true,
            defaultStepOptions: {
                cancelIcon: { enabled: true },
                classes: 'shadow-xl rounded-lg',
                scrollTo: true,
            },
        });

        tour.addStep({
            title: 'Formula Bar',
            text: 'Use this bar to quickly edit and save values or formulas in the selected cell.',
            attachTo: { element: '#formulaInput', on: 'bottom' },
            buttons: [{ text: 'Next', action: tour.next }],
        });

        tour.addStep({
            title: 'Formatting Toolbar',
            text: 'Apply format painter, validation, filters, and sorting from this toolbar.',
            attachTo: { element: '.bg-white.dark\\:bg-gray-800.border-b.border-gray-200.dark\\:border-gray-700.px-4.py-1', on: 'bottom' },
            buttons: [
                { text: 'Back', action: tour.back },
                { text: 'Next', action: tour.next },
            ],
        });

        tour.addStep({
            title: 'Grid',
            text: 'Click cells to select, double-click to edit, and right-click for row/column actions.',
            attachTo: { element: '#gridContainer', on: 'top' },
            buttons: [
                { text: 'Back', action: tour.back },
                { text: 'Next', action: tour.next },
            ],
        });

        tour.addStep({
            title: 'AI + Scripts Sidebars',
            text: 'Use AI analysis, chart tools, comments, sharing, and scripts/macros from the side panels.',
            attachTo: { element: '#cellPropertiesPanel', on: 'left' },
            buttons: [
                { text: 'Back', action: tour.back },
                { text: 'Done', action: tour.complete },
            ],
        });

        tour.start();
    }

    // Sync scroll between headers and grid
    const gridContainer = document.getElementById('gridContainer');
    const colHeaderScroll = document.getElementById('colHeaderScroll');
    const rowHeaderScroll = document.getElementById('rowHeaderScroll');

    if (gridContainer && colHeaderScroll) {
        gridContainer.addEventListener('scroll', () => {
            colHeaderScroll.scrollLeft = gridContainer.scrollLeft;
            rowHeaderScroll.scrollTop = gridContainer.scrollTop;
            renderRemoteCursors(collaborationCursors);
        });
    }

    // Handle AI formula modal
    document.addEventListener('open-formula-modal', () => {
        const backdrop = document.getElementById('formulaModalBackdrop');
        if (backdrop) {
            backdrop.classList.remove('hidden');
        }
    });

    // Update cell reference display in formula bar
    document.addEventListener('livewire:initialized', () => {
        Livewire.on('cell-selected', (payload) => {
            const data = normalizeLivewirePayload(payload);
            const cellRef = document.getElementById('cellRef');
            if (cellRef && data) {
                cellRef.textContent = colToLetter(data.col) + (data.row + 1);
            }

            if (data && Number.isInteger(data.row) && Number.isInteger(data.col)) {
                currentSelection = { row: data.row, col: data.col };
                pushMacroAction({ type: 'select', row: data.row, col: data.col });
            }
        });

        Livewire.on('run-script-in-sandbox', async (payload) => {
            const data = normalizeLivewirePayload(payload);
            if (!window.runSpreadsheetScriptSandbox) {
                alert('Sandbox worker is unavailable.');
                return;
            }

            const result = await window.runSpreadsheetScriptSandbox(data);
            if (!result.ok) {
                alert(`Script error: ${result.error || 'Unknown error'}`);
                return;
            }

            Livewire.dispatch('apply-script-actions', {
                source: 'script',
                actions: result.actions || [],
            });
        });

        Livewire.hook('morph.updated', () => {
            initResizeHandles();
        });
    });

    window.addEventListener('spreadsheet-start-macro-recording', () => {
        isMacroRecording = true;
        recordedMacroActions = [];
    });

    window.addEventListener('spreadsheet-stop-macro-recording', () => {
        isMacroRecording = false;
        if (!recordedMacroActions.length) return;

        Livewire.dispatch('macro-recorded', {
            actions: recordedMacroActions,
        });
        recordedMacroActions = [];
    });

    // CSV/Excel import handler
    function handleImport(event) {
        const file = event.target.files[0];
        if (!file) return;

        const formData = new FormData();
        formData.append('file', file);
        formData.append('sheet_tab_id', activeSheetTabId);
        formData.append('_token', document.querySelector('meta[name="csrf-token"]').content);

        fetch('/spreadsheets/{{ $spreadsheet->id }}/import', {
            method: 'POST',
            body: formData,
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                if (data.queued) {
                    startImportPolling(data.batch_id, data.message);
                    return;
                }
                alert(`Imported ${data.cells} cells across ${data.rows} rows.`);
                window.location.reload();
            } else {
                alert('Import failed: ' + (data.error || 'Unknown error'));
            }
        })
        .catch(e => alert('Import error: ' + e.message));

        event.target.value = '';
    }
</script>

<!-- AI Formula Modal -->
@livewire('ai-formula-modal', ['spreadsheet_id' => $spreadsheet->id])

<!-- Version History Modal -->
@livewire('version-history-modal', ['spreadsheet_id' => $spreadsheet->id, 'sheet_tab_id' => $activeSheetTabId], key('version-history-modal-'.$activeSheetTabId))

<!-- AI Natural Language Query Modal -->
<div id="nlqModal" wire:ignore class="fixed inset-0 bg-black/50 hidden z-50 flex items-center justify-center" @click.self="this.classList.add('hidden')">
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-2xl w-full h-5/6 max-w-2xl" @click.stop>
        <div class="flex flex-col h-full">
            <div class="p-4 border-b border-gray-200 dark:border-gray-700 flex justify-between items-center">
                <h2 class="text-xl font-bold text-gray-900 dark:text-white">AI Assistant</h2>
                <button type="button" class="text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300"
                        onclick="document.getElementById('nlqModal').classList.add('hidden')">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>
            <div class="flex-1 overflow-hidden">
                @livewire('ai-natural-language-query', ['spreadsheet_id' => $spreadsheet->id])
            </div>
        </div>
    </div>
</div>

<!-- Keyboard Shortcuts Modal -->
<div id="shortcutsModal" wire:ignore class="fixed inset-0 bg-black/50 hidden z-50 flex items-center justify-center" onclick="if(event.target===this) toggleShortcutsModal(false)">
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-2xl w-full max-w-lg mx-4">
        <div class="px-5 py-4 border-b border-gray-200 dark:border-gray-700 flex items-center justify-between">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Keyboard Shortcuts</h3>
            <button onclick="toggleShortcutsModal(false)" class="text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200">✕</button>
        </div>
        <div class="p-5">
            <div class="grid grid-cols-2 gap-2 text-sm text-gray-700 dark:text-gray-300">
                <div><kbd class="px-1.5 py-0.5 bg-gray-100 dark:bg-gray-700 rounded">Ctrl/Cmd + S</kbd> Save current cell</div>
                <div><kbd class="px-1.5 py-0.5 bg-gray-100 dark:bg-gray-700 rounded">Ctrl/Cmd + F</kbd> Focus formula bar</div>
                <div><kbd class="px-1.5 py-0.5 bg-gray-100 dark:bg-gray-700 rounded">Ctrl/Cmd + C</kbd> Copy selected cell</div>
                <div><kbd class="px-1.5 py-0.5 bg-gray-100 dark:bg-gray-700 rounded">Ctrl/Cmd + V</kbd> Paste into selected cell</div>
                <div><kbd class="px-1.5 py-0.5 bg-gray-100 dark:bg-gray-700 rounded">Ctrl/Cmd + Z</kbd> Undo</div>
                <div><kbd class="px-1.5 py-0.5 bg-gray-100 dark:bg-gray-700 rounded">Ctrl/Cmd + Y</kbd> Redo</div>
            </div>
        </div>
    </div>
</div>

<!-- Help Modal -->
<div id="helpModal" wire:ignore class="fixed inset-0 bg-black/50 hidden z-50 flex items-center justify-center" onclick="if(event.target===this) toggleHelpModal(false)">
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-2xl w-full max-w-2xl mx-4">
        <div class="px-5 py-4 border-b border-gray-200 dark:border-gray-700 flex items-center justify-between">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Spreadsheet Help</h3>
            <button onclick="toggleHelpModal(false)" class="text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200">✕</button>
        </div>
        <div class="p-5 space-y-3 text-sm text-gray-700 dark:text-gray-300">
            <p><strong>Editing:</strong> click to select, double-click to edit, Enter to save.</p>
            <p><strong>Formulas:</strong> start with <code>=</code>, example <code>=SUM(A1,B1)</code>.</p>
            <p><strong>Structure:</strong> right-click any cell for row/column insert/delete/hide actions.</p>
            <p><strong>Validation:</strong> use toolbar controls to enforce number ranges, text lengths, or list values.</p>
            <p><strong>Filters:</strong> create contains/number/date/color/condition filters from toolbar.</p>
            <p><strong>Imports:</strong> large files run in queue jobs with a live import status badge.</p>
            <p><strong>Tour:</strong> click <em>Tour</em> in header for a guided walkthrough.</p>
        </div>
    </div>
</div>


<style scoped>
    table {
        border-collapse: collapse;
        background-color: white;
    }
    
    td {
        user-select: none;
    }
    
    td:hover {
        background-color: #f3f4f6;
    }
    
    td.dark\:bg-gray-800:hover {
        background-color: rgba(55, 65, 81, 0.5);
    }
</style>

</div>
