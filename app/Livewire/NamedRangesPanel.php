<?php

namespace App\Livewire;

use App\Livewire\Concerns\ChecksSheetEditAccess;
use App\Livewire\Concerns\GuardsSheetState;
use App\Models\NamedRange;
use App\Models\Spreadsheet;
use App\Services\NameRefused;
use App\Services\SheetNames;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The classic editor's list of named ranges. The rules are in SheetNames.
 */
class NamedRangesPanel extends Component
{
    use AuthorizesRequests;
    use ChecksSheetEditAccess;
    use GuardsSheetState;

    #[Locked]
    public $spreadsheet_id;

    #[Locked]
    public $sheet_tab_id;

    public $ranges = [];

    public $name = '';

    public $rangeInput = '';

    public $error = '';

    protected function clientWritable(): array
    {
        return [
            'name',
            'rangeInput',
        ];
    }

    public function mount($spreadsheet_id, $sheet_tab_id = null)
    {
        $this->authorize('view', Spreadsheet::findOrFail($spreadsheet_id));

        $this->spreadsheet_id = $spreadsheet_id;
        $this->sheet_tab_id = $this->ownTabId($sheet_tab_id);
        $this->loadRanges();
    }

    public function render()
    {
        return view('livewire.named-ranges-panel', ['canEdit' => $this->canEditSheet()]);
    }

    public function loadRanges(): void
    {
        $this->ranges = NamedRange::where('spreadsheet_id', $this->spreadsheet_id)
            ->where('sheet_tab_id', $this->sheet_tab_id)
            ->orderBy('name')
            ->get()
            ->map(fn (NamedRange $range) => [
                'id' => $range->id,
                'name' => $range->name,
                'reference' => $range->toReferenceText(),
            ])
            ->toArray();
    }

    public function createRange(): void
    {
        $this->authorize('update', Spreadsheet::findOrFail($this->spreadsheet_id));

        $this->error = '';

        try {
            app(SheetNames::class)->add((int) $this->spreadsheet_id, (int) $this->sheet_tab_id, $this->name, $this->rangeInput);
        } catch (NameRefused $refused) {
            $this->error = $refused->getMessage();

            return;
        }

        $this->name = '';
        $this->rangeInput = '';
        $this->loadRanges();
        $this->dispatch('named-range-changed');
    }

    public function deleteRange($rangeId): void
    {
        $this->authorize('update', Spreadsheet::findOrFail($this->spreadsheet_id));

        $range = NamedRange::where('spreadsheet_id', $this->spreadsheet_id)
            ->where('sheet_tab_id', $this->sheet_tab_id)
            ->find($rangeId);

        if ($range) {
            app(SheetNames::class)->remove($range);
        }

        $this->loadRanges();
        $this->dispatch('named-range-changed');
    }
}
