<?php

namespace App\Http\Controllers;

use App\Models\Cell;
use App\Models\Spreadsheet;
use App\Models\SpreadsheetInvitation;
use App\Services\SpreadsheetImportExportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;

class SpreadsheetController extends Controller
{
    /**
     * Display a listing of spreadsheets
     */
    public function index()
    {
        $user = auth()->user();

        // Get user's own spreadsheets and shared spreadsheets
        $spreadsheets = Spreadsheet::where('owner_id', $user->id)
            ->orWhereHas('sharedUsers', function ($query) use ($user) {
                $query->where('user_id', $user->id);
            })
            ->orWhereHas('team', function ($query) use ($user) {
                $query->whereHas('users', function ($q) use ($user) {
                    $q->where('user_id', $user->id);
                });
            })
            ->latest()
            ->get();

        return view('spreadsheets.index', ['spreadsheets' => $spreadsheets]);
    }

    /**
     * Show the form for creating a new spreadsheet
     */
    public function create()
    {
        return view('spreadsheets.create');
    }

    /**
     * Store a newly created spreadsheet
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'team_id' => 'nullable|integer|exists:teams,id',
        ]);

        $user = auth()->user();

        // Verify team ownership if team_id provided
        if ($validated['team_id']) {
            $user->teams()->findOrFail($validated['team_id']);
        }

        $spreadsheet = Spreadsheet::create([
            'uuid' => (string) Str::uuid(),
            'owner_id' => $user->id,
            'team_id' => $validated['team_id'] ?? null,
            'name' => $validated['name'],
            'settings' => [],
        ]);

        return redirect()->route('spreadsheets.show', $spreadsheet)
            ->with('success', 'Spreadsheet created successfully!');
    }

    /**
     * Display the specified spreadsheet
     */
    public function show(Spreadsheet $spreadsheet)
    {
        $this->authorize('view', $spreadsheet);

        return view('spreadsheets.show', ['spreadsheet' => $spreadsheet]);
    }

    /**
     * Show the form for editing the spreadsheet
     */
    public function edit(Spreadsheet $spreadsheet)
    {
        $this->authorize('update', $spreadsheet);

        return view('spreadsheets.edit', ['spreadsheet' => $spreadsheet]);
    }

    /**
     * Update the specified spreadsheet
     */
    public function update(Request $request, Spreadsheet $spreadsheet)
    {
        $this->authorize('update', $spreadsheet);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'settings' => 'nullable|array',
        ]);

        $spreadsheet->update($validated);

        return redirect()->route('spreadsheets.show', $spreadsheet)
            ->with('success', 'Spreadsheet updated successfully!');
    }

    /**
     * Delete the specified spreadsheet
     */
    public function destroy(Spreadsheet $spreadsheet)
    {
        $this->authorize('delete', $spreadsheet);

        $name = $spreadsheet->name;
        $spreadsheet->delete();

        return redirect()->route('spreadsheets.index')
            ->with('success', "Spreadsheet '{$name}' deleted successfully!");
    }

    /**
     * Import CSV or Excel file into a spreadsheet
     */
    public function import(Request $request, Spreadsheet $spreadsheet)
    {
        $this->authorize('update', $spreadsheet);

        $request->validate([
            'file' => 'required|file|mimes:csv,txt,xlsx,xls|max:10240',
        ]);

        $service = new SpreadsheetImportExportService;
        $result = $service->importFile($spreadsheet, $request->file('file'));

        return response()->json($result);
    }

    /**
     * Poll queued import batch progress.
     */
    public function importBatchStatus(Request $request, Spreadsheet $spreadsheet, string $batchId)
    {
        $this->authorize('update', $spreadsheet);

        $batch = Bus::findBatch($batchId);
        if (! $batch) {
            return response()->json([
                'status' => 'missing',
                'batch_id' => $batchId,
            ], 404);
        }

        $total = max(1, (int) $batch->totalJobs);
        $processed = (int) $batch->processedJobs();
        $progress = (int) floor(($processed / $total) * 100);

        if ($batch->finished()) {
            return response()->json([
                'status' => $batch->cancelled() ? 'cancelled' : 'finished',
                'batch_id' => $batchId,
                'total' => $total,
                'processed' => $processed,
                'progress' => 100,
                'failed' => (int) $batch->failedJobs,
            ]);
        }

        return response()->json([
            'status' => 'running',
            'batch_id' => $batchId,
            'total' => $total,
            'processed' => $processed,
            'progress' => $progress,
            'failed' => (int) $batch->failedJobs,
        ]);
    }

    /**
     * Export spreadsheet as CSV download
     */
    public function exportCsv(Spreadsheet $spreadsheet)
    {
        $this->authorize('view', $spreadsheet);

        $service = new SpreadsheetImportExportService;
        $csv = $service->exportCsv($spreadsheet);

        $filename = \Str::slug($spreadsheet->name).'_'.now()->format('Ymd').'.csv';

        return response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    /**
     * Export spreadsheet as Excel download
     */
    public function exportExcel(Spreadsheet $spreadsheet)
    {
        $this->authorize('view', $spreadsheet);

        $service = new SpreadsheetImportExportService;
        $filePath = $service->exportExcel($spreadsheet);

        $filename = \Str::slug($spreadsheet->name).'_'.now()->format('Ymd').'.xlsx';

        return response()->download($filePath, $filename)->deleteFileAfterSend();
    }

    /**
     * Accept an external spreadsheet invitation.
     */
    public function acceptInvitation(Request $request, string $token)
    {
        $invitation = SpreadsheetInvitation::where('token', $token)
            ->whereNull('accepted_at')
            ->firstOrFail();

        if ($invitation->isExpired()) {
            return redirect()->route('spreadsheets.index')
                ->with('error', 'This invitation has expired.');
        }

        $user = $request->user();
        if (strtolower($user->email) !== strtolower($invitation->email)) {
            return redirect()->route('spreadsheets.index')
                ->with('error', 'Please sign in with the invited email address to accept this invitation.');
        }

        $spreadsheet = $invitation->spreadsheet;
        $spreadsheet->sharedUsers()->syncWithoutDetaching([
            $user->id => ['permission' => $invitation->permission],
        ]);

        $invitation->accepted_at = now();
        $invitation->save();

        return redirect()->route('spreadsheets.show', $spreadsheet)
            ->with('success', 'Invitation accepted. You now have access to this spreadsheet.');
    }

    /**
     * Public view-only spreadsheet endpoint by token.
     */
    public function publicShow(string $token)
    {
        $spreadsheet = Spreadsheet::where('public_token', $token)
            ->where('public_enabled', true)
            ->firstOrFail();

        if ($spreadsheet->public_expires_at && $spreadsheet->public_expires_at->isPast()) {
            abort(410, 'This public link has expired.');
        }

        $cells = Cell::where('spreadsheet_id', $spreadsheet->id)->get();

        if ($cells->isEmpty()) {
            return view('spreadsheets.public-show', [
                'spreadsheet' => $spreadsheet,
                'grid' => [],
                'startRow' => 0,
                'endRow' => -1,
                'startCol' => 0,
                'endCol' => -1,
            ]);
        }

        $startRow = (int) $cells->min('row_index');
        $endRow = (int) $cells->max('row_index');
        $startCol = (int) $cells->min('col_index');
        $endCol = (int) $cells->max('col_index');

        // Guard max render size for public page.
        $endRow = min($endRow, $startRow + 200);
        $endCol = min($endCol, $startCol + 40);

        $map = [];
        foreach ($cells as $cell) {
            if ($cell->row_index < $startRow || $cell->row_index > $endRow || $cell->col_index < $startCol || $cell->col_index > $endCol) {
                continue;
            }
            $map[$cell->row_index][$cell->col_index] = $cell->computed_value ?? $cell->raw_value ?? '';
        }

        $grid = [];
        for ($r = $startRow; $r <= $endRow; $r++) {
            $grid[$r] = [];
            for ($c = $startCol; $c <= $endCol; $c++) {
                $grid[$r][$c] = $map[$r][$c] ?? '';
            }
        }

        return view('spreadsheets.public-show', [
            'spreadsheet' => $spreadsheet,
            'grid' => $grid,
            'startRow' => $startRow,
            'endRow' => $endRow,
            'startCol' => $startCol,
            'endCol' => $endCol,
        ]);
    }
}
