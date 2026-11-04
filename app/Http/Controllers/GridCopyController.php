<?php

namespace App\Http\Controllers;

use App\Models\SheetTab;
use App\Models\Spreadsheet;
use App\Models\SpreadsheetVersion;
use App\Services\SheetVersions;
use App\Services\WriteLock;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Saved copies of one sheet in the new editor. GridController::restoreCopy puts one back: it is the same
 * step undo takes after a delete.
 */
class GridCopyController extends Controller
{
    use AuthorizesRequests;

    /** Copies listed at once, as in the classic editor. */
    private const MAX_LISTED = 50;

    public function __construct(private SheetVersions $versions) {}

    public function index(Spreadsheet $spreadsheet, SheetTab $sheetTab): JsonResponse
    {
        $this->authorizeTab('view', $spreadsheet, $sheetTab);

        return $this->answer($sheetTab);
    }

    public function store(Request $request, Spreadsheet $spreadsheet, SheetTab $sheetTab): JsonResponse
    {
        $this->authorizeTab('update', $spreadsheet, $sheetTab);

        $input = $request->validate(['label' => 'present|nullable|string|max:120'], ['label.max' => 'Use a shorter name: 120 characters at most.']);
        $label = trim((string) $input['label']);

        $busy = false;
        WriteLock::guard(function () use ($spreadsheet, $sheetTab, $label) {
            $this->versions->snapshot($spreadsheet->id, $sheetTab->id, (int) Auth::id(), SheetVersions::MANUAL, $label === '' ? 'Manual Snapshot' : Str::limit($label, 120, ''));
        }, function () use (&$busy) {
            $busy = true;
        });

        return $busy ? response()->json(['message' => WriteLock::BUSY_MESSAGE], 503) : $this->answer($sheetTab);
    }

    /** What putting a copy back would change, so nobody has to do it blind. */
    public function changes(Spreadsheet $spreadsheet, SheetTab $sheetTab, SpreadsheetVersion $copy): JsonResponse
    {
        $this->authorizeTab('update', $spreadsheet, $sheetTab);
        abort_unless($copy->sheet_tab_id === $sheetTab->id, 404);

        return response()->json(['changes' => $this->versions->changes($copy)]);
    }

    private function answer(SheetTab $sheetTab): JsonResponse
    {
        $copies = fn () => SpreadsheetVersion::where('sheet_tab_id', $sheetTab->id);

        return response()->json([
            // A few columns, never the snapshots themselves.
            'copies' => $copies()->with('user:id,name')->latest()->orderByDesc('id')->limit(self::MAX_LISTED)
                ->get(['id', 'user_id', 'label', 'kind', 'cell_count', 'created_at'])
                ->map(fn (SpreadsheetVersion $copy) => [
                    'id' => $copy->id,
                    'name' => match (true) {
                        $copy->kind === SheetVersions::AUTO => 'Automatic copy',
                        $copy->label === 'Manual Snapshot' => 'Saved copy',
                        default => $copy->label,
                    },
                    'kind' => $copy->kind,
                    'who' => $copy->user?->name,
                    'at' => $copy->created_at?->toIso8601String(),
                    'cells' => (int) $copy->cell_count,
                ])->all(),
            'more' => max(0, $copies()->count() - self::MAX_LISTED),
        ]);
    }

    private function authorizeTab(string $ability, Spreadsheet $spreadsheet, SheetTab $sheetTab): void
    {
        $this->authorize($ability, $spreadsheet);
        abort_unless($sheetTab->spreadsheet_id === $spreadsheet->id, 404);
    }
}
