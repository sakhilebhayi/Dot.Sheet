<?php

namespace App\Livewire\Concerns;

use App\Models\SheetProtection;
use App\Models\Spreadsheet;
use App\Services\SheetProtections;
use Illuminate\Support\Facades\Auth;

/**
 * The classic editor writes cells in too many places to guard one by one, so someone who can edit but is
 * not a keeper cannot work a workbook with protection in it there. The new editor guards each change.
 */
trait RefusesProtectedWorkbook
{
    public function hydrateRefusesProtectedWorkbook(): void
    {
        $spreadsheet = Spreadsheet::find($this->spreadsheet_id);
        abort_if($spreadsheet !== null && self::protectedFor($spreadsheet), 403, 'This workbook has protected cells. Open it in the new editor.');
    }

    public static function protectedFor(Spreadsheet $spreadsheet): bool
    {
        $user = Auth::user();

        return $user !== null && $user->can('update', $spreadsheet)
            && SheetProtection::where('spreadsheet_id', $spreadsheet->id)->exists()
            && ! app(SheetProtections::class)->isKeeper($user, $spreadsheet);
    }
}
