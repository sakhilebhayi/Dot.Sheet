<?php

namespace App\Http\Controllers;

use App\Jobs\ImportRowsChunkJob;
use App\Livewire\ShowSpreadsheet;
use App\Models\Cell;
use App\Models\SheetTab;
use App\Models\Spreadsheet;
use App\Models\SpreadsheetInvitation;
use App\Services\ExportTooLargeException;
use App\Services\IdeaStarts;
use App\Services\ProtectionRefused;
use App\Services\SheetPage;
use App\Services\SheetPrint;
use App\Services\SheetProtections;
use App\Services\SheetVersions;
use App\Services\SpreadsheetImportExportService;
use App\Services\TemplateCatalogue;
use App\Services\WorkbookBuilder;
use App\Services\WorkbookImporter;
use App\Services\WorkbookWriter;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SpreadsheetController extends Controller
{
    /** Keys of the settings JSON a request may set. */
    /** Largest JSON size of one of those keys. */
    public const MAX_SETTING_BYTES = 100000;

    public const EDITABLE_SETTINGS_KEYS = SheetTab::LAYOUT_KEYS;

    /**
     * Display a listing of spreadsheets
     */
    public function index()
    {
        $user = auth()->user();

        // Everything this person can reach: their own sheets, sheets shared with them, their teams' sheets.
        $spreadsheets = Spreadsheet::where('owner_id', $user->id)
            ->orWhereHas('sharedUsers', function ($query) use ($user) {
                $query->where('user_id', $user->id);
            })
            ->orWhereIn('team_id', $user->allTeams()->pluck('id'))
            ->with('owner', 'team', 'sheetTabs:id,spreadsheet_id,name,sort_order')
            ->withCount([
                'cells',
                'sharedUsers',
                'cells as formulas_count' => fn ($query) => $query->whereNotNull('formula'),
                'cells as errors_count' => fn ($query) => $query->whereNotNull('formula_error'),
            ])
            ->withMax('cells', 'updated_at')
            ->get()
            ->each(function (Spreadsheet $spreadsheet) {
                // A cell's edit does not touch the sheet's own timestamp.
                $spreadsheet->setAttribute('edited_at', max($spreadsheet->updated_at, Carbon::parse($spreadsheet->cells_max_updated_at ?? $spreadsheet->updated_at)));
            })
            ->sortByDesc('edited_at')
            ->values();

        $first = $spreadsheets->first();

        return view('dashboard', [
            'spreadsheets' => $spreadsheets,
            'firstPrint' => $first ? app(SheetPrint::class)->of($first) : null,
        ]);
    }

    /**
     * The print of a spreadsheet, for the list of sheets.
     */
    public function printOf(Spreadsheet $spreadsheet, SheetPrint $print): JsonResponse
    {
        $this->authorize('view', $spreadsheet);

        return response()->json($print->of($spreadsheet));
    }

    /**
     * Show the form for creating a new spreadsheet
     */
    public function create(TemplateCatalogue $templates)
    {
        $closest = array_filter(array_map(fn (string $key) => $templates->find($key), (array) session('idea_keys', [])));

        return view('spreadsheets.create', ['groups' => $templates->grouped(), 'closest' => $closest, 'ideaSaid' => session('idea_said')]);
    }

    /**
     * Start from a template: a real workbook with its formulas in place and no figures in it.
     */
    public function fromTemplate(Request $request, TemplateCatalogue $templates, WorkbookBuilder $builder)
    {
        $validated = $request->validate([
            'template' => ['required', 'string', Rule::in(array_keys($templates->all()))],
            'name' => 'nullable|string|max:255',
            'team_id' => 'nullable|integer|exists:teams,id',
        ]);

        $template = $templates->find($validated['template']);
        $teamId = $this->teamToAddTo($validated['team_id'] ?? null);
        $name = trim((string) ($validated['name'] ?? '')) ?: $template['title'];

        return redirect()->route('spreadsheets.show', $builder->build($template, $name, $request->user(), $teamId));
    }

    /**
     * Start from a sentence. With AI switched on it builds something new; otherwise, or when what comes
     * back cannot be used, the page offers the closest templates and says so.
     */
    public function fromIdea(Request $request, IdeaStarts $ideas, WorkbookBuilder $builder)
    {
        $validated = $request->validate([
            'idea' => 'required|string|max:500',
            'name' => 'nullable|string|max:255',
            'team_id' => 'nullable|integer|exists:teams,id',
        ], ['idea.required' => 'Say what the spreadsheet is for.', 'idea.max' => 'Keep it to 500 characters.']);

        $teamId = $this->teamToAddTo($validated['team_id'] ?? null);
        $idea = trim($validated['idea']);

        if ($ideas->canBuild()) {
            $blueprint = $ideas->blueprintFor($idea);
            if ($blueprint !== null) {
                $name = trim((string) ($validated['name'] ?? '')) ?: Str::limit($idea, 60, '');

                return redirect()->route('spreadsheets.show', $builder->build($blueprint, $name, $request->user(), $teamId))
                    ->with('success', 'Built from your description. Check the formulas before you rely on them.');
            }
        }

        return redirect()->route('spreadsheets.create')->withInput()->with('idea_keys', array_column($ideas->closest($idea), 'key'))
            ->with('idea_said', $ideas->canBuild() ? 'That could not be built just now. These are the closest templates.' : 'Building from a description is not switched on yet. These are the closest templates.');
    }

    /**
     * Start from a file: a new workbook named after it. An Excel or ODS file comes across whole, every
     * sheet with its formulas and formatting, with a report of whatever it could not bring.
     */
    public function fromFile(Request $request, WorkbookImporter $importer)
    {
        $validated = $request->validate([
            // By extension: an ODS file reads as a plain zip to anything that sniffs it, and each reader
            // checks what it is given anyway.
            'file' => 'required|file|extensions:csv,tsv,xlsx,xls,ods|max:10240',
            'team_id' => 'nullable|integer|exists:teams,id',
        ], [
            'file.required' => 'Choose a file to start from.',
            'file.extensions' => 'Import a CSV, TSV, Excel or ODS file.',
            'file.max' => 'That file is larger than 10 MB.',
        ]);

        $file = $request->file('file');
        $teamId = $this->teamToAddTo($validated['team_id'] ?? null);
        $name = Str::limit(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME), 255, '') ?: 'Imported spreadsheet';
        $extension = strtolower($file->getClientOriginalExtension());

        if (in_array($extension, ['xlsx', 'xls', 'ods'], true)) {
            try {
                if ($importer->fits($file->getRealPath(), $extension)) {
                    $spreadsheet = $importer->import($file->getRealPath(), $extension, $name, $request->user(), $teamId);

                    return redirect()->route('spreadsheets.show', $spreadsheet)->with('import_report', true);
                }
            } catch (\Exception $e) {
                report($e);

                return back()->with('error', SpreadsheetImportExportService::UNREADABLE_MESSAGE);
            }
        }

        $spreadsheet = Spreadsheet::create([
            'uuid' => (string) Str::uuid(),
            'owner_id' => $request->user()->id,
            'team_id' => $teamId,
            'name' => $name,
            'settings' => [],
        ]);

        $result = (new SpreadsheetImportExportService)->importFile($spreadsheet, $file, $spreadsheet->defaultSheetTab());
        if (! ($result['success'] ?? false)) {
            $spreadsheet->delete();

            return back()->with('error', $result['error'] ?? SpreadsheetImportExportService::UNREADABLE_MESSAGE);
        }

        return redirect()->route('spreadsheets.show', $spreadsheet)
            ->with('success', in_array($extension, ['xlsx', 'xls', 'ods'], true) ? 'That file is too large to read whole, so its first sheet came in as values, without formulas or formatting.' : null);
    }

    /** A read-only member (viewer) may belong to a team but not add sheets to it. */
    private function teamToAddTo(?int $teamId): ?int
    {
        if ($teamId === null) {
            return null;
        }
        $team = auth()->user()->allTeams()->firstWhere('id', $teamId);
        abort_if($team === null, 404);
        $this->authorize('createSpreadsheet', $team);

        return $team->id;
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
        $teamId = $this->teamToAddTo($validated['team_id'] ?? null);

        $spreadsheet = Spreadsheet::create([
            'uuid' => (string) Str::uuid(),
            'owner_id' => $user->id,
            'team_id' => $teamId,
            'name' => $validated['name'],
            'settings' => [],
        ]);

        return redirect()->route('spreadsheets.show', $spreadsheet)
            ->with('success', 'Spreadsheet created successfully!');
    }

    /**
     * Display the specified spreadsheet
     */
    public function classic(Spreadsheet $spreadsheet)
    {
        $this->authorize('view', $spreadsheet);

        if (ShowSpreadsheet::protectedFor($spreadsheet)) {
            return redirect()->route('spreadsheets.show', $spreadsheet);
        }

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
            'sheet_tab_id' => ['nullable', 'integer', Rule::exists('sheet_tabs', 'id')->where('spreadsheet_id', $spreadsheet->id)],
        ]);

        $spreadsheet->update(['name' => $validated['name']]);

        // Only the layout keys can be set here, on one sheet (the first unless named), merged into what is
        // stored. Scripts, macros and workflows have their own panels (and scripts their own switch).
        if (! empty($validated['settings'])) {
            $layout = Arr::only($validated['settings'], self::EDITABLE_SETTINGS_KEYS);
            foreach ($layout as $key => $value) {
                if (strlen((string) json_encode($value)) > self::MAX_SETTING_BYTES) {
                    throw ValidationException::withMessages(["settings.{$key}" => 'This setting is too large (at most '.number_format(self::MAX_SETTING_BYTES).' bytes).']);
                }
            }
            if ($layout !== []) {
                abort_if(ShowSpreadsheet::protectedFor($spreadsheet), 403, 'This workbook has protected cells. Open it in the new editor.');
                $tabId = $validated['sheet_tab_id'] ?? $spreadsheet->defaultSheetTab()?->id;
                if ($tabId !== null) {
                    SheetTab::updateLayout((int) $tabId, fn (array $stored) => array_replace($stored, $layout));
                }
            }
        }

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
            'file' => 'required|file|extensions:csv,txt,tsv,xlsx,xls,ods|max:10240',
            'sheet_tab_id' => 'nullable|integer|exists:sheet_tabs,id',
        ], [
            'file.required' => 'Choose a file to import.',
            'file.extensions' => 'Import a CSV or Excel file.',
            'file.max' => 'That file is larger than 10 MB.',
        ]);

        $tab = $this->resolveSheetTab($request, $spreadsheet);
        $file = $request->file('file');
        if ($tab !== null) {
            try {
                app(SheetProtections::class)->refuseAny($spreadsheet, $tab->id, Auth::user());
            } catch (ProtectionRefused $refused) {
                return $request->expectsJson() ? response()->json(['message' => $refused->getMessage()], 422) : back()->with('error', $refused->getMessage());
            }
        }

        // An import writes over what is there, and nothing else can take it back.
        $copy = $tab !== null && SheetVersions::canCopyWhole($tab->id) && Cell::where('sheet_tab_id', $tab->id)->exists()
            ? app(SheetVersions::class)->snapshot($spreadsheet->id, $tab->id, (int) Auth::id(), SheetVersions::SAFETY, 'Before importing '.Str::limit($file->getClientOriginalName(), 80))
            : null;

        $importer = app(WorkbookImporter::class);
        $extension = strtolower($file->getClientOriginalExtension());
        try {
            $result = $tab !== null && in_array($extension, ['xlsx', 'xls', 'ods'], true) && $importer->fits($file->getRealPath(), $extension)
                ? $importer->intoSheet($file->getRealPath(), $extension, $spreadsheet, $tab)
                : (new SpreadsheetImportExportService)->importFile($spreadsheet, $file, $tab);
        } catch (\Exception $e) {
            report($e);
            $result = ['success' => false, 'error' => SpreadsheetImportExportService::UNREADABLE_MESSAGE];
        }

        if (! ($result['success'] ?? false)) {
            $copy?->delete();
            $copy = null;
        }

        return response()->json([...$result, 'copied' => $copy !== null]);
    }

    /**
     * Resolve which tab an import/export request targets. Falls back to
     * the spreadsheet's default tab when sheet_tab_id isn't given, and
     * ignores a sheet_tab_id that doesn't actually belong to this
     * spreadsheet rather than trusting it blindly.
     */
    protected function resolveSheetTab(Request $request, Spreadsheet $spreadsheet): ?SheetTab
    {
        $sheetTabId = $request->input('sheet_tab_id');
        if ($sheetTabId) {
            $tab = SheetTab::where('spreadsheet_id', $spreadsheet->id)->find($sheetTabId);
            if ($tab) {
                return $tab;
            }
        }

        return $spreadsheet->defaultSheetTab();
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
        $failed = (int) $batch->failedJobs;
        $processed = (int) $batch->processedJobs();
        $payload = ['batch_id' => $batchId, 'total' => $total, 'processed' => $processed, 'failed' => $failed];

        // Rows or columns moved while the import was queued: the rest was cancelled, say so.
        if (Cache::get(ImportRowsChunkJob::cancelledKey($batchId)) === 'structure') {
            return response()->json($payload + [
                'status' => 'cancelled', 'progress' => 100,
                'message' => 'The import was cancelled because rows or columns moved while it was running. Part of it may already be on the sheet: check it and import again.',
            ]);
        }

        // A failed chunk with allowFailures() leaves the batch "pending" for good (the failed job is never
        // counted as done), which showed as running forever. Nothing left to run but the failures: it is over.
        if ($failed > 0 && ((int) $batch->pendingJobs - $failed) <= 0) {
            if (! $batch->cancelled()) {
                $batch->cancel();
            }
            $rows = (int) Cache::get("import:{$batchId}:rows", 0);
            $chunkRows = max(1, (int) Cache::get("import:{$batchId}:chunk_rows", SpreadsheetImportExportService::MAX_ROWS_PER_CHUNK));
            $written = min($rows, max(0, $total - $failed) * $chunkRows);

            return response()->json($payload + [
                'status' => 'failed', 'progress' => 100, 'rows' => $rows,
                'message' => "Import failed after {$written} of {$rows} rows; the rows already written are kept.",
            ]);
        }

        $progress = (int) floor(($processed / $total) * 100);

        if ($batch->finished()) {
            return response()->json($payload + ['status' => $batch->cancelled() ? 'cancelled' : 'finished', 'progress' => 100]);
        }

        return response()->json($payload + ['status' => 'running', 'progress' => $progress]);
    }

    /**
     * Export spreadsheet as CSV download
     */
    public function exportCsv(Request $request, Spreadsheet $spreadsheet)
    {
        $this->authorize('view', $spreadsheet);

        $service = new SpreadsheetImportExportService;
        $csv = $service->exportCsv($spreadsheet, $this->resolveSheetTab($request, $spreadsheet));

        $filename = \Str::slug($spreadsheet->name).'_'.now()->format('Ymd').'.csv';

        return response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    public function exportTsv(Request $request, Spreadsheet $spreadsheet)
    {
        $this->authorize('view', $spreadsheet);

        $text = (new SpreadsheetImportExportService)->exportCsv($spreadsheet, $this->resolveSheetTab($request, $spreadsheet), "\t");
        $filename = \Str::slug($spreadsheet->name).'_'.now()->format('Ymd').'.tsv';

        return response($text, 200, ['Content-Type' => 'text/tab-separated-values', 'Content-Disposition' => "attachment; filename=\"{$filename}\""]);
    }

    /**
     * The whole workbook, every sheet, with its formulas and formatting.
     */
    public function exportWorkbook(Spreadsheet $spreadsheet, string $format, WorkbookWriter $writer)
    {
        $this->authorize('view', $spreadsheet);

        try {
            $path = $writer->write($spreadsheet, $format);
        } catch (ExportTooLargeException $e) {
            abort(413, $e->getMessage());
        }

        return response()->download($path, \Str::slug($spreadsheet->name).'_'.now()->format('Ymd').".{$format}")->deleteFileAfterSend();
    }

    /** The open sheet laid out for paper. Printing goes through the browser. */
    public function printSheet(Request $request, Spreadsheet $spreadsheet, SheetPage $pages)
    {
        $this->authorize('view', $spreadsheet);
        $tab = $this->resolveSheetTab($request, $spreadsheet);

        return view('spreadsheets.page', ['spreadsheet' => $spreadsheet, 'tab' => $tab, 'page' => $pages->of($spreadsheet, $tab), 'forPdf' => false]);
    }

    public function exportPdf(Request $request, Spreadsheet $spreadsheet, SheetPage $pages)
    {
        $this->authorize('view', $spreadsheet);
        $tab = $this->resolveSheetTab($request, $spreadsheet);
        $page = $pages->of($spreadsheet, $tab);
        abort_if($page['too_large'], 413, 'This sheet is too large for a PDF. Download it as Excel instead.');

        // Nothing it draws may reach out: the page holds what people typed.
        $pdf = new Dompdf(new Options(['isRemoteEnabled' => false, 'isPhpEnabled' => false, 'isJavascriptEnabled' => false]));
        $pdf->loadHtml(view('spreadsheets.page', ['spreadsheet' => $spreadsheet, 'tab' => $tab, 'page' => $page, 'forPdf' => true])->render());
        $pdf->setPaper('A4', 'landscape');
        $pdf->render();

        $filename = \Str::slug($spreadsheet->name).'_'.\Str::slug((string) $tab->name).'.pdf';

        return response((string) $pdf->output(), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => "attachment; filename=\"{$filename}\""]);
    }

    /**
     * Export spreadsheet as Excel download
     */
    public function exportExcel(Request $request, Spreadsheet $spreadsheet)
    {
        $this->authorize('view', $spreadsheet);

        $service = new SpreadsheetImportExportService;

        try {
            $filePath = $service->exportExcel($spreadsheet, $this->resolveSheetTab($request, $spreadsheet));
        } catch (ExportTooLargeException $e) {
            abort(413, $e->getMessage());
        }

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

        // A share link shows the first tab only. Cells of every tab used to be merged into one grid,
        // so values from different tabs landed on top of each other.
        $tab = $spreadsheet->defaultSheetTab();
        $cells = $tab
            ? Cell::where('spreadsheet_id', $spreadsheet->id)->where('sheet_tab_id', $tab->id)->get()
            : collect();

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
