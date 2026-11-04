<?php

namespace App\Http\Controllers;

use App\Models\SheetTab;
use App\Models\Spreadsheet;
use App\Services\StructureChangeRefused;
use App\Services\WorkbookSheets;
use App\Services\WriteLock;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * How the sheets of a workbook are kept: their order, colour and whether they show. None of it touches a
 * cell, so it needs no check against the sheet's version. Every answer says how to put it back.
 */
class GridTabController extends Controller
{
    use AuthorizesRequests;

    public function __construct(private WorkbookSheets $sheets) {}

    public function colour(Request $request, Spreadsheet $spreadsheet, SheetTab $sheetTab): JsonResponse
    {
        $this->authorizeTab($spreadsheet, $sheetTab);

        $input = $request->validate(['colour' => ['present', 'nullable', Rule::in(WorkbookSheets::COLOURS)]]);
        $undo = ['kind' => 'colour', 'tab' => $sheetTab->id, 'colour' => $sheetTab->color];

        return $this->changing($spreadsheet, $undo, fn () => $sheetTab->update(['color' => $input['colour']]));
    }

    public function hidden(Request $request, Spreadsheet $spreadsheet, SheetTab $sheetTab): JsonResponse
    {
        $this->authorizeTab($spreadsheet, $sheetTab);

        $input = $request->validate(['hidden' => 'required|boolean']);
        $undo = ['kind' => 'hidden', 'tab' => $sheetTab->id, 'hidden' => (bool) $sheetTab->hidden];

        return $this->changing($spreadsheet, $undo, fn () => $this->sheets->setHidden($sheetTab, (bool) $input['hidden']));
    }

    public function order(Request $request, Spreadsheet $spreadsheet): JsonResponse
    {
        $this->authorize('update', $spreadsheet);

        $input = $request->validate(['order' => 'required|array|max:1000', 'order.*' => 'required|integer']);
        $undo = ['kind' => 'order', 'order' => array_column($this->sheets->listOf($spreadsheet), 'id')];

        return $this->changing($spreadsheet, $undo, fn () => $this->sheets->reorder($spreadsheet, array_map('intval', $input['order'])));
    }

    /**
     * @param  array<string, mixed>  $undo
     */
    private function changing(Spreadsheet $spreadsheet, array $undo, callable $change): JsonResponse
    {
        try {
            $busy = false;
            WriteLock::guard($change, function () use (&$busy) {
                $busy = true;
            });
        } catch (StructureChangeRefused $refused) {
            return response()->json(['message' => $refused->getMessage()], 422);
        }

        return $busy ? response()->json(['message' => WriteLock::BUSY_MESSAGE], 503) : response()->json(['tabs' => $this->sheets->listOf($spreadsheet), 'undo' => $undo]);
    }

    private function authorizeTab(Spreadsheet $spreadsheet, SheetTab $sheetTab): void
    {
        $this->authorize('update', $spreadsheet);
        abort_unless($sheetTab->spreadsheet_id === $spreadsheet->id, 404);
    }
}
