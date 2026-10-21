<?php

namespace App\Livewire\Concerns;

use App\Models\Spreadsheet;
use Illuminate\Support\Facades\Auth;

/**
 * Lets a panel hide its write controls from someone who may only read the sheet. A courtesy only:
 * every write action still authorizes on the server.
 */
trait ChecksSheetEditAccess
{
    protected function canEditSheet(): bool
    {
        $spreadsheet = $this->spreadsheet_id ? Spreadsheet::find($this->spreadsheet_id) : null;

        return $spreadsheet !== null && (Auth::user()?->can('update', $spreadsheet) ?? false);
    }
}
