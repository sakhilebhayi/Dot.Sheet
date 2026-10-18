<?php

namespace App\Livewire;

use App\Models\Cell;
use App\Models\Spreadsheet;
use App\Services\StructureVersion;
use App\Services\WriteLock;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

class SpreadsheetToolbar extends Component
{
    use AuthorizesRequests;

    public $spreadsheet_id;

    public $selected_row = 0;

    public $selected_col = 0;

    // Current cell formatting state
    public $bold = false;

    public $italic = false;

    public $underline = false;

    public $strike = false;

    public $font_size = 12;

    public $font_color = '#000000';

    public $bg_color = '#ffffff';

    public $align = 'left';

    public $number_format = 'general'; // general, currency, percent, number, date

    // Format painter
    public $formatPainterBuffer = null;

    public $formatPainterActive = false;

    // Row/column sizing controls
    public $row_height_input = 28;

    public $col_width_input = 96;

    // Validation controls
    public $validation_type = 'number';

    public $validation_min = '';

    public $validation_max = '';

    public $validation_items = '';

    // Conditional formatting controls
    public $conditional_target = 'cell';

    public $conditional_operator = '>';

    public $conditional_value = '0';

    public $conditional_color = '#FEE2E2';

    // Sort/filter controls
    public $sort_direction = 'asc';

    public $filter_column = 'A';

    public $filter_type = 'contains';

    public $filter_operator = '>';

    public $filter_value = '';

    public $filter_value2 = '';

    public $filter_color = '#FEE2E2';

    protected $listeners = [
        'cell-selected' => 'loadCellFormatting',
        'structure-synced' => 'syncStructureVersion',
    ];

    /**
     * The structure version (see StructureVersion) the editor's grid was at when this toolbar
     * last heard from it. Locked: the browser may not set it.
     */
    #[Locked]
    public int $structureVersion = 0;

    public function mount($spreadsheet_id)
    {
        $this->authorize('view', Spreadsheet::findOrFail($spreadsheet_id));

        $this->spreadsheet_id = $spreadsheet_id;
        $this->structureVersion = StructureVersion::current((int) $spreadsheet_id);
    }

    /**
     * The editor reloaded and says so (event structure-synced): take the stored version. Nothing the
     * browser sends is used.
     */
    /**
     * The tab the grid is showing, as last told by the editor (cell-selected, sheet-tab-switched); the
     * first tab until then. Formatting belongs to a cell of that tab, not of any tab.
     */
    #[Locked]
    public ?int $sheet_tab_id = null;

    protected function activeTabId(): ?int
    {
        return $this->sheet_tab_id ?? Spreadsheet::find($this->spreadsheet_id)?->defaultSheetTab()?->id;
    }

    #[On('sheet-tab-switched')]
    public function followTab($sheetTabId = null): void
    {
        $this->sheet_tab_id = $sheetTabId ? (int) $sheetTabId : null;
    }

    /**
     * Run one cell write after checking the structure version under the write lock. False (and an event
     * for the editor to tell the person) when the grid is stale or the database is busy.
     */
    protected function writeCell(callable $write): bool
    {
        $busy = false;
        $written = WriteLock::guard(
            fn () => StructureVersion::writeIfCurrent((int) $this->spreadsheet_id, $this->structureVersion, $write),
            function () use (&$busy) {
                $busy = true;

                return false;
            }
        );

        if ($busy) {
            $this->dispatch('sheet-busy');

            return false;
        }

        if (! $written) {
            $this->dispatch('structure-stale');

            return false;
        }

        return true;
    }

    public function syncStructureVersion(): void
    {
        $this->structureVersion = StructureVersion::current((int) $this->spreadsheet_id);
    }

    public function render()
    {
        return view('livewire.spreadsheet-toolbar');
    }

    /**
     * Load formatting from selected cell
     */
    public function loadCellFormatting($row, $col, $sheetTabId = null)
    {
        $this->selected_row = $row;
        $this->selected_col = $col;
        if ($sheetTabId) {
            $this->sheet_tab_id = (int) $sheetTabId;
        }

        $cell = Cell::where('spreadsheet_id', $this->spreadsheet_id)
            ->where('sheet_tab_id', $this->activeTabId())
            ->where('row_index', $row)
            ->where('col_index', $col)
            ->first();

        if ($cell && $cell->formatting) {
            $fmt = is_array($cell->formatting) ? $cell->formatting : json_decode($cell->formatting, true);
            $this->bold = $fmt['bold'] ?? false;
            $this->italic = $fmt['italic'] ?? false;
            $this->underline = $fmt['underline'] ?? false;
            $this->strike = $fmt['strike'] ?? false;
            $this->font_size = $fmt['font_size'] ?? 12;
            $this->font_color = $fmt['font_color'] ?? '#000000';
            $this->bg_color = $fmt['bg_color'] ?? '#ffffff';
            $this->align = $fmt['align'] ?? 'left';
            $this->number_format = $fmt['number_format'] ?? 'general';
            $this->row_height_input = 28;
            $this->col_width_input = 96;
        } else {
            $this->resetFormatting();
        }
    }

    /**
     * Toggle a boolean formatting property (bold, italic, etc.)
     */
    public function toggle($property)
    {
        if (in_array($property, ['bold', 'italic', 'underline', 'strike'])) {
            $this->$property = ! $this->$property;
            $this->applyFormatting();
        }
    }

    /**
     * Set alignment
     */
    public function setAlign($alignment)
    {
        if (in_array($alignment, ['left', 'center', 'right', 'justify'])) {
            $this->align = $alignment;
            $this->applyFormatting();
        }
    }

    /**
     * Set number format
     */
    public function setNumberFormat($format)
    {
        $allowed = ['general', 'number', 'currency', 'percent', 'date', 'time', 'text'];
        if (in_array($format, $allowed)) {
            $this->number_format = $format;
            $this->applyFormatting();
        }
    }

    /**
     * Update font size
     */
    public function updateFontSize($size)
    {
        $size = (int) $size;
        if ($size >= 6 && $size <= 72) {
            $this->font_size = $size;
            $this->applyFormatting();
        }
    }

    /**
     * Apply current formatting state to the selected cell
     */
    public function applyFormatting()
    {
        $this->authorize('update', Spreadsheet::findOrFail($this->spreadsheet_id));

        $formatting = [
            'bold' => $this->bold,
            'italic' => $this->italic,
            'underline' => $this->underline,
            'strike' => $this->strike,
            'font_size' => $this->font_size,
            'font_color' => $this->sanitizeColor($this->font_color),
            'bg_color' => $this->sanitizeColor($this->bg_color),
            'align' => $this->align,
            'number_format' => $this->number_format,
        ];

        $tabId = $this->activeTabId();
        $row = $this->selected_row;
        $col = $this->selected_col;

        // updated_at is NOT NULL and a cell belongs to a tab: a format on an empty cell used to fail on both.
        if (! $this->writeCell(fn () => Cell::updateOrCreate(
            [
                'spreadsheet_id' => $this->spreadsheet_id,
                'sheet_tab_id' => $tabId,
                'row_index' => $row,
                'col_index' => $col,
            ],
            ['formatting' => $formatting, 'updated_at' => now()]
        ))) {
            return;
        }

        // Notify grid to re-render this cell
        $this->dispatch('formatting-applied', [
            'row' => $this->selected_row,
            'col' => $this->selected_col,
            'formatting' => $formatting,
        ]);
    }

    /**
     * Clear all formatting on selected cell
     */
    public function clearFormatting()
    {
        $this->authorize('update', Spreadsheet::findOrFail($this->spreadsheet_id));

        $this->resetFormatting();

        $tabId = $this->activeTabId();
        $row = $this->selected_row;
        $col = $this->selected_col;

        if (! $this->writeCell(fn () => Cell::where('spreadsheet_id', $this->spreadsheet_id)
            ->where('sheet_tab_id', $tabId)
            ->where('row_index', $row)
            ->where('col_index', $col)
            ->update(['formatting' => null]))) {
            return;
        }

        $this->dispatch('formatting-applied', [
            'row' => $this->selected_row,
            'col' => $this->selected_col,
            'formatting' => null,
        ]);
    }

    public function captureFormatPainter(): void
    {
        $this->formatPainterBuffer = [
            'bold' => $this->bold,
            'italic' => $this->italic,
            'underline' => $this->underline,
            'strike' => $this->strike,
            'font_size' => $this->font_size,
            'font_color' => $this->sanitizeColor($this->font_color),
            'bg_color' => $this->sanitizeColor($this->bg_color),
            'align' => $this->align,
            'number_format' => $this->number_format,
        ];
        $this->formatPainterActive = true;
    }

    public function applyFormatPainter(): void
    {
        if (! is_array($this->formatPainterBuffer)) {
            return;
        }

        $this->bold = (bool) ($this->formatPainterBuffer['bold'] ?? false);
        $this->italic = (bool) ($this->formatPainterBuffer['italic'] ?? false);
        $this->underline = (bool) ($this->formatPainterBuffer['underline'] ?? false);
        $this->strike = (bool) ($this->formatPainterBuffer['strike'] ?? false);
        $this->font_size = (int) ($this->formatPainterBuffer['font_size'] ?? 12);
        $this->font_color = (string) ($this->formatPainterBuffer['font_color'] ?? '#000000');
        $this->bg_color = (string) ($this->formatPainterBuffer['bg_color'] ?? '#ffffff');
        $this->align = (string) ($this->formatPainterBuffer['align'] ?? 'left');
        $this->number_format = (string) ($this->formatPainterBuffer['number_format'] ?? 'general');

        $this->applyFormatting();
        $this->formatPainterActive = false;
    }

    public function insertRow(): void
    {
        $this->dispatch('insert-row-selected');
    }

    public function deleteRow(): void
    {
        $this->dispatch('delete-row-selected');
    }

    public function insertColumn(): void
    {
        $this->dispatch('insert-column-selected');
    }

    public function deleteColumn(): void
    {
        $this->dispatch('delete-column-selected');
    }

    public function toggleHideRow(): void
    {
        $this->dispatch('toggle-hide-row-selected');
    }

    public function toggleHideColumn(): void
    {
        $this->dispatch('toggle-hide-column-selected');
    }

    public function resizeRow(): void
    {
        $this->dispatch('resize-row-selected', ['height' => (int) $this->row_height_input]);
    }

    public function resizeColumn(): void
    {
        $this->dispatch('resize-column-selected', ['width' => (int) $this->col_width_input]);
    }

    public function addValidationRule(): void
    {
        $this->dispatch('add-validation-rule-selected', [
            'type' => $this->validation_type,
            'min' => $this->validation_min !== '' ? $this->validation_min : null,
            'max' => $this->validation_max !== '' ? $this->validation_max : null,
            'items' => $this->validation_items,
        ]);
    }

    public function addConditionalRule(): void
    {
        $this->dispatch('add-conditional-rule-selected', [
            'target' => $this->conditional_target,
            'operator' => $this->conditional_operator,
            'value' => $this->conditional_value,
            'color' => $this->sanitizeColor($this->conditional_color),
        ]);
    }

    public function sortSelectedColumn(): void
    {
        $this->dispatch('sort-selected-column', ['direction' => $this->sort_direction]);
    }

    public function addFilterRule(): void
    {
        $this->dispatch('add-filter-rule', [
            'column' => strtoupper(trim($this->filter_column)),
            'type' => $this->filter_type,
            'operator' => $this->filter_operator,
            'value' => $this->filter_value,
            'value2' => $this->filter_value2,
            'color' => $this->sanitizeColor($this->filter_color),
        ]);
    }

    public function clearFilters(): void
    {
        $this->dispatch('clear-filter-rules');
    }

    protected function resetFormatting()
    {
        $this->bold = false;
        $this->italic = false;
        $this->underline = false;
        $this->strike = false;
        $this->font_size = 12;
        $this->font_color = '#000000';
        $this->bg_color = '#ffffff';
        $this->align = 'left';
        $this->number_format = 'general';
    }

    /**
     * Validate hex color to prevent XSS
     */
    protected function sanitizeColor(string $color): string
    {
        if (preg_match('/^#[0-9A-Fa-f]{3}([0-9A-Fa-f]{3})?$/', $color)) {
            return $color;
        }

        return '#000000';
    }
}
