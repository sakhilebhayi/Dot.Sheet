<?php

namespace App\Livewire;

use App\Livewire\Concerns\ChecksSheetEditAccess;
use App\Livewire\Concerns\GuardsSheetState;
use App\Livewire\Concerns\RefusesProtectedWorkbook;
use App\Models\SheetTab;
use App\Models\Spreadsheet;
use App\Models\SpreadsheetVersion;
use App\Services\SheetVersions;
use App\Services\WriteLock;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Component;

class VersionHistoryModal extends Component
{
    use AuthorizesRequests;
    use ChecksSheetEditAccess;
    use GuardsSheetState;
    use RefusesProtectedWorkbook;

    #[Locked]
    public $spreadsheet_id;

    #[Locked]
    public $sheet_tab_id;

    public $open = false;

    public $versions = [];

    public $snapshotLabel = '';

    protected $listeners = [
        'open-version-history' => 'openModal',
    ];

    protected function clientWritable(): array
    {
        return [
            'snapshotLabel',
        ];
    }

    public function mount($spreadsheet_id, $sheet_tab_id = null)
    {
        $this->authorize('view', Spreadsheet::findOrFail($spreadsheet_id));

        $this->spreadsheet_id = $spreadsheet_id;
        $this->sheet_tab_id = $this->ownTabId($sheet_tab_id);
        $this->loadVersions();
    }

    public function render()
    {
        return view('livewire.version-history-modal', ['canEdit' => $this->canEditSheet()]);
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
        // The list needs a few columns, never the snapshots themselves.
        $this->versions = SpreadsheetVersion::with('user:id,name')
            ->where('spreadsheet_id', $this->spreadsheet_id)
            ->where('sheet_tab_id', $this->sheet_tab_id)
            ->latest()
            ->limit(50)
            ->get(['id', 'user_id', 'label', 'kind', 'cell_count', 'created_at'])
            ->toArray();
    }

    public function createSnapshot(): void
    {
        $spreadsheet = Spreadsheet::findOrFail($this->spreadsheet_id);
        $this->authorize('update', $spreadsheet);

        $label = trim($this->snapshotLabel) !== '' ? Str::limit(trim($this->snapshotLabel), 120, '') : 'Manual Snapshot';
        app(SheetVersions::class)->snapshot((int) $this->spreadsheet_id, (int) $this->sheet_tab_id, Auth::id(), SheetVersions::MANUAL, $label);

        $this->snapshotLabel = '';
        $this->loadVersions();
    }

    public function restoreVersion($versionId): void
    {
        $spreadsheet = Spreadsheet::findOrFail($this->spreadsheet_id);
        $this->authorize('update', $spreadsheet);

        $version = SpreadsheetVersion::where('spreadsheet_id', $this->spreadsheet_id)
            ->where('sheet_tab_id', $this->sheet_tab_id)
            ->find($versionId);

        // The tab (and its versions) can be deleted from another browser while this modal is open.
        if ($version === null || ! SheetTab::whereKey($this->sheet_tab_id)->exists()) {
            $this->open = false;
            $this->dispatch('structure-stale');

            return;
        }

        $busy = false;
        WriteLock::guard(fn () => DB::transaction(function () use ($version) {
            app(SheetVersions::class)->restore($version, (int) Auth::id());
        }), function () use (&$busy) {
            $busy = true;
        });

        if ($busy) {
            $this->open = false;
            $this->dispatch('sheet-busy');

            return;
        }

        $this->dispatch('external-cell-updated');
        $this->loadVersions();
        $this->open = false;
    }
}
