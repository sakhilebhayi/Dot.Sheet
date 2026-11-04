<?php

namespace App\Http\Controllers;

use App\Models\SheetProtection;
use App\Models\SheetTab;
use App\Models\Spreadsheet;
use App\Services\ProtectionRefused;
use App\Services\SheetProtections;
use App\Services\WriteLock;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Protected sheets and cells in the new editor. Every answer is what the sheet's protection now is.
 */
class GridProtectionController extends Controller
{
    use AuthorizesRequests;

    public function __construct(private SheetProtections $protections) {}

    public function index(Spreadsheet $spreadsheet, SheetTab $sheetTab): JsonResponse
    {
        $this->authorizeTab('view', $spreadsheet, $sheetTab);

        return $this->answer($sheetTab);
    }

    public function store(Request $request, Spreadsheet $spreadsheet, SheetTab $sheetTab): JsonResponse
    {
        $this->authorizeTab('manageSharing', $spreadsheet, $sheetTab);

        $input = $request->validate(['range' => 'nullable|string|max:40', 'label' => 'nullable|string|max:80'], [
            'range.max' => 'Range must look like A1 or A1:B10.',
            'label.max' => 'Use a shorter label: 80 characters at most.',
        ]);

        try {
            return $this->writing($sheetTab, fn () => ($input['range'] ?? null) === null
                ? $this->protections->protectSheet($spreadsheet, $sheetTab->id, (int) Auth::id())
                : $this->protections->protectRange($spreadsheet, $sheetTab->id, $input['range'], $input['label'] ?? null, (int) Auth::id()));
        } catch (ProtectionRefused $refused) {
            return response()->json(['message' => $refused->getMessage()], 422);
        }
    }

    public function destroy(Spreadsheet $spreadsheet, SheetTab $sheetTab, SheetProtection $protection): JsonResponse
    {
        $this->authorizeTab('manageSharing', $spreadsheet, $sheetTab);
        abort_unless($protection->sheet_tab_id === $sheetTab->id, 404);

        return $this->writing($sheetTab, fn () => $protection->delete());
    }

    private function writing(SheetTab $sheetTab, callable $write): JsonResponse
    {
        $busy = false;
        WriteLock::guard($write, function () use (&$busy) {
            $busy = true;
        });

        return $busy ? response()->json(['message' => WriteLock::BUSY_MESSAGE], 503) : $this->answer($sheetTab);
    }

    private function answer(SheetTab $sheetTab): JsonResponse
    {
        return response()->json(['protections' => $this->protections->of($sheetTab->id), 'state' => $this->protections->state($sheetTab->id)]);
    }

    private function authorizeTab(string $ability, Spreadsheet $spreadsheet, SheetTab $sheetTab): void
    {
        $this->authorize($ability, $spreadsheet);
        abort_unless($sheetTab->spreadsheet_id === $spreadsheet->id, 404);
    }
}
