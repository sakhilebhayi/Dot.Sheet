<?php

namespace App\Http\Controllers;

use App\Models\SheetTab;
use App\Models\Spreadsheet;
use App\Services\DuplicateRefused;
use App\Services\WorkbookDuplicates;
use App\Services\WorkbookSheets;
use App\Services\WriteLock;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Duplicate a sheet inside its workbook, or a whole workbook into one of your own. */
class GridDuplicateController extends Controller
{
    use AuthorizesRequests;

    public function __construct(private WorkbookDuplicates $duplicates, private WorkbookSheets $sheets) {}

    public function sheet(Spreadsheet $spreadsheet, SheetTab $sheetTab): JsonResponse
    {
        $this->authorize('update', $spreadsheet);
        abort_unless($sheetTab->spreadsheet_id === $spreadsheet->id, 404);

        try {
            $copy = $this->making(fn () => $this->duplicates->sheet($sheetTab));
        } catch (DuplicateRefused $refused) {
            return response()->json(['message' => $refused->getMessage()], 422);
        }

        return $copy === null
            ? response()->json(['message' => WriteLock::BUSY_MESSAGE], 503)
            : response()->json(['tab' => $copy->id, 'tabs' => $this->sheets->listOf($spreadsheet)]);
    }

    /** Anyone who can open a workbook can already download every sheet of it, so anyone can do this. */
    public function workbook(Request $request, Spreadsheet $spreadsheet): JsonResponse|RedirectResponse
    {
        $this->authorize('view', $spreadsheet);

        try {
            $copy = $this->making(fn () => $this->duplicates->workbook($spreadsheet, $request->user()));
        } catch (DuplicateRefused $refused) {
            return $this->answer($request, $refused->getMessage(), 422);
        }

        if ($copy === null) {
            return $this->answer($request, WriteLock::BUSY_MESSAGE, 503);
        }

        return $request->expectsJson()
            ? response()->json(['url' => route('spreadsheets.show', $copy)])
            : redirect()->route('spreadsheets.show', $copy);
    }

    /**
     * @template T
     *
     * @param  callable(): T  $make
     * @return T|null null when the sheet was busy
     */
    private function making(callable $make): mixed
    {
        $made = null;
        WriteLock::guard(function () use ($make, &$made) {
            $made = DB::transaction($make);
        }, fn () => null);

        return $made;
    }

    private function answer(Request $request, string $message, int $status): JsonResponse|RedirectResponse
    {
        return $request->expectsJson()
            ? response()->json(['message' => $message], $status)
            : back()->with('error', $message);
    }
}
