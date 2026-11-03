<?php

namespace App\Http\Controllers;

use App\Models\Cell;
use App\Models\SheetTab;
use App\Models\Spreadsheet;
use App\Services\CellWriter;
use App\Services\Formula\FunctionCatalogue;
use App\Services\Formula\Recalculator;
use App\Services\SheetStructureService;
use App\Services\SheetVersions;
use App\Services\StructureVersion;
use App\Services\WriteLock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * The browser-drawn editor: the page, and the small JSON API it works through. The server stays the
 * source of truth: every answer comes back from the engine, and every request is authorized on the
 * sheet it names.
 */
class GridController extends Controller
{
    /** Most rows one window of cells may ask for. */
    private const MAX_WINDOW_ROWS = 1000;

    /** Most edits one request may carry. */
    private const MAX_EDITS = 500;

    /** Most cells one format request may touch. */
    private const MAX_FORMATTED = 5000;

    /** Most cells the status line adds up on the server. */
    private const MAX_SUMMED = 200000;

    public function show(Spreadsheet $spreadsheet): View
    {
        $this->authorize('view', $spreadsheet);

        return view('spreadsheets.grid', [
            'spreadsheet' => $spreadsheet,
            'boot' => [
                'id' => $spreadsheet->id,
                'name' => $spreadsheet->name,
                'canEdit' => Auth::user()->can('update', $spreadsheet),
                'version' => StructureVersion::current($spreadsheet->id),
                'rows' => SheetStructureService::GRID_ROWS,
                'cols' => SheetStructureService::GRID_COLS,
                'tabs' => $spreadsheet->sheetTabs()->orderBy('sort_order')->orderBy('id')->get(['id', 'name'])->toArray(),
                'functions' => array_map(fn (array $f) => ['name' => $f['name'], 'summary' => $f['summary'], 'syntax' => $f['syntax']], FunctionCatalogue::all()),
                'links' => [
                    'dashboard' => route('dashboard'),
                    'classic' => route('spreadsheets.show', $spreadsheet),
                    'csv' => route('spreadsheets.export.csv', $spreadsheet),
                    'excel' => route('spreadsheets.export.excel', $spreadsheet),
                ],
            ],
        ]);
    }

    /**
     * A sheet's layout and how far its data reaches, worked out first if it is stale.
     */
    public function sheet(Spreadsheet $spreadsheet, SheetTab $sheetTab, Recalculator $recalculator): JsonResponse
    {
        $this->authorizeTab('view', $spreadsheet, $sheetTab);
        $recalculator->ensureBuilt($spreadsheet->id, $sheetTab->id);

        return response()->json([
            'layout' => $sheetTab->fresh()->layout ?? (object) [],
            'version' => StructureVersion::current($spreadsheet->id),
            'lastRow' => Cell::where('sheet_tab_id', $sheetTab->id)->max('row_index'),
            'lastCol' => Cell::where('sheet_tab_id', $sheetTab->id)->max('col_index'),
        ]);
    }

    public function cells(Request $request, Spreadsheet $spreadsheet, SheetTab $sheetTab): JsonResponse
    {
        $this->authorizeTab('view', $spreadsheet, $sheetTab);

        $window = $request->validate([
            'r1' => 'required|integer|min:0',
            'r2' => 'required|integer|gte:r1',
        ]);
        abort_if($window['r2'] - $window['r1'] >= self::MAX_WINDOW_ROWS, 422, 'That window is too large.');

        $cells = Cell::where('sheet_tab_id', $sheetTab->id)
            ->whereBetween('row_index', [$window['r1'], $window['r2']])
            ->get(['row_index', 'col_index', 'raw_value', 'computed_value', 'formula', 'formula_error', 'formatting', 'spill_row']);

        return response()->json(['cells' => $cells->map(fn (Cell $cell) => $this->cellJson($cell))->all()]);
    }

    public function edit(Request $request, Spreadsheet $spreadsheet, SheetTab $sheetTab, CellWriter $writer): JsonResponse
    {
        $this->authorizeTab('update', $spreadsheet, $sheetTab);

        $input = $request->validate([
            'version' => 'required|integer',
            'edits' => 'required|array|max:'.self::MAX_EDITS,
            'edits.*.row' => 'required|integer|min:0|max:'.(SheetStructureService::GRID_ROWS - 1),
            'edits.*.col' => 'required|integer|min:0|max:'.(SheetStructureService::GRID_COLS - 1),
            'edits.*.value' => 'present|nullable|string|max:32767',
        ]);

        $edits = array_map(fn (array $e) => ['row' => (int) $e['row'], 'col' => (int) $e['col'], 'value' => (string) ($e['value'] ?? '')], $input['edits']);

        $result = null;
        $written = false;
        $busy = false;
        WriteLock::guard(function () use ($spreadsheet, $sheetTab, $input, $edits, $writer, &$result, &$written) {
            $written = StructureVersion::writeIfCurrent($spreadsheet->id, (int) $input['version'], function () use ($spreadsheet, $sheetTab, $edits, $writer, &$result) {
                $result = $writer->write($spreadsheet->id, $sheetTab->id, $edits, (int) Auth::id());
                app(SheetVersions::class)->autoSnapshot($spreadsheet->id, $sheetTab->id, (int) Auth::id());
            });
        }, function () use (&$busy) {
            $busy = true;
        });

        if ($busy) {
            return response()->json(['message' => WriteLock::BUSY_MESSAGE], 503);
        }
        if (! $written) {
            return response()->json(['stale' => true, 'message' => StructureVersion::STALE_MESSAGE], 409);
        }

        // What changed on this sheet: the edited cells and every formula worked out because of them.
        $changed = array_map(fn (array $e) => $e['row'].':'.$e['col'], $edits);
        foreach ($result['recalculated'] as $node) {
            [$tab, $row, $col] = explode(':', $node);
            if ((int) $tab === $sheetTab->id) {
                $changed[] = $row.':'.$col;
            }
        }

        return response()->json([
            'refused' => $result['refused'],
            'cells' => $this->cellsAt($sheetTab->id, array_values(array_unique($changed))),
            // An answer of many cells changed shape somewhere: the browser reloads what it holds.
            'reload' => Cell::where('sheet_tab_id', $sheetTab->id)->whereNotNull('spill_rows')->exists(),
            'version' => StructureVersion::current($spreadsheet->id),
        ]);
    }

    public function format(Request $request, Spreadsheet $spreadsheet, SheetTab $sheetTab, CellWriter $writer): JsonResponse
    {
        $this->authorizeTab('update', $spreadsheet, $sheetTab);

        $input = $request->validate([
            'version' => 'required|integer',
            'r1' => 'required|integer|min:0',
            'r2' => 'required|integer|gte:r1',
            'c1' => 'required|integer|min:0',
            'c2' => 'required|integer|gte:c1',
            'bold' => 'sometimes|boolean',
            'number_format' => 'sometimes|in:general,percent,currency,number,date',
        ]);

        $count = ($input['r2'] - $input['r1'] + 1) * ($input['c2'] - $input['c1'] + 1);
        if ($count > self::MAX_FORMATTED) {
            return response()->json(['message' => 'Select fewer cells to format: '.number_format(self::MAX_FORMATTED).' at most.'], 422);
        }

        $patch = array_intersect_key($input, array_flip(['bold', 'number_format']));
        $written = false;
        $busy = false;
        WriteLock::guard(function () use ($spreadsheet, $sheetTab, $input, $patch, $writer, &$written) {
            $written = StructureVersion::writeIfCurrent($spreadsheet->id, (int) $input['version'], fn () => $writer->format($spreadsheet->id, $sheetTab->id, $input, $patch));
        }, function () use (&$busy) {
            $busy = true;
        });

        if ($busy) {
            return response()->json(['message' => WriteLock::BUSY_MESSAGE], 503);
        }
        if (! $written) {
            return response()->json(['stale' => true, 'message' => StructureVersion::STALE_MESSAGE], 409);
        }

        return response()->json(['ok' => true]);
    }

    public function trace(Spreadsheet $spreadsheet, SheetTab $sheetTab, int $row, int $col, Recalculator $recalculator): JsonResponse
    {
        $this->authorizeTab('view', $spreadsheet, $sheetTab);

        return response()->json($recalculator->trace($sheetTab->id, $row, $col));
    }

    /**
     * Sum, average and count of a selection too large for the browser to have loaded.
     */
    public function stats(Request $request, Spreadsheet $spreadsheet, SheetTab $sheetTab): JsonResponse
    {
        $this->authorizeTab('view', $spreadsheet, $sheetTab);

        $area = $request->validate([
            'r1' => 'required|integer|min:0',
            'r2' => 'required|integer|gte:r1',
            'c1' => 'required|integer|min:0',
            'c2' => 'required|integer|gte:c1',
        ]);

        $cells = DB::table('cells')->where('sheet_tab_id', $sheetTab->id)
            ->whereBetween('row_index', [$area['r1'], $area['r2']])
            ->whereBetween('col_index', [$area['c1'], $area['c2']])
            ->select('raw_value', 'computed_value', 'formula', 'spill_row')
            ->limit(self::MAX_SUMMED)->cursor();

        $sum = 0.0;
        $numbers = 0;
        $count = 0;
        foreach ($cells as $cell) {
            $value = $cell->formula !== null || $cell->spill_row !== null ? $cell->computed_value : $cell->raw_value;
            if ($value === null || $value === '') {
                continue;
            }
            $count++;
            if (is_numeric($value)) {
                $sum += (float) $value;
                $numbers++;
            }
        }

        return response()->json(['sum' => $sum, 'average' => $numbers > 0 ? $sum / $numbers : null, 'count' => $count, 'numbers' => $numbers]);
    }

    private function authorizeTab(string $ability, Spreadsheet $spreadsheet, SheetTab $sheetTab): void
    {
        $this->authorize($ability, $spreadsheet);
        abort_unless($sheetTab->spreadsheet_id === $spreadsheet->id, 404);
    }

    /**
     * @param  list<string>  $positions  "row:col"
     * @return list<array<string, mixed>>
     */
    private function cellsAt(int $sheetTabId, array $positions): array
    {
        $found = [];
        foreach (array_chunk($positions, 200) as $chunk) {
            $rows = array_map(fn (string $p) => (int) explode(':', $p)[0], $chunk);
            $cols = array_map(fn (string $p) => (int) explode(':', $p)[1], $chunk);
            $cells = Cell::where('sheet_tab_id', $sheetTabId)->whereIn('row_index', array_unique($rows))->whereIn('col_index', array_unique($cols))
                ->get(['row_index', 'col_index', 'raw_value', 'computed_value', 'formula', 'formula_error', 'formatting', 'spill_row']);
            foreach ($cells as $cell) {
                $found[$cell->row_index.':'.$cell->col_index] = $this->cellJson($cell);
            }
        }

        // A position with no cell any more was cleared: the browser removes it.
        $answer = [];
        foreach ($positions as $position) {
            [$row, $col] = array_map('intval', explode(':', $position));
            $answer[] = $found[$position] ?? ['r' => $row, 'c' => $col, 'gone' => true];
        }

        return $answer;
    }

    /**
     * @return array<string, mixed>
     */
    private function cellJson(Cell $cell): array
    {
        $worked = $cell->formula !== null || $cell->spill_row !== null;

        return array_filter([
            'r' => (int) $cell->row_index,
            'c' => (int) $cell->col_index,
            'v' => $worked ? $cell->computed_value : $cell->raw_value,
            'f' => $cell->formula,
            'e' => $cell->formula_error,
            's' => $cell->spill_row !== null ? true : null,
            'x' => $cell->formatting ?: null,
        ], fn ($value) => $value !== null);
    }
}
