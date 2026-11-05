<?php

namespace App\Http\Controllers;

use App\Models\Spreadsheet;
use App\Services\ExportTooLargeException;
use App\Services\Integrations\FilesRefused;
use App\Services\Integrations\WorkbookFilings;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Save a workbook to its business's Dot.Files workspace. The person waits, so a failure comes back as words. */
class GridFilingController extends Controller
{
    use AuthorizesRequests;

    public function store(Request $request, Spreadsheet $spreadsheet, WorkbookFilings $filings): JsonResponse
    {
        $this->authorize('update', $spreadsheet);

        try {
            return response()->json($filings->file($spreadsheet, $request->user()));
        } catch (FilesRefused $refused) {
            return response()->json(['message' => $refused->getMessage()], $refused->status === 409 ? 409 : 422);
        } catch (ExportTooLargeException $tooLarge) {
            return response()->json(['message' => $tooLarge->getMessage()], 413);
        }
    }
}
