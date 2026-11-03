<?php

namespace App\Livewire\Concerns;

use App\Models\SheetTab;
use App\Models\Spreadsheet;
use Illuminate\Support\Facades\Gate;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;

/**
 * Keeps a sheet component's state on the server.
 *
 * Livewire sends public properties to the browser and accepts changes to them back. Only the
 * fields a person types into may change that way (clientWritable()); every other property, such
 * as which sheet, which tab, the loaded cells or the undo history, is set by the server alone and
 * a request that tries to change it is refused. Access to the sheet is checked again on every
 * request, so a share withdrawn mid-session stops working at once.
 */
trait GuardsSheetState
{
    /**
     * Top-level properties the browser may set: the wire:model and $set targets of the view.
     *
     * @return list<string>
     */
    abstract protected function clientWritable(): array;

    /**
     * The ability on the sheet that every request must still hold.
     */
    protected function sheetAbility(): string
    {
        return 'view';
    }

    public function hydrateGuardsSheetState(): void
    {
        if ($this->spreadsheet_id) {
            Gate::authorize($this->sheetAbility(), Spreadsheet::findOrFail($this->spreadsheet_id));
        }
    }

    /**
     * A tab id that came from outside the server (a mount argument, an event, an action argument),
     * accepted only when the tab belongs to this sheet. Null stays null: the sheet's first tab.
     */
    protected function ownTabId(mixed $tabId): ?int
    {
        if ($tabId === null || $tabId === '' || $tabId === 0 || $tabId === '0') {
            return null;
        }

        abort_unless(
            is_numeric($tabId) && SheetTab::whereKey((int) $tabId)->where('spreadsheet_id', $this->spreadsheet_id)->exists(),
            404
        );

        return (int) $tabId;
    }

    public function updatingGuardsSheetState(string $path, mixed $value): void
    {
        $property = explode('.', $path, 2)[0];

        if (! in_array($property, $this->clientWritable(), true)) {
            throw new CannotUpdateLockedPropertyException($property);
        }
    }
}
