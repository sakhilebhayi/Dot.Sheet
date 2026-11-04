<?php

namespace App\Http\Controllers;

use App\Models\Cell;
use App\Models\SheetTab;
use App\Models\Spreadsheet;
use App\Services\CellFormat;
use App\Services\CellWriter;
use App\Services\Formula\FunctionCatalogue;
use App\Services\Formula\Recalculator;
use App\Services\RangeCopy;
use App\Services\RangeFill;
use App\Services\SheetSearch;
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
    public function __construct(private Recalculator $recalculator) {}

    /** Most rows one window of cells may ask for. */
    private const MAX_WINDOW_ROWS = 1000;

    /** Most edits one request may carry: undoing a move writes both its ends. */
    private const MAX_EDITS = RangeCopy::MAX_CELLS * 2;

    /** Most columns or rows one resize may set. */
    private const MAX_RESIZED = 200;

    /** The sizes the classic editor allows, so a sheet reads the same in both. */
    private const COLUMN_WIDTHS = [48, 420];

    private const ROW_HEIGHTS = [18, 120];

    /** Most changed cells sent back with an answer; past it, the browser reloads what it holds. */
    private const MAX_ANSWERED = 500;

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
            'edits.*.x' => 'sometimes|nullable|array',
        ]);

        $edits = array_map(function (array $e) {
            $edit = ['row' => (int) $e['row'], 'col' => (int) $e['col'], 'value' => (string) ($e['value'] ?? '')];

            return array_key_exists('x', $e) ? $edit + ['x' => CellFormat::clean($e['x'])] : $edit;
        }, $input['edits']);

        return $this->commit($spreadsheet, $sheetTab, (int) $input['version'], fn () => [$sheetTab->id => $edits], $writer);
    }

    /**
     * Copy or move a rectangle to another place in the workbook, formulas and formatting with it.
     */
    public function paste(Request $request, Spreadsheet $spreadsheet, SheetTab $sheetTab, CellWriter $writer, RangeCopy $copy): JsonResponse
    {
        $this->authorizeTab('update', $spreadsheet, $sheetTab);

        $input = $request->validate([
            'version' => 'required|integer',
            'from_tab' => 'required|integer',
            'r1' => 'required|integer|min:0',
            'r2' => 'required|integer|gte:r1',
            'c1' => 'required|integer|min:0',
            'c2' => 'required|integer|gte:c1',
            'row' => 'required|integer|min:0',
            'col' => 'required|integer|min:0',
            'rows' => 'required|integer|min:1',
            'cols' => 'required|integer|min:1',
            'cut' => 'sometimes|boolean',
        ]);

        $source = SheetTab::whereKey($input['from_tab'])->where('spreadsheet_id', $spreadsheet->id)->first();
        abort_if($source === null, 404);

        $area = array_intersect_key($input, array_flip(['r1', 'r2', 'c1', 'c2']));
        $target = RangeCopy::target($area, (int) $input['row'], (int) $input['col'], (int) $input['rows'], (int) $input['cols']);
        if ($target['r2'] >= SheetStructureService::GRID_ROWS || $target['c2'] >= SheetStructureService::GRID_COLS) {
            return response()->json(['message' => 'That does not fit on the sheet from here.'], 422);
        }
        if (($target['r2'] - $target['r1'] + 1) * ($target['c2'] - $target['c1'] + 1) > RangeCopy::MAX_CELLS) {
            return response()->json(['message' => 'Paste fewer cells at a time: '.number_format(RangeCopy::MAX_CELLS).' at most.'], 422);
        }

        return $this->commit(
            $spreadsheet,
            $sheetTab,
            (int) $input['version'],
            fn () => $copy->plan($source->id, $area, $sheetTab->id, $target, (bool) ($input['cut'] ?? false)),
            $writer,
            ['area' => $target],
        );
    }

    /**
     * Take a selection out to a larger area, continuing what it starts.
     */
    public function fill(Request $request, Spreadsheet $spreadsheet, SheetTab $sheetTab, CellWriter $writer, RangeFill $fill): JsonResponse
    {
        $this->authorizeTab('update', $spreadsheet, $sheetTab);

        $input = $request->validate([
            'version' => 'required|integer',
            'r1' => 'required|integer|min:0',
            'r2' => 'required|integer|gte:r1',
            'c1' => 'required|integer|min:0',
            'c2' => 'required|integer|gte:c1',
            'to_r1' => 'required|integer|min:0',
            'to_r2' => 'required|integer|gte:to_r1|max:'.(SheetStructureService::GRID_ROWS - 1),
            'to_c1' => 'required|integer|min:0',
            'to_c2' => 'required|integer|gte:to_c1|max:'.(SheetStructureService::GRID_COLS - 1),
        ]);

        $area = ['r1' => (int) $input['r1'], 'r2' => (int) $input['r2'], 'c1' => (int) $input['c1'], 'c2' => (int) $input['c2']];
        $target = ['r1' => (int) $input['to_r1'], 'r2' => (int) $input['to_r2'], 'c1' => (int) $input['to_c1'], 'c2' => (int) $input['to_c2']];

        $contains = $target['r1'] <= $area['r1'] && $target['r2'] >= $area['r2'] && $target['c1'] <= $area['c1'] && $target['c2'] >= $area['c2'];
        $sameRows = $target['r1'] === $area['r1'] && $target['r2'] === $area['r2'];
        $sameCols = $target['c1'] === $area['c1'] && $target['c2'] === $area['c2'];
        if (! $contains || ($sameRows === $sameCols)) {
            return response()->json(['message' => 'Fill goes one way from the selection: down, up, left or right.'], 422);
        }
        if (($target['r2'] - $target['r1'] + 1) * ($target['c2'] - $target['c1'] + 1) > RangeCopy::MAX_CELLS) {
            return response()->json(['message' => 'Fill fewer cells at a time: '.number_format(RangeCopy::MAX_CELLS).' at most.'], 422);
        }

        return $this->commit(
            $spreadsheet,
            $sheetTab,
            (int) $input['version'],
            fn () => [$sheetTab->id => $fill->plan($sheetTab->id, $area, $target)],
            $writer,
            ['area' => $target],
        );
    }

    public function find(Request $request, Spreadsheet $spreadsheet, SheetTab $sheetTab, SheetSearch $search): JsonResponse
    {
        $this->authorizeTab('view', $spreadsheet, $sheetTab);

        $input = $request->validate(['q' => 'required|string|max:255', 'case' => 'sometimes|boolean', 'whole' => 'sometimes|boolean', 'all' => 'sometimes|boolean']);

        return response()->json($search->find(
            $this->searchedTabs($spreadsheet, $sheetTab, $request->boolean('all')),
            $input['q'],
            $request->boolean('case'),
            $request->boolean('whole'),
        ));
    }

    public function replace(Request $request, Spreadsheet $spreadsheet, SheetTab $sheetTab, CellWriter $writer, SheetSearch $search): JsonResponse
    {
        $this->authorizeTab('update', $spreadsheet, $sheetTab);

        $input = $request->validate([
            'version' => 'required|integer',
            'q' => 'required|string|max:255',
            'with' => 'present|nullable|string|max:255',
            'case' => 'sometimes|boolean',
            'whole' => 'sometimes|boolean',
            'all' => 'sometimes|boolean',
            'only' => 'sometimes|array',
            'only.tab' => 'required_with:only|integer',
            'only.r' => 'required_with:only|integer|min:0',
            'only.c' => 'required_with:only|integer|min:0',
        ]);

        $tabs = $this->searchedTabs($spreadsheet, $sheetTab, $request->boolean('all'));
        $only = isset($input['only']) ? ['tab' => (int) $input['only']['tab'], 'r' => (int) $input['only']['r'], 'c' => (int) $input['only']['c']] : null;

        $edits = $search->replacements($tabs, $input['q'], (string) ($input['with'] ?? ''), $request->boolean('case'), $request->boolean('whole'), $only);
        if (array_sum(array_map('count', $edits)) > RangeCopy::MAX_CELLS) {
            return response()->json(['message' => 'That would change too many cells at once: '.number_format(RangeCopy::MAX_CELLS).' at most.'], 422);
        }

        return $this->commit($spreadsheet, $sheetTab, (int) $input['version'], fn () => $edits, $writer, [
            'replaced' => array_sum(array_map('count', $edits)),
        ]);
    }

    /**
     * @return list<int>
     */
    private function searchedTabs(Spreadsheet $spreadsheet, SheetTab $sheetTab, bool $all): array
    {
        return $all ? $spreadsheet->sheetTabs()->orderBy('sort_order')->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all() : [$sheetTab->id];
    }

    /**
     * Set column widths or row heights, shared with the classic editor. A null size goes back to the
     * default. The answer says how to put them back.
     */
    public function layout(Request $request, Spreadsheet $spreadsheet, SheetTab $sheetTab): JsonResponse
    {
        $this->authorizeTab('update', $spreadsheet, $sheetTab);

        $input = $request->validate([
            'version' => 'required|integer',
            'axis' => 'required|in:col,row',
            'sizes' => 'required|array|min:1|max:'.self::MAX_RESIZED,
        ]);

        $column = $input['axis'] === 'col';
        [$smallest, $largest] = $column ? self::COLUMN_WIDTHS : self::ROW_HEIGHTS;
        foreach ($input['sizes'] as $position => $size) {
            if (filter_var($position, FILTER_VALIDATE_INT) === false || (int) $position < 0 || (int) $position >= ($column ? SheetStructureService::GRID_COLS : SheetStructureService::GRID_ROWS)) {
                return response()->json(['message' => 'That is not a place on the sheet.'], 422);
            }
            if ($size !== null && (! is_int($size) || $size < $smallest || $size > $largest)) {
                return response()->json(['message' => "Sizes run from {$smallest} to {$largest}."], 422);
            }
        }

        $key = $column ? 'col_widths' : 'row_heights';
        $undo = [];
        $written = false;
        $busy = false;
        WriteLock::guard(function () use ($spreadsheet, $sheetTab, $input, $key, &$undo, &$written) {
            $written = StructureVersion::writeIfCurrent($spreadsheet->id, (int) $input['version'], function () use ($sheetTab, $input, $key, &$undo) {
                SheetTab::updateLayout($sheetTab->id, function (array $layout) use ($input, $key, &$undo) {
                    $sizes = is_array($layout[$key] ?? null) ? $layout[$key] : [];
                    foreach ($input['sizes'] as $position => $size) {
                        $undo[(int) $position] = $sizes[(int) $position] ?? null;
                        if ($size === null) {
                            unset($sizes[(int) $position]);
                        } else {
                            $sizes[(int) $position] = $size;
                        }
                    }
                    $layout[$key] = $sizes;

                    return $layout;
                });
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

        return response()->json(['undo' => (object) $undo]);
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
        $undo = [];
        $written = false;
        $busy = false;
        WriteLock::guard(function () use ($spreadsheet, $sheetTab, $input, $patch, $writer, &$written, &$undo) {
            $written = StructureVersion::writeIfCurrent($spreadsheet->id, (int) $input['version'], function () use ($spreadsheet, $sheetTab, $input, $patch, $writer, &$undo) {
                $undo = $writer->format($spreadsheet->id, $sheetTab->id, $input, $patch);
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

        return response()->json(['ok' => true, 'undo' => [['tab' => $sheetTab->id, 'edits' => $undo]]]);
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

    /**
     * Write edits to one or more sheets as one change, and answer with what this sheet now shows and
     * how to put everything back.
     *
     * @param  callable(): array<int, list<array{row: int, col: int, value: string, x?: array<string, mixed>|null}>>  $plan  sheet id => edits, read inside the lock
     * @param  array<string, mixed>  $extra
     */
    private function commit(Spreadsheet $spreadsheet, SheetTab $sheetTab, int $version, callable $plan, CellWriter $writer, array $extra = []): JsonResponse
    {
        $results = [];
        $plans = [];
        $written = false;
        $busy = false;
        WriteLock::guard(function () use ($spreadsheet, $sheetTab, $version, $plan, $writer, &$results, &$plans, &$written) {
            $written = StructureVersion::writeIfCurrent($spreadsheet->id, $version, function () use ($spreadsheet, $sheetTab, $plan, $writer, &$results, &$plans) {
                $plans = $plan();
                foreach ($plans as $tab => $edits) {
                    $results[$tab] = $writer->write($spreadsheet->id, $tab, $edits, (int) Auth::id());
                }
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

        foreach ($results as $tab => $result) {
            if ($result['stale']) {
                $this->recalculator->ensureBuilt($spreadsheet->id, $tab);
            }
        }

        // What changed on this sheet: the edited cells and every formula worked out because of them.
        $here = $results[$sheetTab->id] ?? ['recalculated' => [], 'refused' => [], 'stale' => false];
        $changed = array_map(fn (array $e) => $e['row'].':'.$e['col'], $plans[$sheetTab->id] ?? []);
        foreach ($results as $result) {
            foreach ($result['recalculated'] as $node) {
                [$tab, $row, $col] = explode(':', $node);
                if ((int) $tab === $sheetTab->id) {
                    $changed[] = $row.':'.$col;
                }
            }
        }
        $large = $here['stale'] || count($changed) > self::MAX_ANSWERED;

        $undo = [];
        foreach ($results as $tab => $result) {
            $undo[] = ['tab' => $tab, 'edits' => $result['undo']];
        }

        return response()->json([
            'refused' => $here['refused'],
            'cells' => $large ? [] : $this->cellsAt($sheetTab->id, array_values(array_unique($changed))),
            // An answer of many cells changed shape somewhere: the browser reloads what it holds.
            'reload' => $large || Cell::where('sheet_tab_id', $sheetTab->id)->whereNotNull('spill_rows')->exists(),
            'undo' => $undo,
            'version' => StructureVersion::current($spreadsheet->id),
            ...$extra,
        ]);
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
