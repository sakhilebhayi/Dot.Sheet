<?php

namespace App\Livewire;

use App\Events\SpreadsheetCellUpdated;
use App\Events\SpreadsheetCursorMoved;
use App\Jobs\BulkPasteChunkJob;
use App\Models\Cell;
use App\Models\CellHistory;
use App\Models\SheetTab;
use App\Models\Spreadsheet;
use App\Models\SpreadsheetVersion;
use App\Models\WorkflowExecutionLog;
use App\Notifications\WorkflowRuleTriggered;
use App\Services\FormulaEvaluatorService;
use App\Services\SheetStructureService;
use App\Services\StaleStructure;
use App\Services\StructureChangeRefused;
use App\Services\StructureVersion;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

class ShowSpreadsheet extends Component
{
    use AuthorizesRequests;

    public $spreadsheet_id;

    protected ?Spreadsheet $spreadsheet = null;

    // Multi-tab workbook. Every Cell/ChartConfig/Comment/SpreadsheetVersion
    // query below is scoped to $activeSheetTabId as well as spreadsheet_id
    // -- see cellsQuery() -- so two tabs in the same spreadsheet each have
    // their own fully independent grid.
    public $activeSheetTabId;

    public $sheetTabs = [];

    // Grid display
    public $viewportRows = 50;           // Visible rows (for virtual scrolling)

    public $viewportCols = 20;           // Visible columns

    public $scrollRowOffset = 0;         // Current scroll position (rows)

    public $scrollColOffset = 0;         // Current scroll position (columns)

    public $totalRows = SheetStructureService::GRID_ROWS;         // Total rows in grid (1M+ target)

    public $totalCols = SheetStructureService::GRID_COLS;             // Total columns (A-CV)

    public $rowHeight = 28;              // px

    public $colWidth = 96;               // px

    // Lazy-loading / prefetch tuning
    public $prefetchRows = 40;

    public $prefetchCols = 15;

    // Current selection
    public $selectedRow = 0;

    public $selectedCol = 0;

    public $editingCell = null;          // Format: 'R<row>C<col>'

    public $editingValue = '';

    // Cell data (cached in memory for performance)
    public $cellData = [];               // Grid cache: [rowIndex][colIndex] => Cell data

    public $loadedWindows = [];          // Loaded windows cache keys to avoid duplicate queries

    // Formula evaluation
    public $formulaErrors = [];

    public $computedValues = [];         // Cached computed values

    // Local undo/redo stacks (session-scoped)
    public $undoStack = [];

    public $redoStack = [];

    // Bulk paste queue batch tracking
    public $bulkPasteBatchId = null;

    public $bulkPasteStatus = 'idle';

    public $bulkPasteTotalJobs = 0;

    public $bulkPasteProcessedJobs = 0;

    // Phase 4 settings-backed features
    public $rowHeights = [];

    public $colWidths = [];

    public $hiddenRows = [];

    public $hiddenCols = [];

    public $validationRules = [];

    public $conditionalFormattingRules = [];

    public $filterRules = [];

    /**
     * The structure version (see StructureVersion) this component's grid was loaded at.
     * Locked: the browser may not set it, or the stale-write check could be switched off.
     */
    #[Locked]
    public int $structureVersion = 0;

    // A sentence for the person at the keyboard: stale grid reloaded, undo history cleared,
    // an insert refused.
    public string $structureNotice = '';

    public function mount($spreadsheet_id, $sheet_tab_id = null)
    {
        $this->spreadsheet_id = $spreadsheet_id;
        $this->loadSpreadsheet($sheet_tab_id);
    }

    public function hydrate(): void
    {
        if ($this->spreadsheet_id) {
            $this->spreadsheet = Spreadsheet::findOrFail($this->spreadsheet_id);
        }
    }

    public function loadSpreadsheet($sheet_tab_id = null)
    {
        // Load spreadsheet with authorization check
        $this->spreadsheet = Spreadsheet::findOrFail($this->spreadsheet_id);

        // Check authorization
        $this->authorize('view', $this->spreadsheet);

        $this->structureVersion = StructureVersion::current((int) $this->spreadsheet_id);

        $this->loadSheetTabs();

        $requestedTab = $sheet_tab_id ? $this->findOwnTab((int) $sheet_tab_id) : null;
        $this->activeSheetTabId = $requestedTab['id'] ?? ($this->sheetTabs[0]['id'] ?? null);

        // Load only current viewport (+ prefetch buffer) from DB
        $this->loadViewportCells(true);
        $this->loadFeatureSettings();
    }

    protected function loadSheetTabs(): void
    {
        $this->sheetTabs = SheetTab::where('spreadsheet_id', $this->spreadsheet_id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['id', 'name', 'sort_order'])
            ->toArray();
    }

    protected function findOwnTab(int $tabId): ?array
    {
        foreach ($this->sheetTabs as $tab) {
            if ((int) $tab['id'] === $tabId) {
                return $tab;
            }
        }

        return null;
    }

    /**
     * Every cell query in this component goes through here rather than a
     * bare Cell::where('spreadsheet_id', ...) so tab-scoping can't be
     * forgotten on a new call site.
     */
    protected function cellsQuery()
    {
        return Cell::where('spreadsheet_id', $this->spreadsheet_id)
            ->where('sheet_tab_id', $this->activeSheetTabId);
    }

    public function switchTab($sheetTabId): void
    {
        if ($this->refuseWhenStructureIsStale()) {
            return;
        }

        $tab = $this->findOwnTab((int) $sheetTabId);
        if (! $tab || (int) $sheetTabId === (int) $this->activeSheetTabId) {
            return;
        }

        $this->activeSheetTabId = $tab['id'];
        $this->cellData = [];
        $this->loadedWindows = [];
        $this->selectedRow = 0;
        $this->selectedCol = 0;
        $this->editingCell = null;
        $this->undoStack = [];
        $this->redoStack = [];
        $this->loadViewportCells(true);

        $this->dispatch('sheet-tab-switched', sheetTabId: $this->activeSheetTabId);
    }

    public function createTab(?string $name = null): void
    {
        $this->authorize('update', $this->spreadsheet);

        $nextIndex = count($this->sheetTabs) + 1;
        $tab = SheetTab::create([
            'spreadsheet_id' => $this->spreadsheet_id,
            'name' => $name && trim($name) !== '' ? trim($name) : "Sheet{$nextIndex}",
            'sort_order' => count($this->sheetTabs),
        ]);

        $this->loadSheetTabs();
        $this->switchTab($tab->id);
    }

    public function renameTab($sheetTabId, string $name): void
    {
        $this->authorize('update', $this->spreadsheet);

        $name = trim($name);
        if ($name === '' || ! $this->findOwnTab((int) $sheetTabId)) {
            return;
        }

        SheetTab::where('spreadsheet_id', $this->spreadsheet_id)
            ->where('id', $sheetTabId)
            ->update(['name' => $name]);

        $this->loadSheetTabs();
    }

    public function deleteTab($sheetTabId): void
    {
        $this->authorize('update', $this->spreadsheet);

        if ($this->refuseWhenStructureIsStale()) {
            return;
        }

        if (count($this->sheetTabs) <= 1 || ! $this->findOwnTab((int) $sheetTabId)) {
            // A workbook always needs at least one tab.
            return;
        }

        $wasActive = (int) $sheetTabId === (int) $this->activeSheetTabId;

        // Deleting a tab takes its cells with it: every editor still showing it must reload, so the
        // structure version goes up with the delete, in the same transaction.
        $deleted = false;
        $this->guardedWrite(function () use ($sheetTabId, &$deleted) {
            SheetTab::where('spreadsheet_id', $this->spreadsheet_id)
                ->where('id', $sheetTabId)
                ->delete();
            StructureVersion::bump((int) $this->spreadsheet_id);
            $deleted = true;
        });

        if (! $deleted) {
            return;
        }

        $this->structureVersion = StructureVersion::current((int) $this->spreadsheet_id);
        $this->undoStack = [];
        $this->redoStack = [];
        $this->dispatch('structure-synced');

        $this->loadSheetTabs();

        if ($wasActive) {
            $this->activeSheetTabId = $this->sheetTabs[0]['id'] ?? null;
            $this->cellData = [];
            $this->loadedWindows = [];
            $this->loadViewportCells(true);
            $this->dispatch('sheet-tab-switched', sheetTabId: $this->activeSheetTabId);
        }
    }

    protected function loadCellsFromDatabase()
    {
        // Backward-compatible alias; now uses lazy viewport loading.
        $this->loadViewportCells(true);
    }

    /**
     * Why this component's grid can no longer be written to, or null when it still matches the
     * stored cells: rows or columns moved since it loaded, or its tab was deleted.
     */
    protected function staleReason(bool $lock = false): ?string
    {
        if ($this->activeSheetTabId && ! SheetTab::whereKey($this->activeSheetTabId)->exists()) {
            return StructureVersion::TAB_GONE_MESSAGE;
        }

        return StructureVersion::current((int) $this->spreadsheet_id, $lock) === $this->structureVersion
            ? null
            : StructureVersion::STALE_MESSAGE;
    }

    /**
     * True (after reloading the grid and saying so) when rows or columns were inserted or deleted
     * since this component loaded: its coordinates no longer match the stored cells, so the write
     * that asked must not happen. A quick check before the work; the check that counts is the one
     * guardedWrite() makes inside the transaction that writes.
     */
    protected function refuseWhenStructureIsStale(): bool
    {
        $reason = $this->staleReason();
        if ($reason === null) {
            return false;
        }

        $this->resyncAfterStructureChange();
        $this->structureNotice = $reason;

        return true;
    }

    /**
     * Run a cell write inside one transaction (BEGIN IMMEDIATE on SQLite) that first re-checks the
     * structure version under the write lock. A structure change committing between a check and the
     * write used to let a stale write through: onto a moved cell (unique key error) or onto the old
     * coordinate of an empty cell. Returns false, after reloading and saying so, when it refused.
     */
    protected function guardedWrite(callable $write): bool
    {
        try {
            DB::transaction(function () use ($write) {
                $reason = $this->staleReason(lock: true);
                if ($reason !== null) {
                    throw new StaleStructure($reason);
                }

                $write();
            });
        } catch (StaleStructure $e) {
            $this->resyncAfterStructureChange();
            $this->structureNotice = $e->getMessage();

            return false;
        } catch (QueryException|\PDOException $e) {
            // Another writer held the SQLite write lock for longer than busy_timeout: nothing was written.
            if (! SheetStructureService::isLockTimeout($e)) {
                throw $e;
            }
            $this->structureNotice = SheetStructureService::BUSY_MESSAGE;

            return false;
        }

        $this->structureNotice = '';

        return true;
    }

    /**
     * Reload everything that holds coordinates: the tab list, the grid, the per-row and per-column
     * settings, the undo history (its entries point at coordinates that moved) and the child components.
     */
    protected function resyncAfterStructureChange(): void
    {
        $this->structureVersion = StructureVersion::current((int) $this->spreadsheet_id);
        $this->spreadsheet->refresh();
        $this->loadSheetTabs();
        if (! $this->findOwnTab((int) $this->activeSheetTabId)) {
            $this->activeSheetTabId = $this->sheetTabs[0]['id'] ?? null;
            $this->cellData = [];
            $this->dispatch('sheet-tab-switched', sheetTabId: $this->activeSheetTabId);
        }
        $this->undoStack = [];
        $this->redoStack = [];
        $this->editingCell = null;
        $this->editingValue = '';
        $this->formulaErrors = [];
        $this->loadFeatureSettings();
        $this->loadedWindows = [];
        $this->loadViewportCells(true);
        $this->dispatch('structure-synced');
    }

    /**
     * A child component (toolbar, AI panel) found out this page is stale.
     */
    #[On('structure-stale')]
    public function reloadBecauseStructureChanged(): void
    {
        $this->resyncAfterStructureChange();
        $this->structureNotice = $this->staleReason() ?? StructureVersion::STALE_MESSAGE;
    }

    public function dismissStructureNotice(): void
    {
        $this->structureNotice = '';
    }

    /**
     * Run one insert/delete of a row or column and bring this component in line with it.
     *
     * @param  'insert'|'delete'  $operation
     * @param  'row'|'col'  $axis
     */
    protected function changeStructure(string $operation, string $axis): void
    {
        $this->authorize('update', $this->spreadsheet);

        if ($this->refuseWhenStructureIsStale()) {
            return;
        }

        $index = max(0, (int) ($axis === 'row' ? $this->selectedRow : $this->selectedCol));
        $service = app(SheetStructureService::class);
        $spreadsheetId = (int) $this->spreadsheet_id;
        $tabId = (int) $this->activeSheetTabId;

        try {
            match ([$operation, $axis]) {
                ['insert', 'row'] => $service->insertRow($spreadsheetId, $tabId, $index, $this->structureVersion),
                ['delete', 'row'] => $service->deleteRow($spreadsheetId, $tabId, $index, $this->structureVersion),
                ['insert', 'col'] => $service->insertColumn($spreadsheetId, $tabId, $index, $this->structureVersion),
                ['delete', 'col'] => $service->deleteColumn($spreadsheetId, $tabId, $index, $this->structureVersion),
            };
        } catch (StaleStructure $e) {
            $this->resyncAfterStructureChange();
            $this->structureNotice = $e->getMessage();

            return;
        } catch (StructureChangeRefused $e) {
            $this->structureNotice = $e->getMessage();

            return;
        }

        $hadUndoHistory = $this->undoStack !== [] || $this->redoStack !== [];

        // Undo entries hold coordinates and would hit whatever moved there: drop them.
        $this->resyncAfterStructureChange();
        $this->structureNotice = $hadUndoHistory ? 'Undo history was cleared because rows or columns moved.' : '';

        if ($axis === 'row') {
            $this->shiftRowSettings($operation, $index);
        } else {
            $this->shiftColumnSettings($operation, $index);
        }
        $this->saveFeatureSettings();
    }

    /**
     * @param  'insert'|'delete'  $operation
     */
    protected function shiftRowSettings(string $operation, int $row): void
    {
        $shifted = [];
        foreach ($this->rowHeights as $r => $h) {
            $key = (int) $r;
            if ($operation === 'delete' && $key === $row) {
                continue;
            }
            $shifted[$operation === 'insert' ? ($key >= $row ? $key + 1 : $key) : ($key > $row ? $key - 1 : $key)] = $h;
        }
        $this->rowHeights = $shifted;

        $this->hiddenRows = array_values(array_filter(array_map(function ($r) use ($operation, $row) {
            $r = (int) $r;
            if ($operation === 'insert') {
                return $r >= $row ? $r + 1 : $r;
            }

            return $r === $row ? null : ($r > $row ? $r - 1 : $r);
        }, $this->hiddenRows), fn ($v) => $v !== null));
    }

    /**
     * @param  'insert'|'delete'  $operation
     */
    protected function shiftColumnSettings(string $operation, int $col): void
    {
        $shifted = [];
        foreach ($this->colWidths as $c => $w) {
            $key = (int) $c;
            if ($operation === 'delete' && $key === $col) {
                continue;
            }
            $shifted[$operation === 'insert' ? ($key >= $col ? $key + 1 : $key) : ($key > $col ? $key - 1 : $key)] = $w;
        }
        $this->colWidths = $shifted;

        $this->hiddenCols = array_values(array_filter(array_map(function ($c) use ($operation, $col) {
            $c = (int) $c;
            if ($operation === 'insert') {
                return $c >= $col ? $c + 1 : $c;
            }

            return $c === $col ? null : ($c > $col ? $c - 1 : $c);
        }, $this->hiddenCols), fn ($v) => $v !== null));
    }

    protected function loadFeatureSettings(): void
    {
        $settings = $this->normalizeArrayValue($this->spreadsheet->settings ?? []);

        $this->rowHeights = $this->normalizeArrayValue($settings['row_heights'] ?? []);
        $this->colWidths = $this->normalizeArrayValue($settings['col_widths'] ?? []);
        $this->hiddenRows = $this->normalizeArrayValue($settings['hidden_rows'] ?? []);
        $this->hiddenCols = $this->normalizeArrayValue($settings['hidden_cols'] ?? []);
        $this->validationRules = $this->normalizeArrayValue($settings['validation_rules'] ?? []);
        $this->conditionalFormattingRules = $this->normalizeArrayValue($settings['conditional_formatting_rules'] ?? []);
        $this->filterRules = $this->normalizeArrayValue($settings['filter_rules'] ?? []);
    }

    protected function saveFeatureSettings(): void
    {
        $rowHeights = $this->rowHeights;
        $colWidths = $this->colWidths;
        $hiddenRows = array_values(array_unique(array_map('intval', $this->hiddenRows)));
        $hiddenCols = array_values(array_unique(array_map('intval', $this->hiddenCols)));
        $validation = $this->validationRules;
        $conditional = $this->conditionalFormattingRules;
        $filters = $this->filterRules;

        // Only these keys, merged into whatever is stored now (scripts, workflows ...), under the write lock.
        Spreadsheet::updateSettings((int) $this->spreadsheet_id, function (array $settings) use ($rowHeights, $colWidths, $hiddenRows, $hiddenCols, $validation, $conditional, $filters) {
            $settings['row_heights'] = $rowHeights;
            $settings['col_widths'] = $colWidths;
            $settings['hidden_rows'] = $hiddenRows;
            $settings['hidden_cols'] = $hiddenCols;
            $settings['validation_rules'] = $validation;
            $settings['conditional_formatting_rules'] = $conditional;
            $settings['filter_rules'] = $filters;

            return $settings;
        });

        $this->spreadsheet->refresh();
    }

    /**
     * Load only cells in current viewport plus a configurable prefetch buffer.
     */
    protected function loadViewportCells(bool $force = false): void
    {
        $startRow = max(0, $this->scrollRowOffset - $this->prefetchRows);
        $endRow = min($this->totalRows - 1, $this->scrollRowOffset + $this->viewportRows + $this->prefetchRows);

        $startCol = max(0, $this->scrollColOffset - $this->prefetchCols);
        $endCol = min($this->totalCols - 1, $this->scrollColOffset + $this->viewportCols + $this->prefetchCols);

        $windowKey = implode(':', [$startRow, $endRow, $startCol, $endCol]);
        if (! $force && isset($this->loadedWindows[$windowKey])) {
            return;
        }

        $cells = $this->cellsQuery()
            ->whereBetween('row_index', [$startRow, $endRow])
            ->whereBetween('col_index', [$startCol, $endCol])
            ->get();

        foreach ($cells as $cell) {
            $row = (int) $cell->row_index;
            $col = (int) $cell->col_index;

            if (! isset($this->cellData[$row])) {
                $this->cellData[$row] = [];
            }

            $this->cellData[$row][$col] = [
                'id' => $cell->id,
                'raw_value' => $cell->raw_value,
                'computed_value' => $cell->computed_value,
                'formula' => $cell->formula,
                'formatting' => $this->normalizeArrayValue($cell->formatting ?? []),
            ];
        }

        $this->loadedWindows[$windowKey] = true;
        $this->pruneCellCache();
    }

    /**
     * Keep memory bounded by pruning cells far away from the active viewport.
     */
    protected function pruneCellCache(): void
    {
        $minRow = max(0, $this->scrollRowOffset - ($this->prefetchRows * 3));
        $maxRow = min($this->totalRows - 1, $this->scrollRowOffset + $this->viewportRows + ($this->prefetchRows * 3));
        $minCol = max(0, $this->scrollColOffset - ($this->prefetchCols * 3));
        $maxCol = min($this->totalCols - 1, $this->scrollColOffset + $this->viewportCols + ($this->prefetchCols * 3));

        foreach ($this->cellData as $row => $cols) {
            if ($row < $minRow || $row > $maxRow) {
                unset($this->cellData[$row]);

                continue;
            }

            foreach ($cols as $col => $_cell) {
                if ($col < $minCol || $col > $maxCol) {
                    unset($this->cellData[$row][$col]);
                }
            }

            if (empty($this->cellData[$row])) {
                unset($this->cellData[$row]);
            }
        }

        // Reset loaded windows when cache grows too much to avoid stale-memory growth.
        if (count($this->loadedWindows) > 40) {
            $this->loadedWindows = [];
        }
    }

    /**
     * Get viewport data for rendering
     */
    public function getViewportData()
    {
        $this->loadViewportCells();

        $viewport = [];
        $endCol = min($this->scrollColOffset + $this->viewportCols, $this->totalCols);

        $visibleRows = 0;
        for ($row = $this->scrollRowOffset; $row < $this->totalRows && $visibleRows < $this->viewportRows; $row++) {
            if (in_array($row, $this->hiddenRows, true)) {
                continue;
            }

            $viewport[$row] = [];
            for ($col = $this->scrollColOffset; $col < $endCol; $col++) {
                if (in_array($col, $this->hiddenCols, true)) {
                    continue;
                }
                $viewport[$row][$col] = $this->getCellData($row, $col);
            }

            $visibleRows++;
        }

        return $viewport;
    }

    /**
     * Get individual cell data
     */
    protected function getCellData($row, $col)
    {
        $key = "{$row}_{$col}";

        if (isset($this->cellData[$row][$col])) {
            $cell = $this->cellData[$row][$col];
            $displayValue = $cell['computed_value'] ?? $cell['raw_value'] ?? '';
            $formatting = $this->applyConditionalFormattingRules($row, $col, $displayValue, $cell['formatting'] ?? []);

            return [
                'id' => $cell['id'],
                'row' => $row,
                'col' => $col,
                'value' => $displayValue,
                'raw_value' => $cell['raw_value'] ?? '',
                'formula' => $cell['formula'],
                'formatting' => $formatting,
                'isEditing' => $this->editingCell === $key,
            ];
        }

        return [
            'id' => null,
            'row' => $row,
            'col' => $col,
            'value' => '',
            'raw_value' => '',
            'formula' => null,
            'formatting' => [],
            'isEditing' => $this->editingCell === $key,
        ];
    }

    /**
     * Convert column index to letter (0 -> A, 1 -> B, etc.)
     */
    public static function colIndexToLetter($index)
    {
        $letter = '';
        while ($index >= 0) {
            $letter = chr(65 + ($index % 26)).$letter;
            $index = intdiv($index, 26) - 1;
            if ($index < 0) {
                break;
            }
        }

        return $letter;
    }

    /**
     * Convert column letter to index (A -> 0, B -> 1, etc.)
     */
    public static function colLetterToIndex($letter)
    {
        $index = 0;
        for ($i = 0; $i < strlen($letter); $i++) {
            $index = $index * 26 + (ord($letter[$i]) - 64);
        }

        return $index - 1;
    }

    // Livewire Actions

    #[On('cell-clicked')]
    public function selectCell($row, $col)
    {
        $this->selectedRow = $row;
        $this->selectedCol = $col;

        // Ensure selection is visible (autoscroll on select)
        $this->ensureVisible($row, $col);

        // Notify sibling components (toolbar/comments) about selected cell
        $this->dispatch('cell-selected', row: $row, col: $col);

        // Broadcast cursor movement to other connected collaborators
        if (Auth::check()) {
            broadcast(new SpreadsheetCursorMoved(
                spreadsheetId: (int) $this->spreadsheet_id,
                sheetTabId: $this->activeSheetTabId ? (int) $this->activeSheetTabId : null,
                row: (int) $row,
                col: (int) $col,
                userId: (int) Auth::id(),
                userName: (string) Auth::user()->name,
            ))->toOthers();
        }
    }

    #[On('cell-double-clicked')]
    public function startEditing($row, $col)
    {
        $this->selectCell($row, $col);
        $this->editingCell = "{$row}_{$col}";
        $cell = $this->getCellData($row, $col);
        $this->editingValue = $cell['raw_value'] ?? '';
    }

    #[On('cell-edit-complete')]
    public function saveCell($value)
    {
        $this->authorize('update', $this->spreadsheet);

        if ($this->refuseWhenStructureIsStale()) {
            return;
        }

        if (! $this->editingCell) {
            return;
        }

        [$row, $col] = explode('_', $this->editingCell);
        $row = (int) $row;
        $col = (int) $col;

        $invalid = false;
        $cell = null;
        $oldValue = null;
        $oldState = [];
        $rawValue = '';
        $computedValue = null;
        $formula = null;

        // Everything from reading the cell to writing it and its history is one transaction that
        // re-checks the structure version first (see guardedWrite()).
        $written = $this->guardedWrite(function () use ($value, $row, $col, &$invalid, &$cell, &$oldValue, &$oldState, &$rawValue, &$computedValue, &$formula) {
            // Find or create cell
            $cell = $this->cellsQuery()
                ->where('row_index', $row)
                ->where('col_index', $col)
                ->first();

            $oldValue = $cell?->raw_value;
            $oldState = [
                'raw_value' => $cell?->raw_value,
                'computed_value' => $cell?->computed_value,
                'formula' => $cell?->formula,
            ];

            $validationError = $this->validateCellInput($row, $col, (string) $value);
            if ($validationError !== null) {
                $this->formulaErrors["{$row}_{$col}"] = $validationError;
                $invalid = true;

                return;
            }

            unset($this->formulaErrors["{$row}_{$col}"]);

            $isFormula = str_starts_with($value, '=');

            if ($isFormula) {
                // Parse and evaluate formula
                $result = $this->evaluateFormula($value);
                $computedValue = $result['error'] ? '#ERROR' : $result['value'];
                $formula = $value;
                $rawValue = '';
            } else {
                $computedValue = null;
                $formula = null;
                $rawValue = $value;
            }

            // Update or create cell
            if ($cell) {
                $cell->update([
                    'raw_value' => $rawValue,
                    'computed_value' => $computedValue,
                    'formula' => $formula,
                ]);
            } else {
                $cell = Cell::create([
                    'spreadsheet_id' => $this->spreadsheet_id,
                    'sheet_tab_id' => $this->activeSheetTabId,
                    'row_index' => $row,
                    'col_index' => $col,
                    'raw_value' => $rawValue,
                    'computed_value' => $computedValue,
                    'formula' => $formula,
                    'updated_at' => now(),
                ]);
            }

            // Record in history
            CellHistory::create([
                'cell_id' => $cell->id,
                'user_id' => Auth::id(),
                'old_value' => $oldValue,
                'new_value' => $value,
                'created_at' => now(),
            ]);
        });

        if (! $written) {
            return;
        }

        if ($invalid) {
            $this->editingCell = null;
            $this->editingValue = '';

            return;
        }

        // Update local cache
        if (! isset($this->cellData[$row])) {
            $this->cellData[$row] = [];
        }
        $this->cellData[$row][$col] = [
            'id' => $cell->id,
            'raw_value' => $rawValue,
            'computed_value' => $computedValue,
            'formula' => $formula,
            'formatting' => $this->normalizeArrayValue($cell->formatting ?? []),
        ];

        // Clear editing state
        $this->editingCell = null;
        $this->editingValue = '';

        // Track undo/redo history for keyboard shortcuts
        $newState = [
            'raw_value' => $rawValue,
            'computed_value' => $computedValue,
            'formula' => $formula,
        ];
        $this->undoStack[] = [
            'row' => $row,
            'col' => $col,
            'old' => $oldState,
            'new' => $newState,
        ];
        $this->redoStack = [];
        if (count($this->undoStack) > 100) {
            array_shift($this->undoStack);
        }

        // Invalidate formula caches after any cell update
        Cache::forget("spreadsheet:{$this->spreadsheet_id}:tab:{$this->activeSheetTabId}:cell_values");
        Cache::forget("spreadsheet:{$this->spreadsheet_id}:tab:{$this->activeSheetTabId}:formula_dependency_graph");

        if (Auth::check()) {
            broadcast(new SpreadsheetCellUpdated(
                spreadsheetId: (int) $this->spreadsheet_id,
                sheetTabId: $this->activeSheetTabId ? (int) $this->activeSheetTabId : null,
                row: (int) $row,
                col: (int) $col,
                rawValue: $rawValue,
                computedValue: $computedValue,
                formula: $formula,
                userId: (int) Auth::id(),
                userName: (string) Auth::user()->name,
            ))->toOthers();
        }

        $this->evaluateAutomatedWorkflows($row, $col, $rawValue, $computedValue);

        $this->createAutoSnapshotIfNeeded();

        $this->dispatch('cell-saved', row: $row, col: $col);
    }

    #[On('external-cell-updated')]
    #[On('named-range-changed')]
    public function reloadFromBroadcast(): void
    {
        // A version restore (or another change that moved cells) bumped the version: reload as a whole.
        if ($this->staleReason() !== null) {
            $hadUndoHistory = $this->undoStack !== [] || $this->redoStack !== [];
            $this->resyncAfterStructureChange();
            $this->structureNotice = $hadUndoHistory ? 'Undo history was cleared because rows or columns moved.' : '';

            return;
        }

        $this->loadedWindows = [];
        $this->loadViewportCells(true);
    }

    #[On('insert-text-at-selected-cell')]
    public function insertTextAtSelectedCell($payload): void
    {
        $data = is_array($payload) ? $payload : [];
        if (array_is_list($data) && isset($data[0]) && is_array($data[0])) {
            $data = $data[0];
        }

        $text = (string) ($data['text'] ?? '');
        if ($text === '') {
            return;
        }

        $this->editingCell = "{$this->selectedRow}_{$this->selectedCol}";
        $this->saveCell($text);
    }

    protected function createAutoSnapshotIfNeeded(): void
    {
        $latest = SpreadsheetVersion::where('spreadsheet_id', $this->spreadsheet_id)
            ->where('sheet_tab_id', $this->activeSheetTabId)
            ->latest()
            ->first();

        if ($latest && $latest->created_at && $latest->created_at->gt(now()->subMinutes(5))) {
            return;
        }

        $cells = $this->cellsQuery()
            ->get(['row_index', 'col_index', 'raw_value', 'computed_value', 'formula', 'formatting'])
            ->toArray();

        SpreadsheetVersion::create([
            'spreadsheet_id' => $this->spreadsheet_id,
            'sheet_tab_id' => $this->activeSheetTabId,
            'user_id' => Auth::id(),
            'label' => 'Auto Snapshot',
            'cells_snapshot' => $cells,
        ]);
    }

    public function evaluateFormula($formula)
    {
        $evaluator = new FormulaEvaluatorService;

        return $evaluator->evaluate($formula, $this->spreadsheet_id, $this->activeSheetTabId);
    }

    /**
     * Save current formula-bar value into the selected cell.
     */
    public function saveFromFormulaBar($value): void
    {
        $this->editingCell = "{$this->selectedRow}_{$this->selectedCol}";
        $this->saveCell((string) $value);
    }

    /**
     * Paste plain text into the selected cell.
     */
    public function pasteIntoSelectedCell($value): void
    {
        $this->editingCell = "{$this->selectedRow}_{$this->selectedCol}";
        $this->saveCell((string) $value);
    }

    /**
     * Paste matrix content using queued batch jobs for large payloads.
     */
    public function bulkPasteFromText(string $text): array
    {
        $this->authorize('update', $this->spreadsheet);

        if ($this->refuseWhenStructureIsStale()) {
            return ['started' => false, 'message' => StructureVersion::STALE_MESSAGE];
        }

        $matrix = $this->parsePastedText($text);
        if (empty($matrix)) {
            return ['started' => false, 'message' => 'No paste data found.'];
        }

        $startRow = (int) $this->selectedRow;
        $startCol = (int) $this->selectedCol;

        $cellPayload = [];
        foreach ($matrix as $rOffset => $rowValues) {
            foreach ($rowValues as $cOffset => $raw) {
                $value = (string) $raw;
                $value = rtrim($value, "\r");
                if ($value === '') {
                    continue;
                }

                $targetRow = $startRow + $rOffset;
                $targetCol = $startCol + $cOffset;
                if ($targetRow >= $this->totalRows || $targetCol >= $this->totalCols) {
                    continue;
                }

                $cellPayload[] = [
                    'row_index' => $targetRow,
                    'col_index' => $targetCol,
                    'value' => $value,
                ];
            }
        }

        if (empty($cellPayload)) {
            return ['started' => false, 'message' => 'No writable cells in paste payload.'];
        }

        // Small payloads skip queue overhead and apply immediately.
        if (count($cellPayload) <= 25) {
            foreach ($cellPayload as $cell) {
                $this->editingCell = "{$cell['row_index']}_{$cell['col_index']}";
                $this->saveCell($cell['value']);
            }

            return [
                'started' => false,
                'message' => 'Paste applied immediately.',
                'cells' => count($cellPayload),
            ];
        }

        $chunkSize = 500;
        $chunks = array_chunk($cellPayload, $chunkSize);
        $jobs = [];
        foreach ($chunks as $chunk) {
            $jobs[] = new BulkPasteChunkJob($this->spreadsheet_id, $this->activeSheetTabId, $chunk, Auth::id(), $this->structureVersion);
        }

        $batch = Bus::batch($jobs)
            ->name("bulk-paste:spreadsheet:{$this->spreadsheet_id}")
            ->allowFailures()
            ->dispatch();

        $this->bulkPasteBatchId = $batch->id;
        $this->bulkPasteStatus = 'running';
        $this->bulkPasteTotalJobs = count($jobs);
        $this->bulkPasteProcessedJobs = 0;

        return [
            'started' => true,
            'batch_id' => $batch->id,
            'total_jobs' => count($jobs),
            'cells' => count($cellPayload),
        ];
    }

    /**
     * Poll current bulk paste batch status.
     */
    public function getBulkPasteBatchStatus(): array
    {
        if (! $this->bulkPasteBatchId) {
            return ['status' => 'idle'];
        }

        $batch = Bus::findBatch($this->bulkPasteBatchId);
        if (! $batch) {
            return ['status' => 'missing'];
        }

        $processed = $batch->processedJobs();
        $total = max(1, $batch->totalJobs);
        $progress = (int) floor(($processed / $total) * 100);

        if ($batch->finished()) {
            $this->bulkPasteStatus = $batch->cancelled() ? 'cancelled' : 'finished';

            if ($batch->cancelled() && Cache::get(BulkPasteChunkJob::cancelledKey($batch->id)) === 'structure') {
                $this->structureNotice = 'The paste was cancelled because rows or columns moved while it was running. Part of it may already be on the sheet: check it and paste again.';
            }
            $this->bulkPasteProcessedJobs = $processed;

            // Refresh local caches once batch completes.
            $this->loadedWindows = [];
            $this->loadViewportCells(true);

            Cache::forget("spreadsheet:{$this->spreadsheet_id}:tab:{$this->activeSheetTabId}:cell_values");
            Cache::forget("spreadsheet:{$this->spreadsheet_id}:tab:{$this->activeSheetTabId}:formula_dependency_graph");

            return [
                'status' => $this->bulkPasteStatus,
                'processed' => $processed,
                'total' => $total,
                'progress' => 100,
            ];
        }

        $this->bulkPasteProcessedJobs = $processed;

        return [
            'status' => 'running',
            'processed' => $processed,
            'total' => $total,
            'progress' => $progress,
        ];
    }

    protected function parsePastedText(string $text): array
    {
        $text = trim($text);
        if ($text === '') {
            return [];
        }

        $lines = preg_split('/\r\n|\n|\r/', $text) ?: [];
        $matrix = [];
        foreach ($lines as $line) {
            // Tab-delimited from spreadsheet apps, fallback to CSV parse for comma-delimited.
            if (str_contains($line, "\t")) {
                $matrix[] = explode("\t", $line);
            } else {
                $matrix[] = str_getcsv($line);
            }
        }

        return $matrix;
    }

    public function undoAction(): void
    {
        $this->authorize('update', $this->spreadsheet);

        if ($this->refuseWhenStructureIsStale()) {
            return;
        }

        $entry = array_pop($this->undoStack);
        if (! $entry) {
            return;
        }

        $this->applyCellState($entry['row'], $entry['col'], $entry['old']);
        $this->redoStack[] = $entry;
    }

    public function redoAction(): void
    {
        $this->authorize('update', $this->spreadsheet);

        if ($this->refuseWhenStructureIsStale()) {
            return;
        }

        $entry = array_pop($this->redoStack);
        if (! $entry) {
            return;
        }

        $this->applyCellState($entry['row'], $entry['col'], $entry['new']);
        $this->undoStack[] = $entry;
    }

    #[On('apply-script-actions')]
    public function applyScriptActions($payload): array
    {
        $this->authorize('update', $this->spreadsheet);

        if ($this->refuseWhenStructureIsStale()) {
            return ['applied' => 0, 'message' => StructureVersion::STALE_MESSAGE];
        }

        $data = is_array($payload) ? $payload : [];
        if (array_is_list($data) && isset($data[0]) && is_array($data[0])) {
            $data = $data[0];
        }

        $actions = $data['actions'] ?? [];
        if (! is_array($actions) || empty($actions)) {
            return ['applied' => 0, 'message' => 'No actions provided'];
        }

        $applied = 0;
        foreach (array_slice($actions, 0, 1000) as $action) {
            if (! is_array($action)) {
                continue;
            }

            $type = strtolower((string) ($action['type'] ?? ''));
            $target = $this->resolveScriptTarget($action);
            if (! $target && $type !== 'noop') {
                continue;
            }

            if ($type === 'set') {
                $value = (string) ($action['value'] ?? '');
                $this->editingCell = "{$target['row']}_{$target['col']}";
                $this->saveCell($value);
                $applied++;

                continue;
            }

            if ($type === 'clear') {
                $this->applyCellState((int) $target['row'], (int) $target['col'], [
                    'raw_value' => null,
                    'computed_value' => null,
                    'formula' => null,
                ]);
                $applied++;

                continue;
            }

            if ($type === 'select') {
                $this->selectCell((int) $target['row'], (int) $target['col']);
                $applied++;
            }
        }

        return ['applied' => $applied];
    }

    protected function resolveScriptTarget(array $action): ?array
    {
        $row = $action['row'] ?? null;
        $col = $action['col'] ?? null;
        $cellRef = $action['cell'] ?? null;

        if (is_string($cellRef)) {
            return $this->parseCellReference($cellRef);
        }

        if (is_numeric($row) && is_numeric($col)) {
            $r = (int) $row;
            $c = (int) $col;
            if ($r < 0 || $c < 0 || $r >= $this->totalRows || $c >= $this->totalCols) {
                return null;
            }

            return ['row' => $r, 'col' => $c];
        }

        return null;
    }

    protected function parseCellReference(string $cellRef): ?array
    {
        $cellRef = strtoupper(trim($cellRef));
        if (! preg_match('/^([A-Z]{1,3})(\d+)$/', $cellRef, $matches)) {
            return null;
        }

        $col = self::colLetterToIndex($matches[1]);
        $row = ((int) $matches[2]) - 1;
        if ($row < 0 || $col < 0 || $row >= $this->totalRows || $col >= $this->totalCols) {
            return null;
        }

        return ['row' => $row, 'col' => $col];
    }

    protected function validateCellInput(int $row, int $col, string $value): ?string
    {
        foreach ($this->validationRules as $rule) {
            if (! is_array($rule)) {
                continue;
            }

            if (($rule['row'] ?? null) !== $row || ($rule['col'] ?? null) !== $col) {
                continue;
            }

            $type = (string) ($rule['type'] ?? '');
            if ($type === 'number') {
                if (! is_numeric($value)) {
                    return 'Validation failed: value must be numeric.';
                }

                $min = $rule['min'] ?? null;
                $max = $rule['max'] ?? null;
                $numeric = (float) $value;
                if ($min !== null && $numeric < (float) $min) {
                    return 'Validation failed: value is below minimum.';
                }
                if ($max !== null && $numeric > (float) $max) {
                    return 'Validation failed: value is above maximum.';
                }
            }

            if ($type === 'text_length') {
                $len = mb_strlen($value);
                $min = (int) ($rule['min'] ?? 0);
                $max = (int) ($rule['max'] ?? 999999);
                if ($len < $min || $len > $max) {
                    return 'Validation failed: text length out of range.';
                }
            }

            if ($type === 'list') {
                $items = array_values(array_filter(array_map('trim', explode(',', (string) ($rule['items'] ?? '')))));
                if (! empty($items) && ! in_array($value, $items, true)) {
                    return 'Validation failed: value not in allowed list.';
                }
            }
        }

        return null;
    }

    protected function applyConditionalFormattingRules(int $row, int $col, mixed $displayValue, array $formatting): array
    {
        foreach ($this->conditionalFormattingRules as $rule) {
            if (! is_array($rule)) {
                continue;
            }

            $target = (string) ($rule['target'] ?? 'cell');
            if ($target === 'cell' && (($rule['row'] ?? null) !== $row || ($rule['col'] ?? null) !== $col)) {
                continue;
            }
            if ($target === 'column' && (($rule['col'] ?? null) !== $col)) {
                continue;
            }

            if (! $this->matchCondition($displayValue, (string) ($rule['operator'] ?? '=='), $rule['value'] ?? null)) {
                continue;
            }

            $color = (string) ($rule['color'] ?? '#FEE2E2');
            if (preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) {
                $formatting['bg_color'] = $color;
            }
        }

        return $formatting;
    }

    protected function matchCondition(mixed $value, string $operator, mixed $expected): bool
    {
        if (in_array($operator, ['>', '>=', '<', '<='], true)) {
            if (! is_numeric($value) || ! is_numeric($expected)) {
                return false;
            }

            $lhs = (float) $value;
            $rhs = (float) $expected;

            return match ($operator) {
                '>' => $lhs > $rhs,
                '>=' => $lhs >= $rhs,
                '<' => $lhs < $rhs,
                '<=' => $lhs <= $rhs,
                default => false,
            };
        }

        if ($operator === 'contains') {
            return str_contains(strtolower((string) $value), strtolower((string) $expected));
        }

        if ($operator === '!=') {
            return (string) $value !== (string) $expected;
        }

        return (string) $value === (string) $expected;
    }

    #[On('insert-row-selected')]
    public function insertRowSelected(): void
    {
        $this->changeStructure('insert', 'row');
    }

    #[On('delete-row-selected')]
    public function deleteRowSelected(): void
    {
        $this->changeStructure('delete', 'row');
    }

    #[On('insert-column-selected')]
    public function insertColumnSelected(): void
    {
        $this->changeStructure('insert', 'col');
    }

    #[On('delete-column-selected')]
    public function deleteColumnSelected(): void
    {
        $this->changeStructure('delete', 'col');
    }

    #[On('toggle-hide-row-selected')]
    public function toggleHideRowSelected(): void
    {
        $row = (int) $this->selectedRow;
        if (in_array($row, $this->hiddenRows, true)) {
            $this->hiddenRows = array_values(array_filter($this->hiddenRows, fn ($r) => $r !== $row));
        } else {
            $this->hiddenRows[] = $row;
        }
        $this->saveFeatureSettings();
    }

    #[On('toggle-hide-column-selected')]
    public function toggleHideColumnSelected(): void
    {
        $col = (int) $this->selectedCol;
        if (in_array($col, $this->hiddenCols, true)) {
            $this->hiddenCols = array_values(array_filter($this->hiddenCols, fn ($c) => $c !== $col));
        } else {
            $this->hiddenCols[] = $col;
        }
        $this->saveFeatureSettings();
    }

    #[On('resize-row-selected')]
    public function resizeRowSelected($payload): void
    {
        $data = is_array($payload) ? $payload : [];
        if (array_is_list($data) && isset($data[0]) && is_array($data[0])) {
            $data = $data[0];
        }

        $height = max(18, min(120, (int) ($data['height'] ?? $this->rowHeight)));
        $row = isset($data['row']) ? (int) $data['row'] : (int) $this->selectedRow;
        $this->rowHeights[$row] = $height;
        $this->saveFeatureSettings();
    }

    #[On('resize-column-selected')]
    public function resizeColumnSelected($payload): void
    {
        $data = is_array($payload) ? $payload : [];
        if (array_is_list($data) && isset($data[0]) && is_array($data[0])) {
            $data = $data[0];
        }

        $width = max(48, min(420, (int) ($data['width'] ?? $this->colWidth)));
        $col = isset($data['col']) ? (int) $data['col'] : (int) $this->selectedCol;
        $this->colWidths[$col] = $width;
        $this->saveFeatureSettings();
    }

    #[On('add-validation-rule-selected')]
    public function addValidationRuleSelected($payload): void
    {
        $data = is_array($payload) ? $payload : [];
        if (array_is_list($data) && isset($data[0]) && is_array($data[0])) {
            $data = $data[0];
        }

        $this->validationRules[] = [
            'id' => (string) str()->uuid(),
            'row' => (int) $this->selectedRow,
            'col' => (int) $this->selectedCol,
            'type' => $data['type'] ?? 'number',
            'min' => $data['min'] ?? null,
            'max' => $data['max'] ?? null,
            'items' => $data['items'] ?? null,
        ];

        $this->saveFeatureSettings();
    }

    #[On('add-conditional-rule-selected')]
    public function addConditionalRuleSelected($payload): void
    {
        $data = is_array($payload) ? $payload : [];
        if (array_is_list($data) && isset($data[0]) && is_array($data[0])) {
            $data = $data[0];
        }

        $this->conditionalFormattingRules[] = [
            'id' => (string) str()->uuid(),
            'target' => $data['target'] ?? 'cell',
            'row' => (int) $this->selectedRow,
            'col' => (int) $this->selectedCol,
            'operator' => $data['operator'] ?? '>',
            'value' => $data['value'] ?? '0',
            'color' => $data['color'] ?? '#FEE2E2',
        ];

        $this->saveFeatureSettings();
    }

    #[On('add-filter-rule')]
    public function addFilterRule($payload): void
    {
        $data = is_array($payload) ? $payload : [];
        if (array_is_list($data) && isset($data[0]) && is_array($data[0])) {
            $data = $data[0];
        }

        $colLetter = strtoupper((string) ($data['column'] ?? 'A'));
        $col = self::colLetterToIndex($colLetter);
        if ($col < 0) {
            return;
        }

        $this->filterRules[] = [
            'id' => (string) str()->uuid(),
            'col' => $col,
            'type' => $data['type'] ?? 'contains',
            'operator' => $data['operator'] ?? '>',
            'value' => $data['value'] ?? null,
            'value2' => $data['value2'] ?? null,
            'color' => $data['color'] ?? null,
        ];

        $this->applyFilterRules();
        $this->saveFeatureSettings();
    }

    #[On('clear-filter-rules')]
    public function clearFilterRules(): void
    {
        $this->filterRules = [];
        $this->hiddenRows = [];
        $this->saveFeatureSettings();
    }

    #[On('sort-selected-column')]
    public function sortSelectedColumn($payload): void
    {
        $data = is_array($payload) ? $payload : [];
        if (array_is_list($data) && isset($data[0]) && is_array($data[0])) {
            $data = $data[0];
        }

        $this->authorize('update', $this->spreadsheet);

        if ($this->refuseWhenStructureIsStale()) {
            return;
        }

        $direction = strtolower((string) ($data['direction'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';
        $targetCol = (int) $this->selectedCol;
        $sorted = false;

        // One transaction: the rows are parked at a high index while they swap, which nobody else may
        // see, and the structure version goes up with it, so a stale browser or an undo entry can not
        // land on a row that moved.
        $written = $this->guardedWrite(function () use ($direction, $targetCol, &$sorted) {
            $rows = $this->cellsQuery()
                ->select('row_index')
                ->distinct()
                ->orderBy('row_index')
                ->pluck('row_index')
                ->map(fn ($r) => (int) $r)
                ->values()
                ->toArray();

            if (count($rows) < 2) {
                return;
            }

            $values = [];
            foreach ($rows as $row) {
                $cell = $this->cellsQuery()
                    ->where('row_index', $row)
                    ->where('col_index', $targetCol)
                    ->first();
                $values[$row] = $cell?->computed_value ?? $cell?->raw_value ?? '';
            }

            usort($rows, function ($a, $b) use ($values, $direction) {
                $va = $values[$a] ?? '';
                $vb = $values[$b] ?? '';
                if (is_numeric($va) && is_numeric($vb)) {
                    $cmp = (float) $va <=> (float) $vb;
                } else {
                    $cmp = strnatcasecmp((string) $va, (string) $vb);
                }

                return $direction === 'desc' ? -$cmp : $cmp;
            });

            $startRow = min($rows);
            $tempOffset = 200000000;
            foreach ($rows as $idx => $oldRow) {
                $this->cellsQuery()
                    ->where('row_index', $oldRow)
                    ->update(['row_index' => $tempOffset + $idx]);
            }

            foreach ($rows as $idx => $_oldRow) {
                $this->cellsQuery()
                    ->where('row_index', $tempOffset + $idx)
                    ->update(['row_index' => $startRow + $idx]);
            }

            StructureVersion::bump((int) $this->spreadsheet_id);
            $sorted = true;
        });

        if (! $written || ! $sorted) {
            return;
        }

        $hadUndoHistory = $this->undoStack !== [] || $this->redoStack !== [];
        $this->resyncAfterStructureChange();
        $this->structureNotice = $hadUndoHistory ? 'Undo history was cleared because rows or columns moved.' : '';
    }

    protected function applyFilterRules(): void
    {
        if (empty($this->filterRules)) {
            $this->hiddenRows = [];

            return;
        }

        $rows = $this->cellsQuery()
            ->select('row_index')
            ->distinct()
            ->pluck('row_index')
            ->map(fn ($r) => (int) $r)
            ->values()
            ->toArray();

        $hidden = [];
        foreach ($rows as $row) {
            $keep = true;
            foreach ($this->filterRules as $rule) {
                $cell = $this->cellsQuery()
                    ->where('row_index', $row)
                    ->where('col_index', (int) ($rule['col'] ?? 0))
                    ->first();

                $value = $cell?->computed_value ?? $cell?->raw_value ?? '';
                $type = $rule['type'] ?? 'contains';
                if ($type === 'contains') {
                    if (! str_contains(strtolower((string) $value), strtolower((string) ($rule['value'] ?? '')))) {
                        $keep = false;
                        break;
                    }
                } elseif ($type === 'number_between') {
                    $min = (float) ($rule['value'] ?? 0);
                    $max = (float) ($rule['value2'] ?? INF);
                    if (! is_numeric($value) || (float) $value < $min || (float) $value > $max) {
                        $keep = false;
                        break;
                    }
                } elseif ($type === 'date_range') {
                    $from = strtotime((string) ($rule['value'] ?? ''));
                    $to = strtotime((string) ($rule['value2'] ?? ''));
                    $actual = strtotime((string) $value);
                    if (! $actual || ! $from || ! $to || $actual < $from || $actual > $to) {
                        $keep = false;
                        break;
                    }
                } elseif ($type === 'color') {
                    $fmtColor = $cell?->formatting['bg_color'] ?? null;
                    if (strtolower((string) $fmtColor) !== strtolower((string) ($rule['color'] ?? ''))) {
                        $keep = false;
                        break;
                    }
                } elseif ($type === 'condition') {
                    if (! $this->matchCondition($value, (string) ($rule['operator'] ?? '=='), $rule['value'] ?? null)) {
                        $keep = false;
                        break;
                    }
                }
            }

            if (! $keep) {
                $hidden[] = $row;
            }
        }

        $this->hiddenRows = array_values(array_unique($hidden));
    }

    protected function evaluateAutomatedWorkflows(int $row, int $col, mixed $rawValue, mixed $computedValue): void
    {
        $settings = $this->spreadsheet->settings ?? [];
        $rules = $settings['ai_workflows'] ?? [];
        if (! is_array($rules) || empty($rules)) {
            return;
        }

        $columnLetter = self::colIndexToLetter($col);
        $value = $computedValue ?? $rawValue;

        foreach ($rules as $rule) {
            if (! is_array($rule) || ! ($rule['enabled'] ?? true)) {
                continue;
            }

            $ruleColumn = strtoupper((string) ($rule['column'] ?? ''));
            if ($ruleColumn !== $columnLetter) {
                continue;
            }

            $operator = (string) ($rule['operator'] ?? '==');
            $expected = $rule['value'] ?? null;
            if (! $this->compareWorkflowCondition($value, $operator, $expected)) {
                continue;
            }

            $ruleId = (string) ($rule['id'] ?? md5(json_encode($rule)));
            $dedupeKey = "workflow:{$this->spreadsheet_id}:{$this->activeSheetTabId}:{$ruleId}:{$row}:{$col}:".md5((string) $value);
            if (Cache::has($dedupeKey)) {
                continue;
            }
            Cache::put($dedupeKey, true, 300);

            $channels = $this->resolveWorkflowNotificationChannels($rule);
            $this->dispatchWorkflowNotification($rule, $row, $col, $value, $channels);

            $status = 'triggered';

            $action = (string) ($rule['action'] ?? 'email_owner');
            if ($action === 'highlight_cell') {
                $this->highlightCellForWorkflow($row, $col);
                $status = 'highlighted';
            } elseif ($action === 'email_owner') {
                $status = 'notified';
            }

            $this->logWorkflowExecution($rule, $row, $col, $value, $channels, $status);
        }
    }

    protected function compareWorkflowCondition(mixed $actual, string $operator, mixed $expected): bool
    {
        $lhs = is_numeric($actual) ? (float) $actual : (string) $actual;
        $rhs = is_numeric($expected) ? (float) $expected : (string) $expected;

        return match ($operator) {
            '<' => $lhs < $rhs,
            '<=' => $lhs <= $rhs,
            '>' => $lhs > $rhs,
            '>=' => $lhs >= $rhs,
            '!=' => $lhs != $rhs,
            default => $lhs == $rhs,
        };
    }

    protected function resolveWorkflowNotificationChannels(array $rule): array
    {
        $channels = $rule['notify_channels'] ?? ['email'];
        if (! is_array($channels)) {
            return ['email'];
        }

        $allowed = ['email', 'database'];
        $filtered = array_values(array_intersect($channels, $allowed));

        if (empty($filtered)) {
            return ['email'];
        }

        return $filtered;
    }

    protected function dispatchWorkflowNotification(array $rule, int $row, int $col, mixed $value, array $channels): void
    {
        $owner = $this->spreadsheet->owner;
        if (! $owner) {
            return;
        }

        $cellRef = self::colIndexToLetter($col).($row + 1);
        $owner->notify(new WorkflowRuleTriggered(
            spreadsheetId: (int) $this->spreadsheet_id,
            spreadsheetName: (string) $this->spreadsheet->name,
            ruleName: (string) ($rule['name'] ?? 'Workflow Rule'),
            cellReference: $cellRef,
            actualValue: $value,
            channels: $channels,
        ));
    }

    protected function logWorkflowExecution(array $rule, int $row, int $col, mixed $value, array $channels, string $status): void
    {
        WorkflowExecutionLog::create([
            'spreadsheet_id' => $this->spreadsheet_id,
            'triggered_by_user_id' => Auth::id(),
            'rule_id' => (string) ($rule['id'] ?? md5(json_encode($rule))),
            'rule_name' => (string) ($rule['name'] ?? 'Workflow Rule'),
            'rule_action' => (string) ($rule['action'] ?? ''),
            'rule_operator' => (string) ($rule['operator'] ?? ''),
            'rule_expected_value' => (string) ($rule['value'] ?? ''),
            'row_index' => $row,
            'col_index' => $col,
            'cell_reference' => self::colIndexToLetter($col).($row + 1),
            'actual_value' => is_scalar($value) || $value === null ? (string) $value : json_encode($value),
            'notification_channels' => $channels,
            'status' => $status,
        ]);
    }

    protected function highlightCellForWorkflow(int $row, int $col): void
    {
        $cell = $this->cellsQuery()
            ->where('row_index', $row)
            ->where('col_index', $col)
            ->first();

        if (! $cell) {
            return;
        }

        $formatting = $cell->formatting ?? [];
        $formatting['backgroundColor'] = '#FEF08A';
        $cell->formatting = $formatting;
        $cell->save();

        if (! isset($this->cellData[$row])) {
            $this->cellData[$row] = [];
        }

        if (! isset($this->cellData[$row][$col])) {
            $this->cellData[$row][$col] = [
                'id' => $cell->id,
                'raw_value' => $cell->raw_value,
                'computed_value' => $cell->computed_value,
                'formula' => $cell->formula,
                'formatting' => $formatting,
            ];
        } else {
            $this->cellData[$row][$col]['formatting'] = $formatting;
        }
    }

    protected function applyCellState(int $row, int $col, array $state): void
    {
        $this->guardedWrite(function () use ($row, $col, $state) {
            $this->writeCellState($row, $col, $state);
        });
    }

    protected function writeCellState(int $row, int $col, array $state): void
    {
        $cell = $this->cellsQuery()
            ->where('row_index', $row)
            ->where('col_index', $col)
            ->first();

        $raw = $state['raw_value'] ?? null;
        $computed = $state['computed_value'] ?? null;
        $formula = $state['formula'] ?? null;

        if ($raw === null && $computed === null && $formula === null) {
            if ($cell) {
                $cell->delete();
            }
            unset($this->cellData[$row][$col]);
            if (empty($this->cellData[$row] ?? [])) {
                unset($this->cellData[$row]);
            }
        } else {
            if ($cell) {
                $cell->update([
                    'raw_value' => $raw,
                    'computed_value' => $computed,
                    'formula' => $formula,
                ]);
            } else {
                $cell = Cell::create([
                    'spreadsheet_id' => $this->spreadsheet_id,
                    'sheet_tab_id' => $this->activeSheetTabId,
                    'row_index' => $row,
                    'col_index' => $col,
                    'raw_value' => $raw,
                    'computed_value' => $computed,
                    'formula' => $formula,
                    'updated_at' => now(),
                ]);
            }

            if (! isset($this->cellData[$row])) {
                $this->cellData[$row] = [];
            }
            $this->cellData[$row][$col] = [
                'id' => $cell->id,
                'raw_value' => $raw,
                'computed_value' => $computed,
                'formula' => $formula,
                'formatting' => $this->normalizeArrayValue($cell->formatting ?? []),
            ];
        }

        Cache::forget("spreadsheet:{$this->spreadsheet_id}:tab:{$this->activeSheetTabId}:cell_values");
        Cache::forget("spreadsheet:{$this->spreadsheet_id}:tab:{$this->activeSheetTabId}:formula_dependency_graph");
        $this->dispatch('cell-saved', row: $row, col: $col);
    }

    public function ensureVisible($row, $col)
    {
        // Autoscroll: ensure cell is visible in viewport
        if ($row < $this->scrollRowOffset) {
            $this->scrollRowOffset = $row;
        } elseif ($row >= $this->scrollRowOffset + $this->viewportRows) {
            $this->scrollRowOffset = max(0, $row - $this->viewportRows + 1);
        }

        if ($col < $this->scrollColOffset) {
            $this->scrollColOffset = $col;
        } elseif ($col >= $this->scrollColOffset + $this->viewportCols) {
            $this->scrollColOffset = max(0, $col - $this->viewportCols + 1);
        }

        $this->loadViewportCells();
    }

    public function handleScroll($rowOffset = 0, $colOffset = 0)
    {
        $this->scrollRowOffset = max(0, min((int) $rowOffset, $this->totalRows - $this->viewportRows));
        $this->scrollColOffset = max(0, min((int) $colOffset, $this->totalCols - $this->viewportCols));
        $this->loadViewportCells();
    }

    #[On('key-navigate')]
    public function navigate($direction)
    {
        $row = $this->selectedRow;
        $col = $this->selectedCol;

        match ($direction) {
            'up' => $row = max(0, $row - 1),
            'down' => $row = min($this->totalRows - 1, $row + 1),
            'left' => $col = max(0, $col - 1),
            'right' => $col = min($this->totalCols - 1, $col + 1),
        };

        $this->selectCell($row, $col);
    }

    public function render()
    {
        $this->spreadsheet ??= Spreadsheet::findOrFail($this->spreadsheet_id);

        return view('livewire.show-spreadsheet', [
            'viewportData' => $this->getViewportData(),
            'spreadsheet' => $this->spreadsheet,
        ]);
    }

    protected function normalizeArrayValue(mixed $value): array
    {
        $normalized = $this->normalizeForLivewire($value);

        return is_array($normalized) ? $normalized : [];
    }

    protected function normalizeForLivewire(mixed $value): mixed
    {
        if (is_array($value)) {
            $normalized = [];
            foreach ($value as $key => $item) {
                $normalized[$key] = $this->normalizeForLivewire($item);
            }

            return $normalized;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        if (is_object($value)) {
            return $this->normalizeForLivewire((array) $value);
        }

        return $value;
    }
}
