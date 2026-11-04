<?php

namespace App\Livewire;

use App\Livewire\Concerns\ChecksSheetEditAccess;
use App\Livewire\Concerns\GuardsSheetState;
use App\Models\PivotTable;
use App\Models\Spreadsheet;
use App\Services\PivotRefused;
use App\Services\PivotTableService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Locked;
use Livewire\Component;

class PivotTableBuilder extends Component
{
    use AuthorizesRequests;
    use ChecksSheetEditAccess;
    use GuardsSheetState;

    #[Locked]
    public $spreadsheet_id;

    #[Locked]
    public $sheet_tab_id;

    public $showModal = false;

    public $title = 'My Pivot Table';

    public $source_range = '';

    public $row_field = '';

    public $column_field = '';

    public $value_field = '';

    public $aggregation = 'sum';

    public $pivots = [];

    public $openPivotId = null;

    public $result = null;

    protected $rules = [
        'title' => 'required|string|max:100',
        'source_range' => ['required', 'regex:/^[A-Z]+\d+:[A-Z]+\d+$/i'],
        'row_field' => ['required', 'regex:/^[A-Z]{1,3}$/i'],
        'column_field' => ['nullable', 'regex:/^[A-Z]{1,3}$/i'],
        'value_field' => ['required', 'regex:/^[A-Z]{1,3}$/i'],
        'aggregation' => ['required', 'in:sum,count,average,min,max'],
    ];

    protected function clientWritable(): array
    {
        return [
            'aggregation',
            'column_field',
            'row_field',
            'source_range',
            'title',
            'value_field',
        ];
    }

    public function mount($spreadsheet_id, $sheet_tab_id = null)
    {
        $this->authorize('view', Spreadsheet::findOrFail($spreadsheet_id));

        $this->spreadsheet_id = $spreadsheet_id;
        $this->sheet_tab_id = $this->ownTabId($sheet_tab_id);
        $this->loadPivots();
    }

    public function render()
    {
        return view('livewire.pivot-table-builder', ['canEdit' => $this->canEditSheet()]);
    }

    public function loadPivots(): void
    {
        $this->pivots = PivotTable::where('spreadsheet_id', $this->spreadsheet_id)
            ->where('sheet_tab_id', $this->sheet_tab_id)
            ->orderByDesc('created_at')
            ->get()
            ->toArray();
    }

    public function openModal(): void
    {
        $this->reset(['title', 'source_range', 'row_field', 'column_field', 'value_field', 'aggregation']);
        $this->title = 'My Pivot Table';
        $this->aggregation = 'sum';
        $this->showModal = true;
    }

    public function closeModal(): void
    {
        $this->showModal = false;
    }

    public function savePivot(): void
    {
        $this->authorize('update', Spreadsheet::findOrFail($this->spreadsheet_id));

        $this->validate();

        try {
            $pivot = app(PivotTableService::class)->add((int) $this->spreadsheet_id, (int) $this->sheet_tab_id, $this->title, $this->source_range, $this->row_field, $this->column_field, $this->value_field, $this->aggregation);
        } catch (PivotRefused $refused) {
            $this->addError('source_range', $refused->getMessage());

            return;
        }

        $this->loadPivots();
        $this->showModal = false;
        $this->openPivot($pivot->id);
    }

    public function openPivot($pivotId): void
    {
        $pivot = PivotTable::where('spreadsheet_id', $this->spreadsheet_id)
            ->where('sheet_tab_id', $this->sheet_tab_id)
            ->find($pivotId);

        if (! $pivot) {
            return;
        }

        $this->openPivotId = $pivot->id;
        $this->result = app(PivotTableService::class)->compute($pivot);
    }

    public function closePivot(): void
    {
        $this->openPivotId = null;
        $this->result = null;
    }

    public function deletePivot($pivotId): void
    {
        $this->authorize('update', Spreadsheet::findOrFail($this->spreadsheet_id));

        PivotTable::where('spreadsheet_id', $this->spreadsheet_id)
            ->where('sheet_tab_id', $this->sheet_tab_id)
            ->where('id', $pivotId)
            ->delete();

        if ((int) $this->openPivotId === (int) $pivotId) {
            $this->closePivot();
        }

        $this->loadPivots();
    }
}
