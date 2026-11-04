<?php

namespace App\Http\Controllers;

use App\Models\NamedRange;
use App\Models\SheetTab;
use App\Models\Spreadsheet;
use App\Services\NameRefused;
use App\Services\SheetNames;
use App\Services\WriteLock;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Named ranges in the new editor. Every answer is the sheet's names as they now stand.
 */
class GridNameController extends Controller
{
    use AuthorizesRequests;

    public function __construct(private SheetNames $names) {}

    public function index(Spreadsheet $spreadsheet, SheetTab $sheetTab): JsonResponse
    {
        $this->authorizeTab('view', $spreadsheet, $sheetTab);

        return $this->answer($spreadsheet, $sheetTab);
    }

    public function store(Request $request, Spreadsheet $spreadsheet, SheetTab $sheetTab): JsonResponse
    {
        $this->authorizeTab('update', $spreadsheet, $sheetTab);

        $input = $request->validate(['name' => 'required|string|max:64', 'range' => 'required|string|max:40'], [
            'name.required' => 'Give it a name.',
            'name.max' => 'Use a shorter name: 64 characters at most.',
            'range.required' => 'Say which cells it stands for.',
            'range.max' => 'Range must look like A1 or A1:B10.',
        ]);

        try {
            return $this->writing($spreadsheet, $sheetTab, fn () => $this->names->add($spreadsheet->id, $sheetTab->id, $input['name'], $input['range']));
        } catch (NameRefused $refused) {
            return response()->json(['message' => $refused->getMessage()], 422);
        }
    }

    /** What deleting a name would break, so it can be said first. */
    public function uses(Spreadsheet $spreadsheet, SheetTab $sheetTab, NamedRange $namedRange): JsonResponse
    {
        $this->authorizeTab('update', $spreadsheet, $sheetTab);
        abort_unless($namedRange->sheet_tab_id === $sheetTab->id, 404);

        return response()->json(['formulas' => $this->names->usedBy($namedRange)]);
    }

    public function destroy(Spreadsheet $spreadsheet, SheetTab $sheetTab, NamedRange $namedRange): JsonResponse
    {
        $this->authorizeTab('update', $spreadsheet, $sheetTab);
        abort_unless($namedRange->sheet_tab_id === $sheetTab->id, 404);

        return $this->writing($spreadsheet, $sheetTab, fn () => $this->names->remove($namedRange));
    }

    private function writing(Spreadsheet $spreadsheet, SheetTab $sheetTab, callable $write): JsonResponse
    {
        $busy = false;
        WriteLock::guard($write, function () use (&$busy) {
            $busy = true;
        });

        return $busy ? response()->json(['message' => WriteLock::BUSY_MESSAGE], 503) : $this->answer($spreadsheet, $sheetTab);
    }

    private function answer(Spreadsheet $spreadsheet, SheetTab $sheetTab): JsonResponse
    {
        return response()->json(['names' => $this->names->of($spreadsheet->id, $sheetTab->id)]);
    }

    private function authorizeTab(string $ability, Spreadsheet $spreadsheet, SheetTab $sheetTab): void
    {
        $this->authorize($ability, $spreadsheet);
        abort_unless($sheetTab->spreadsheet_id === $spreadsheet->id, 404);
    }
}
