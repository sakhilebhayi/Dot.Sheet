<?php

namespace App\Http\Controllers;

use App\Models\Spreadsheet;
use App\Services\ApprovalRefused;
use App\Services\WorkbookApprovals;
use App\Services\WriteLock;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Approval of a controlled workbook. Keepers decide; anyone who can edit can ask. */
class GridApprovalController extends Controller
{
    use AuthorizesRequests;

    public function __construct(private WorkbookApprovals $approvals) {}

    public function index(Request $request, Spreadsheet $spreadsheet): JsonResponse
    {
        $this->authorize('view', $spreadsheet);

        return $this->answer($spreadsheet, $request);
    }

    public function ask(Request $request, Spreadsheet $spreadsheet): JsonResponse
    {
        $this->authorize('update', $spreadsheet);

        return $this->step($spreadsheet, fn (?string $note) => $this->approvals->request($spreadsheet, $request->user(), $note), $request);
    }

    public function decide(Request $request, Spreadsheet $spreadsheet): JsonResponse
    {
        $this->authorize('manageSharing', $spreadsheet);

        $approve = $request->validate(['approve' => 'required|boolean'])['approve'];

        return $this->step($spreadsheet, fn (?string $note) => $this->approvals->decide($spreadsheet, $request->user(), (bool) $approve, $note), $request);
    }

    public function reopen(Request $request, Spreadsheet $spreadsheet): JsonResponse
    {
        $this->authorize('manageSharing', $spreadsheet);

        return $this->step($spreadsheet, fn (?string $note) => $this->approvals->reopen($spreadsheet, $request->user(), $note), $request);
    }

    public function withdraw(Request $request, Spreadsheet $spreadsheet): JsonResponse
    {
        $this->authorize('update', $spreadsheet);

        $keeper = $request->user()->can('manageSharing', $spreadsheet);

        return $this->step($spreadsheet, fn () => $this->approvals->withdraw($spreadsheet, $request->user(), $keeper), $request);
    }

    private function step(Spreadsheet $spreadsheet, callable $step, Request $request): JsonResponse
    {
        $note = $request->validate(['note' => 'sometimes|nullable|string|max:500'])['note'] ?? null;

        $busy = false;
        try {
            WriteLock::guard(fn () => $step($note), function () use (&$busy) {
                $busy = true;
            });
        } catch (ApprovalRefused $refused) {
            return response()->json(['message' => $refused->getMessage()], 422);
        }

        return $busy ? response()->json(['message' => WriteLock::BUSY_MESSAGE], 503) : $this->answer($spreadsheet, $request);
    }

    private function answer(Spreadsheet $spreadsheet, Request $request): JsonResponse
    {
        return response()->json($this->approvals->record($spreadsheet->id, (int) $request->user()?->id));
    }
}
