<?php

namespace App\Livewire;

use App\Models\Cell;
use App\Models\SheetTab;
use App\Models\Spreadsheet;
use App\Models\SpreadsheetVersion;
use App\Services\StructureVersion;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

class VersionHistoryModal extends Component
{
    use AuthorizesRequests;

    public $spreadsheet_id;

    public $sheet_tab_id;

    public $open = false;

    public $versions = [];

    public $snapshotLabel = '';

    protected $listeners = [
        'open-version-history' => 'openModal',
    ];

    public function mount($spreadsheet_id, $sheet_tab_id = null)
    {
        $this->authorize('view', Spreadsheet::findOrFail($spreadsheet_id));

        $this->spreadsheet_id = $spreadsheet_id;
        $this->sheet_tab_id = $sheet_tab_id;
        $this->loadVersions();
    }

    public function render()
    {
        return view('livewire.version-history-modal');
    }

    public function openModal(): void
    {
        $this->open = true;
        $this->loadVersions();
    }

    public function closeModal(): void
    {
        $this->open = false;
    }

    public function loadVersions(): void
    {
        $this->versions = SpreadsheetVersion::with('user:id,name')
            ->where('spreadsheet_id', $this->spreadsheet_id)
            ->where('sheet_tab_id', $this->sheet_tab_id)
            ->latest()
            ->limit(50)
            ->get()
            ->toArray();
    }

    public function createSnapshot(): void
    {
        $spreadsheet = Spreadsheet::findOrFail($this->spreadsheet_id);
        if (! Auth::user()->can('update', $spreadsheet)) {
            return;
        }

        $cells = Cell::where('spreadsheet_id', $this->spreadsheet_id)
            ->where('sheet_tab_id', $this->sheet_tab_id)
            ->get(['row_index', 'col_index', 'raw_value', 'computed_value', 'formula', 'formatting'])
            ->toArray();

        SpreadsheetVersion::create([
            'spreadsheet_id' => $this->spreadsheet_id,
            'sheet_tab_id' => $this->sheet_tab_id,
            'user_id' => Auth::id(),
            'label' => trim($this->snapshotLabel) !== '' ? trim($this->snapshotLabel) : 'Manual Snapshot',
            'cells_snapshot' => $cells,
        ]);

        $this->snapshotLabel = '';
        $this->loadVersions();
    }

    public function restoreVersion($versionId): void
    {
        $spreadsheet = Spreadsheet::findOrFail($this->spreadsheet_id);
        if (! Auth::user()->can('update', $spreadsheet)) {
            return;
        }

        $version = SpreadsheetVersion::where('spreadsheet_id', $this->spreadsheet_id)
            ->where('sheet_tab_id', $this->sheet_tab_id)
            ->find($versionId);

        // The tab (and its versions) can be deleted from another browser while this modal is open.
        if ($version === null || ! SheetTab::whereKey($this->sheet_tab_id)->exists()) {
            $this->open = false;
            $this->dispatch('structure-stale');

            return;
        }

        DB::transaction(function () use ($version) {
            Cell::where('spreadsheet_id', $this->spreadsheet_id)
                ->where('sheet_tab_id', $this->sheet_tab_id)
                ->delete();

            foreach ($version->cells_snapshot ?? [] as $cell) {
                Cell::create([
                    'spreadsheet_id' => $this->spreadsheet_id,
                    'sheet_tab_id' => $this->sheet_tab_id,
                    'row_index' => $cell['row_index'],
                    'col_index' => $cell['col_index'],
                    'raw_value' => $cell['raw_value'] ?? null,
                    'computed_value' => $cell['computed_value'] ?? null,
                    'formula' => $cell['formula'] ?? null,
                    'formatting' => $cell['formatting'] ?? null,
                    'updated_at' => now(),
                ]);
            }

            // Every cell may have moved: editors that had the old grid open must reload.
            StructureVersion::bump((int) $this->spreadsheet_id);
        });

        $this->dispatch('external-cell-updated');
        $this->loadVersions();
        $this->open = false;
    }
}
