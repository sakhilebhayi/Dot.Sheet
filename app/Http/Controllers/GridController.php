<?php

namespace App\Http\Controllers;

use App\Models\Cell;
use App\Models\NamedRange;
use App\Models\SheetTab;
use App\Models\Spreadsheet;
use App\Models\SpreadsheetVersion;
use App\Services\CellFormat;
use App\Services\CellRules;
use App\Services\CellWriter;
use App\Services\Formula\FunctionCatalogue;
use App\Services\Formula\Recalculator;
use App\Services\HideRefused;
use App\Services\MergeRefused;
use App\Services\RangeCopy;
use App\Services\RangeFill;
use App\Services\RangeSort;
use App\Services\SheetComments;
use App\Services\SheetFilter;
use App\Services\SheetMerges;
use App\Services\SheetSearch;
use App\Services\SheetStructureService;
use App\Services\SheetVersions;
use App\Services\SheetWorkflows;
use App\Services\SortRefused;
use App\Services\StaleStructure;
use App\Services\StructureChangeRefused;
use App\Services\StructureVersion;
use App\Services\WorkbookSheets;
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
    public function __construct(private Recalculator $recalculator, private SheetWorkflows $workflows) {}

    /** Most rows one window of cells may ask for. */
    private const MAX_WINDOW_ROWS = 1000;

    /** Most edits one request may carry: undoing a move writes both its ends. */
    private const MAX_EDITS = RangeCopy::MAX_CELLS * 2;

    /** Most columns or rows one resize may set. */
    private const MAX_RESIZED = 200;

    /** The sizes the classic editor allows, so a sheet reads the same in both. */
    private const COLUMN_WIDTHS = [48, 420];

    private const ROW_HEIGHTS = [18, 120];

    /** Most colour rules one sheet may hold. */
    private const MAX_RULES = 200;

    /** Most rules about what cells may hold on one sheet. */
    private const MAX_ALLOWED = 500;

    /** Most filters one sheet may hold. */
    private const MAX_FILTERS = 50;

    /** Most rows or columns one request may insert or delete. */
    private const MAX_STRUCTURE = 20;

    /** Most rows or columns one request may hide or show. */
    private const MAX_HIDDEN_CHANGE = 1000;

    /** Most rows and columns a sheet may freeze: any more leaves nothing to scroll. */
    private const MAX_FROZEN_ROWS = 50;

    private const MAX_FROZEN_COLS = 20;

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
                'canComment' => Auth::user()->can('comment', $spreadsheet),
                'canShare' => Auth::user()->can('manageSharing', $spreadsheet),
                'scripts' => (bool) config('sheet.scripts_enabled'),
                'told' => session('success'),
                'structure' => (bool) config('sheet.structure_ops_enabled'),
                'version' => StructureVersion::current($spreadsheet->id),
                'rows' => SheetStructureService::GRID_ROWS,
                'cols' => SheetStructureService::GRID_COLS,
                'tabs' => $this->tabs($spreadsheet),
                'functions' => array_map(fn (array $f) => ['name' => $f['name'], 'summary' => $f['summary'], 'syntax' => $f['syntax']], FunctionCatalogue::all()),
                'links' => [
                    'dashboard' => route('dashboard'),
                    'classic' => route('spreadsheets.classic', $spreadsheet),
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
            'tabs' => $this->tabs($spreadsheet),
            'version' => StructureVersion::current($spreadsheet->id),
            'lastRow' => Cell::where('sheet_tab_id', $sheetTab->id)->max('row_index'),
            'lastCol' => Cell::where('sheet_tab_id', $sheetTab->id)->max('col_index'),
            'marks' => app(SheetComments::class)->marks($sheetTab->id),
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
            'edits' => 'present|array|max:'.self::MAX_EDITS,
            'edits.*.row' => 'required|integer|min:0|max:'.(SheetStructureService::GRID_ROWS - 1),
            'edits.*.col' => 'required|integer|min:0|max:'.(SheetStructureService::GRID_COLS - 1),
            'edits.*.value' => 'present|nullable|string|max:32767',
            'edits.*.x' => 'sometimes|nullable|array',
            ...$this->mergeRules('merges.add'),
            ...$this->mergeRules('merges.remove'),
            // Undo of a sort or a cut: where the comments that moved were.
            'comments' => 'sometimes|array|max:'.self::MAX_EDITS,
            'comments.*.id' => 'required|integer',
            'comments.*.tab' => 'required|integer',
            'comments.*.row' => 'required|integer|min:0|max:'.(SheetStructureService::GRID_ROWS - 1),
            'comments.*.col' => 'required|integer|min:0|max:'.(SheetStructureService::GRID_COLS - 1),
        ]);

        $edits = array_map(function (array $e) {
            $edit = ['row' => (int) $e['row'], 'col' => (int) $e['col'], 'value' => (string) ($e['value'] ?? '')];

            return array_key_exists('x', $e) ? $edit + ['x' => CellFormat::clean($e['x'])] : $edit;
        }, $input['edits']);

        $merges = isset($input['merges']) ? [$sheetTab->id => [
            'add' => $this->areas($input['merges']['add'] ?? []),
            'remove' => $this->areas($input['merges']['remove'] ?? []),
        ]] : [];

        return $this->commit(
            $spreadsheet,
            $sheetTab,
            (int) $input['version'],
            fn () => [$sheetTab->id => $edits],
            $writer,
            [],
            $merges,
            moveComments: isset($input['comments']) ? fn () => app(SheetComments::class)->place($spreadsheet->id, $input['comments']) : null,
        );
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

        $cut = (bool) ($input['cut'] ?? false);
        $merges = SheetMerges::of($sheetTab->layout);
        $carried = SheetMerges::within($source->id === $sheetTab->id ? $merges : SheetMerges::of($source->layout), $area);
        if ($carried['split'] !== []) {
            return response()->json(['message' => SheetMerges::PART_MESSAGE], 422);
        }
        // A merge being moved is out of the way of where it lands.
        $landing = SheetMerges::within($cut && $source->id === $sheetTab->id ? array_values(array_filter($merges, fn (array $m) => ! in_array($m, $carried['inside'], true))) : $merges, $target);
        // One cell on the first cell of a merge goes in it, as typing does.
        $intoFirst = SheetMerges::count($target) === 1 && ! SheetMerges::covers($merges, $target['r1'], $target['c1']);
        if ($landing['split'] !== [] && ! $intoFirst) {
            return response()->json(['message' => SheetMerges::SPLIT_MESSAGE], 422);
        }

        return $this->commit(
            $spreadsheet,
            $sheetTab,
            (int) $input['version'],
            fn () => $copy->plan($source->id, $area, $sheetTab->id, $target, $cut),
            $writer,
            ['area' => $target],
            $this->carriedMerges($source->id, $sheetTab->id, $area, $target, $carried['inside'], $landing['inside'], $cut),
            moveComments: $cut ? fn () => app(SheetComments::class)->moveArea($source->id, $area, $sheetTab->id, $target['r1'] - $area['r1'], $target['c1'] - $area['c1']) : null,
        );
    }

    /**
     * What a paste does to merged cells: those it lands on go, and those it brought come with it, once
     * for each time the copy repeats. Moving also takes them from where they were.
     *
     * @param  array{r1: int, r2: int, c1: int, c2: int}  $area
     * @param  array{r1: int, r2: int, c1: int, c2: int}  $target
     * @param  list<array{r1: int, r2: int, c1: int, c2: int}>  $carried
     * @param  list<array{r1: int, r2: int, c1: int, c2: int}>  $landedOn
     * @return array<int, array{add?: list<array{r1: int, r2: int, c1: int, c2: int}>, remove?: list<array{r1: int, r2: int, c1: int, c2: int}>}>
     */
    private function carriedMerges(int $fromTab, int $toTab, array $area, array $target, array $carried, array $landedOn, bool $cut): array
    {
        $changes = [];
        if ($landedOn !== []) {
            $changes[$toTab]['remove'] = $landedOn;
        }
        if ($cut && $carried !== []) {
            $changes[$fromTab]['remove'] = [...($changes[$fromTab]['remove'] ?? []), ...$carried];
        }

        $height = $area['r2'] - $area['r1'] + 1;
        $width = $area['c2'] - $area['c1'] + 1;
        for ($row = $target['r1']; $row <= $target['r2']; $row += $height) {
            for ($col = $target['c1']; $col <= $target['c2']; $col += $width) {
                foreach ($carried as $merge) {
                    $down = $row - $area['r1'];
                    $across = $col - $area['c1'];
                    $changes[$toTab]['add'][] = ['r1' => $merge['r1'] + $down, 'r2' => $merge['r2'] + $down, 'c1' => $merge['c1'] + $across, 'c2' => $merge['c2'] + $across];
                }
            }
        }

        return $changes;
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

        $merges = SheetMerges::of($sheetTab->layout);
        $own = SheetMerges::within($merges, $area);
        if ($own['split'] !== []) {
            return response()->json(['message' => SheetMerges::PART_MESSAGE], 422);
        }
        $landing = SheetMerges::within($merges, $target);
        if ($landing['split'] !== []) {
            return response()->json(['message' => SheetMerges::SPLIT_MESSAGE], 422);
        }
        $landedOn = array_values(array_filter($landing['inside'], fn (array $m) => ! in_array($m, $own['inside'], true)));

        return $this->commit(
            $spreadsheet,
            $sheetTab,
            (int) $input['version'],
            fn () => [$sheetTab->id => $fill->plan($sheetTab->id, $area, $target)],
            $writer,
            ['area' => $target],
            $landedOn === [] ? [] : [$sheetTab->id => ['remove' => $landedOn]],
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

    /**
     * Freeze the rows above and the columns to the left of a place on the sheet.
     */
    public function freeze(Request $request, Spreadsheet $spreadsheet, SheetTab $sheetTab): JsonResponse
    {
        $this->authorizeTab('update', $spreadsheet, $sheetTab);

        $input = $request->validate([
            'version' => 'required|integer',
            'rows' => 'required|integer|min:0|max:'.self::MAX_FROZEN_ROWS,
            'cols' => 'required|integer|min:0|max:'.self::MAX_FROZEN_COLS,
        ]);

        $undo = [];
        $written = false;
        $busy = false;
        WriteLock::guard(function () use ($spreadsheet, $sheetTab, $input, &$undo, &$written) {
            $written = StructureVersion::writeIfCurrent($spreadsheet->id, (int) $input['version'], function () use ($sheetTab, $input, &$undo) {
                SheetTab::updateLayout($sheetTab->id, function (array $layout) use ($input, &$undo) {
                    $undo = ['rows' => (int) ($layout['frozen_rows'] ?? 0), 'cols' => (int) ($layout['frozen_cols'] ?? 1)];
                    $layout['frozen_rows'] = (int) $input['rows'];
                    $layout['frozen_cols'] = (int) $input['cols'];

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

        return response()->json(['undo' => $undo]);
    }

    /**
     * Make a rectangle one cell. Its first contents stay, the rest are cleared, and the answer says how
     * to put them back.
     */
    public function merge(Request $request, Spreadsheet $spreadsheet, SheetTab $sheetTab, CellWriter $writer): JsonResponse
    {
        $this->authorizeTab('update', $spreadsheet, $sheetTab);

        $input = $request->validate(['version' => 'required|integer', ...$this->areaRules()]);
        $area = $this->areas([$input])[0];

        if (SheetMerges::count($area) < 2) {
            return response()->json(['message' => 'Select two or more cells to merge.'], 422);
        }

        $cleared = 0;

        return $this->commit(
            $spreadsheet,
            $sheetTab,
            (int) $input['version'],
            function () use ($sheetTab, $area, &$cleared) {
                ['edits' => $edits, 'cleared' => $cleared] = SheetMerges::contents($sheetTab->id, $area);

                return [$sheetTab->id => $edits];
            },
            $writer,
            function () use ($area, &$cleared) {
                return ['area' => $area, 'cleared' => $cleared];
            },
            [$sheetTab->id => ['add' => [$area]]],
        );
    }

    /**
     * Let every merged cell in a rectangle go. What they hold stays in their first cells.
     */
    public function unmerge(Request $request, Spreadsheet $spreadsheet, SheetTab $sheetTab, CellWriter $writer): JsonResponse
    {
        $this->authorizeTab('update', $spreadsheet, $sheetTab);

        $input = $request->validate(['version' => 'required|integer', ...$this->areaRules()]);
        $area = $this->areas([$input])[0];

        $found = SheetMerges::near(SheetMerges::of($sheetTab->layout), $area);
        if ($found === []) {
            return response()->json(['message' => 'Nothing here is merged.'], 422);
        }

        return $this->commit($spreadsheet, $sheetTab, (int) $input['version'], fn () => [$sheetTab->id => []], $writer, ['area' => $area], [$sheetTab->id => ['remove' => $found]]);
    }

    /**
     * @return array<string, string>
     */
    private function areaRules(): array
    {
        return [
            'r1' => 'required|integer|min:0',
            'r2' => 'required|integer|gte:r1|max:'.(SheetStructureService::GRID_ROWS - 1),
            'c1' => 'required|integer|min:0',
            'c2' => 'required|integer|gte:c1|max:'.(SheetStructureService::GRID_COLS - 1),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function mergeRules(string $key): array
    {
        return [
            $key => 'sometimes|array|max:'.SheetMerges::MAX_MERGES,
            "{$key}.*.r1" => 'required|integer|min:0',
            "{$key}.*.r2" => 'required|integer|gte:'.$key.'.*.r1|max:'.(SheetStructureService::GRID_ROWS - 1),
            "{$key}.*.c1" => 'required|integer|min:0',
            "{$key}.*.c2" => 'required|integer|gte:'.$key.'.*.c1|max:'.(SheetStructureService::GRID_COLS - 1),
        ];
    }

    /**
     * @param  array<int|string, array<string, mixed>>  $rows
     * @return list<array{r1: int, r2: int, c1: int, c2: int}>
     */
    private function areas(array $rows): array
    {
        return array_values(array_map(fn (array $a) => ['r1' => (int) $a['r1'], 'r2' => (int) $a['r2'], 'c1' => (int) $a['c1'], 'c2' => (int) $a['c2']], $rows));
    }

    /**
     * @return array<int, array{id: int, name: string}>
     */
    private function tabs(Spreadsheet $spreadsheet): array
    {
        return app(WorkbookSheets::class)->listOf($spreadsheet);
    }

    public function addSheet(Request $request, Spreadsheet $spreadsheet, WorkbookSheets $sheets): JsonResponse
    {
        $this->authorize('update', $spreadsheet);

        $name = $request->validate(['name' => 'sometimes|nullable|string|max:255'])['name'] ?? null;
        $name = $name === null ? null : trim($name);
        if ($name !== null && ($reason = $sheets->refusal($spreadsheet->id, $name)) !== null) {
            return response()->json(['message' => $reason], 422);
        }

        $tab = null;
        $busy = false;
        WriteLock::guard(function () use ($spreadsheet, $sheets, $name, &$tab) {
            $tab = DB::transaction(fn () => $sheets->add($spreadsheet, $name));
        }, function () use (&$busy) {
            $busy = true;
        });

        if ($busy || $tab === null) {
            return response()->json(['message' => WriteLock::BUSY_MESSAGE], 503);
        }

        return response()->json(['tab' => $tab->id, 'tabs' => $this->tabs($spreadsheet)]);
    }

    /**
     * Rename a sheet and the formulas that name it. The answer says how to put it back.
     */
    public function renameSheet(Request $request, Spreadsheet $spreadsheet, SheetTab $sheetTab, WorkbookSheets $sheets): JsonResponse
    {
        $this->authorizeTab('update', $spreadsheet, $sheetTab);

        $input = $request->validate(['version' => 'required|integer', 'name' => 'required|string|max:255']);
        $name = trim($input['name']);
        $from = (string) $sheetTab->name;

        if (($reason = $sheets->refusal($spreadsheet->id, $name, $sheetTab->id)) !== null) {
            return response()->json(['message' => $reason], 422);
        }

        return $this->changeSheets($spreadsheet, (int) $input['version'], fn () => $sheets->rename($sheetTab, $name), ['undo' => ['name' => $from]]);
    }

    /**
     * What deleting a sheet would cost, for the person to weigh first: it cannot be undone.
     */
    public function removalCost(Spreadsheet $spreadsheet, SheetTab $sheetTab, WorkbookSheets $sheets): JsonResponse
    {
        $this->authorizeTab('update', $spreadsheet, $sheetTab);

        return response()->json($sheets->cost($sheetTab));
    }

    public function removeSheet(Request $request, Spreadsheet $spreadsheet, SheetTab $sheetTab, WorkbookSheets $sheets): JsonResponse
    {
        $this->authorizeTab('update', $spreadsheet, $sheetTab);

        $input = $request->validate(['version' => 'required|integer']);

        return $this->changeSheets($spreadsheet, (int) $input['version'], fn () => $sheets->remove($sheetTab));
    }

    /**
     * @param  callable(): void  $change
     * @param  array<string, mixed>  $extra
     */
    private function changeSheets(Spreadsheet $spreadsheet, int $version, callable $change, array $extra = []): JsonResponse
    {
        $written = false;
        $busy = false;
        try {
            WriteLock::guard(function () use ($spreadsheet, $version, $change, &$written) {
                $written = StructureVersion::writeIfCurrent($spreadsheet->id, $version, $change);
            }, function () use (&$busy) {
                $busy = true;
            });
        } catch (StructureChangeRefused $refused) {
            return response()->json(['message' => $refused->getMessage()], 422);
        }

        if ($busy) {
            return response()->json(['message' => WriteLock::BUSY_MESSAGE], 503);
        }
        if (! $written) {
            return response()->json(['stale' => true, 'message' => StructureVersion::STALE_MESSAGE], 409);
        }

        return response()->json(['tabs' => $this->tabs($spreadsheet), 'version' => StructureVersion::current($spreadsheet->id), ...$extra]);
    }

    /**
     * Sort rows by one column. One cell, or a whole row or column, sorts the block of data around it; a
     * larger selection sorts exactly that. Rows move as edits, so formulas follow them and undo works.
     */
    public function sort(Request $request, Spreadsheet $spreadsheet, SheetTab $sheetTab, CellWriter $writer, RangeSort $sorter): JsonResponse
    {
        $this->authorizeTab('update', $spreadsheet, $sheetTab);

        $input = $request->validate(['version' => 'required|integer', ...$this->areaRules(), 'key' => 'required|integer|min:0|max:'.(SheetStructureService::GRID_COLS - 1), 'descending' => 'required|boolean']);
        $area = $this->areas([$input])[0];
        $key = (int) $input['key'];
        $layout = is_array($sheetTab->layout) ? $sheetTab->layout : [];
        $whole = $area['r2'] - $area['r1'] + 1 === SheetStructureService::GRID_ROWS || $area['c2'] - $area['c1'] + 1 === SheetStructureService::GRID_COLS;

        $header = null;
        if (SheetMerges::count($area) === 1 || $whole) {
            $block = $sorter->block($sheetTab->id, $area['r1'], $key, (int) ($layout['frozen_rows'] ?? 0));
            if ($block === null) {
                return response()->json(['message' => 'There is nothing to sort here.'], 422);
            }
            ['area' => $area, 'header' => $header] = $block;
        }

        if ($area['r1'] >= $area['r2']) {
            return response()->json(['message' => 'Select two or more rows to sort.'], 422);
        }
        if (SheetMerges::count($area) > RangeCopy::MAX_CELLS) {
            return response()->json(['message' => 'Sort fewer cells at a time: '.number_format(RangeCopy::MAX_CELLS).' at most.'], 422);
        }
        if ($key < $area['c1'] || $key > $area['c2']) {
            return response()->json(['message' => 'Sort by a column inside the selection.'], 422);
        }
        if (SheetMerges::near(SheetMerges::of($layout), $area) !== []) {
            return response()->json(['message' => 'These rows have merged cells. Unmerge them to sort.'], 422);
        }
        if (! empty($layout['filter_rules'])) {
            return response()->json(['message' => 'Clear the filter to sort: rows it hides would be left out of place.'], 422);
        }
        $hidden = is_array($layout['hidden_rows'] ?? null) ? array_map('intval', $layout['hidden_rows']) : [];
        if (array_filter($hidden, fn (int $row) => $row >= $area['r1'] && $row <= $area['r2']) !== []) {
            return response()->json(['message' => 'Some of these rows are hidden. Show them to sort.'], 422);
        }

        $this->recalculator->ensureBuilt($spreadsheet->id, $sheetTab->id);
        $rules = is_array($layout['validation_rules'] ?? null) ? $layout['validation_rules'] : [];

        return $this->commit(
            $spreadsheet,
            $sheetTab,
            (int) $input['version'],
            fn () => [$sheetTab->id => $sorter->plan($sheetTab->id, $area, $key, (bool) $input['descending'], $rules)],
            $writer,
            ['area' => $area, 'header' => $header],
            watch: false,
            moveComments: fn () => app(SheetComments::class)->moveRows($sheetTab->id, $sorter->moved, $area['c1'], $area['c2']),
        );
    }

    /**
     * Set a sheet's filters, whole. Sending the same list applies them again to the sheet as it now is.
     */
    public function filter(Request $request, Spreadsheet $spreadsheet, SheetTab $sheetTab, SheetFilter $filters): JsonResponse
    {
        $this->authorizeTab('update', $spreadsheet, $sheetTab);

        $input = $request->validate([
            'version' => 'required|integer',
            'rules' => 'present|array|max:'.self::MAX_FILTERS,
            'rules.*.id' => 'sometimes|string|max:64',
            'rules.*.col' => 'required|integer|min:0|max:'.(SheetStructureService::GRID_COLS - 1),
            'rules.*.type' => 'required|in:'.implode(',', SheetFilter::TYPES),
            'rules.*.operator' => 'sometimes|nullable|in:>,>=,<,<=,==,!=,contains',
            'rules.*.value' => 'sometimes|nullable|string|max:255',
            'rules.*.value2' => 'sometimes|nullable|string|max:255',
            'rules.*.color' => ['sometimes', 'nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ]);

        $rules = [];
        foreach ($input['rules'] as $rule) {
            $between = $rule['type'] === 'number_between';
            $ordered = $rule['type'] === 'condition' && in_array($rule['operator'] ?? '==', ['>', '>=', '<', '<='], true);
            if (($between || $ordered) && (! is_numeric($rule['value'] ?? null) || ($between && ! is_numeric($rule['value2'] ?? null)))) {
                return response()->json(['message' => 'More, less and between compare numbers: give it a number.'], 422);
            }
            $rules[] = ['id' => $rule['id'] ?? (string) str()->uuid(), 'col' => (int) $rule['col'], 'type' => $rule['type'], 'operator' => $rule['operator'] ?? null, 'value' => $rule['value'] ?? null, 'value2' => $rule['value2'] ?? null, 'color' => $rule['color'] ?? null];
        }

        $undo = [];
        $hidden = [];
        $written = false;
        $busy = false;
        WriteLock::guard(function () use ($spreadsheet, $sheetTab, $input, $rules, $filters, &$undo, &$hidden, &$written) {
            $written = StructureVersion::writeIfCurrent($spreadsheet->id, (int) $input['version'], function () use ($sheetTab, $rules, $filters, &$undo, &$hidden) {
                SheetTab::updateLayout($sheetTab->id, function (array $layout) use ($sheetTab, $rules, $filters, &$undo, &$hidden) {
                    $undo = is_array($layout['filter_rules'] ?? null) ? array_values($layout['filter_rules']) : [];
                    $layout = $filters->applied($layout, $sheetTab->id, $rules);
                    $hidden = $layout['hidden_rows'];

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

        return response()->json(['rules' => $rules, 'hidden' => $hidden, 'undo' => ['rules' => $undo]]);
    }

    /**
     * Add a colour rule to a sheet or take one off, shared with the classic editor, which can only add
     * them. A rule put back by undo keeps its id. The answer says how to undo this.
     */
    public function rules(Request $request, Spreadsheet $spreadsheet, SheetTab $sheetTab): JsonResponse
    {
        $this->authorizeTab('update', $spreadsheet, $sheetTab);

        $input = $request->validate([
            'version' => 'required|integer',
            'add' => 'required_without:remove|array',
            'add.id' => 'sometimes|string|max:64',
            'add.target' => 'required_with:add|in:cell,column',
            'add.row' => 'required_with:add|integer|min:0|max:'.(SheetStructureService::GRID_ROWS - 1),
            'add.col' => 'required_with:add|integer|min:0|max:'.(SheetStructureService::GRID_COLS - 1),
            'add.operator' => 'required_with:add|in:>,>=,<,<=,==,!=,contains',
            'add.value' => 'present_with:add|nullable|string|max:255',
            'add.color' => ['required_with:add', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'remove' => 'required_without:add|string|max:64',
        ]);

        $add = $input['add'] ?? null;
        if ($add !== null && in_array($add['operator'], ['>', '>=', '<', '<='], true) && ! is_numeric($add['value'] ?? null)) {
            return response()->json(['message' => 'More and less compare numbers: give it a number.'], 422);
        }

        $rule = $add === null ? null : ['id' => $add['id'] ?? (string) str()->uuid(), 'target' => $add['target'], 'row' => (int) $add['row'], 'col' => (int) $add['col'], 'operator' => $add['operator'], 'value' => (string) ($add['value'] ?? ''), 'color' => $add['color']];

        return $this->changeRules($spreadsheet, $sheetTab, (int) $input['version'], 'conditional_formatting_rules', $rule, $input['remove'] ?? null, self::MAX_RULES, 'A sheet holds at most '.self::MAX_RULES.' colour rules.');
    }

    /**
     * Add a rule about what cells may hold or take one off, shared with the classic editor, which can only
     * add them, one cell at a time. Values already in the cells are left alone.
     */
    public function allowed(Request $request, Spreadsheet $spreadsheet, SheetTab $sheetTab): JsonResponse
    {
        $this->authorizeTab('update', $spreadsheet, $sheetTab);

        $lastRow = SheetStructureService::GRID_ROWS - 1;
        $lastCol = SheetStructureService::GRID_COLS - 1;
        $input = $request->validate([
            'version' => 'required|integer',
            'add' => 'required_without:remove|array',
            'add.id' => 'sometimes|string|max:64',
            'add.row' => "required_with:add|integer|min:0|max:{$lastRow}",
            'add.col' => "required_with:add|integer|min:0|max:{$lastCol}",
            'add.row2' => "sometimes|integer|gte:add.row|max:{$lastRow}",
            'add.col2' => "sometimes|integer|gte:add.col|max:{$lastCol}",
            'add.type' => 'required_with:add|in:number,text_length,list',
            'add.min' => 'sometimes|nullable|numeric',
            'add.max' => 'sometimes|nullable|numeric',
            'add.items' => 'sometimes|nullable|string|max:1000',
            'remove' => 'required_without:add|string|max:64',
        ]);

        $add = $input['add'] ?? null;
        $rule = null;
        if ($add !== null) {
            $min = $add['min'] ?? null;
            $max = $add['max'] ?? null;
            if ($min !== null && $max !== null && (float) $min > (float) $max) {
                return response()->json(['message' => 'The lowest cannot be above the highest.'], 422);
            }
            if ($add['type'] === 'text_length' && (($min === null && $max === null) || (float) $min < 0)) {
                return response()->json(['message' => 'Give a shortest or a longest length.'], 422);
            }

            $rule = array_filter([
                'id' => $add['id'] ?? (string) str()->uuid(),
                'row' => (int) $add['row'],
                'col' => (int) $add['col'],
                'row2' => isset($add['row2']) ? (int) $add['row2'] : null,
                'col2' => isset($add['col2']) ? (int) $add['col2'] : null,
                'type' => $add['type'],
                'min' => $add['type'] === 'list' ? null : $min,
                'max' => $add['type'] === 'list' ? null : $max,
                'items' => $add['type'] === 'list' ? implode(', ', CellRules::items(['items' => $add['items'] ?? ''])) : null,
            ], fn ($value) => $value !== null);

            if ($add['type'] === 'list' && ($rule['items'] ?? '') === '') {
                return response()->json(['message' => 'List what is allowed, separated by commas.'], 422);
            }
        }

        return $this->changeRules($spreadsheet, $sheetTab, (int) $input['version'], 'validation_rules', $rule, $input['remove'] ?? null, self::MAX_ALLOWED, 'A sheet holds at most '.self::MAX_ALLOWED.' rules about what cells may hold.');
    }

    /**
     * Add a rule to a list in a sheet's layout or take one off. The answer says how to undo it.
     *
     * @param  array<string, mixed>|null  $rule
     */
    private function changeRules(Spreadsheet $spreadsheet, SheetTab $sheetTab, int $version, string $key, ?array $rule, ?string $remove, int $most, string $tooMany): JsonResponse
    {
        $list = [];
        $undo = null;
        $full = false;
        $written = false;
        $busy = false;
        WriteLock::guard(function () use ($spreadsheet, $sheetTab, $version, $key, $rule, $remove, $most, &$list, &$undo, &$full, &$written) {
            $written = StructureVersion::writeIfCurrent($spreadsheet->id, $version, function () use ($sheetTab, $key, $rule, $remove, $most, &$list, &$undo, &$full) {
                SheetTab::updateLayout($sheetTab->id, function (array $layout) use ($key, $rule, $remove, $most, &$list, &$undo, &$full) {
                    $rules = is_array($layout[$key] ?? null) ? array_values($layout[$key]) : [];

                    if ($rule !== null) {
                        if (count($rules) >= $most) {
                            $full = true;

                            return $layout;
                        }
                        $rules[] = $rule;
                        $undo = ['remove' => $rule['id']];
                    } else {
                        foreach ($rules as $i => $held) {
                            if (is_array($held) && ($held['id'] ?? null) === $remove) {
                                $undo = ['add' => $held];
                                array_splice($rules, $i, 1);
                                break;
                            }
                        }
                    }

                    $list = $rules;
                    $layout[$key] = $rules;

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
        if ($full) {
            return response()->json(['message' => $tooMany], 422);
        }

        return response()->json(['rules' => $list, 'undo' => $undo]);
    }

    /**
     * Hide or show rows or columns, shared with the classic editor. The answer says how to put them back.
     */
    public function hidden(Request $request, Spreadsheet $spreadsheet, SheetTab $sheetTab): JsonResponse
    {
        $this->authorizeTab('update', $spreadsheet, $sheetTab);

        $input = $request->validate([
            'version' => 'required|integer',
            'axis' => 'required|in:col,row',
            'hide' => 'present|array|max:'.self::MAX_HIDDEN_CHANGE,
            'hide.*' => 'integer|min:0',
            'show' => 'present|array|max:'.self::MAX_HIDDEN_CHANGE,
            'show.*' => 'integer|min:0',
        ]);

        $column = $input['axis'] === 'col';
        $size = $column ? SheetStructureService::GRID_COLS : SheetStructureService::GRID_ROWS;
        $hide = array_values(array_unique(array_map('intval', $input['hide'])));
        $show = array_values(array_unique(array_map('intval', $input['show'])));
        if (array_filter([...$hide, ...$show], fn (int $at) => $at >= $size) !== []) {
            return response()->json(['message' => 'That is not a place on the sheet.'], 422);
        }

        $key = $column ? 'hidden_cols' : 'hidden_rows';
        $undo = [];
        $list = [];
        $written = false;
        $busy = false;
        try {
            WriteLock::guard(function () use ($spreadsheet, $sheetTab, $input, $key, $size, $hide, $show, &$undo, &$list, &$written) {
                $written = StructureVersion::writeIfCurrent($spreadsheet->id, (int) $input['version'], function () use ($sheetTab, $key, $size, $hide, $show, &$undo, &$list) {
                    SheetTab::updateLayout($sheetTab->id, function (array $layout) use ($key, $size, $hide, $show, &$undo, &$list) {
                        $held = array_map('intval', is_array($layout[$key] ?? null) ? $layout[$key] : []);
                        $list = array_values(array_unique([...array_diff($held, $show), ...$hide]));
                        if (count($list) >= $size) {
                            throw new HideRefused;
                        }
                        $undo = ['hide' => array_values(array_diff($held, $list)), 'show' => array_values(array_diff($list, $held))];
                        $layout[$key] = $list;

                        return $layout;
                    });
                });
            }, function () use (&$busy) {
                $busy = true;
            });
        } catch (HideRefused) {
            return response()->json(['message' => 'Leave at least one showing.'], 422);
        }

        if ($busy) {
            return response()->json(['message' => WriteLock::BUSY_MESSAGE], 503);
        }
        if (! $written) {
            return response()->json(['stale' => true, 'message' => StructureVersion::STALE_MESSAGE], 409);
        }

        return response()->json(['hidden' => $list, 'undo' => $undo]);
    }

    /**
     * Insert or delete rows or columns, all of them or none. Inserting is undone by deleting. Deleting
     * takes a safety copy first, because references to what went cannot be worked back out, and undo
     * restores it. The answer says which, or carries no undo when neither would be right.
     */
    public function structure(Request $request, Spreadsheet $spreadsheet, SheetTab $sheetTab, SheetVersions $versions): JsonResponse
    {
        $this->authorizeTab('update', $spreadsheet, $sheetTab);

        $input = $request->validate([
            'version' => 'required|integer',
            'action' => 'required|in:insert,delete',
            'axis' => 'required|in:col,row',
            'at' => 'required|integer|min:0',
            'count' => 'required|integer|min:1|max:'.self::MAX_STRUCTURE,
        ]);

        ['action' => $action, 'axis' => $axis] = $input;
        $at = (int) $input['at'];
        $count = (int) $input['count'];
        $version = (int) $input['version'];
        if ($at + $count > ($axis === 'col' ? SheetStructureService::GRID_COLS : SheetStructureService::GRID_ROWS)) {
            return response()->json(['message' => 'That is not a place on the sheet.'], 422);
        }

        $undo = $action === 'insert' ? ['structure' => ['action' => 'delete', 'axis' => $axis, 'at' => $at, 'count' => $count]] : null;
        if ($action === 'delete' && config('sheet.structure_ops_enabled') && StructureVersion::current($spreadsheet->id) === $version && $this->copiesWhole($sheetTab)) {
            $what = $axis === 'col' ? 'columns' : 'rows';
            $undo = ['restore' => $versions->snapshot($spreadsheet->id, $sheetTab->id, (int) Auth::id(), SheetVersions::SAFETY, "Before deleting {$what}")->id];
        }

        // One change however many lines: the time the service allows one is shared out, so the write lock stays short.
        $service = app(SheetStructureService::class)->withBudget(SheetStructureService::WORK_BUDGET / $count, SheetStructureService::TIME_LIMIT_SECONDS / $count);
        $change = $axis === 'row'
            ? ($action === 'insert' ? $service->insertRow(...) : $service->deleteRow(...))
            : ($action === 'insert' ? $service->insertColumn(...) : $service->deleteColumn(...));

        try {
            DB::transaction(function () use ($change, $spreadsheet, $sheetTab, $at, $count, $version) {
                for ($done = 0; $done < $count; $done++) {
                    $change($spreadsheet->id, $sheetTab->id, $at, $version + $done);
                }
            });
        } catch (StaleStructure $stale) {
            return response()->json(['stale' => true, 'message' => $stale->getMessage()], 409);
        } catch (StructureChangeRefused $refused) {
            return response()->json(['message' => $refused->getMessage()], 422);
        }

        return response()->json(['version' => StructureVersion::current($spreadsheet->id), 'undo' => $undo]);
    }

    /**
     * Whether a safety copy can stand in for undo. A very large sheet is too much to copy in a request,
     * and a copy does not hold named ranges, so formulas using them would read the wrong rows afterwards.
     */
    private function copiesWhole(SheetTab $sheetTab): bool
    {
        return Cell::where('sheet_tab_id', $sheetTab->id)->count() <= SheetVersions::AUTO_MAX_CELLS
            && NamedRange::where('sheet_tab_id', $sheetTab->id)->doesntExist();
    }

    /**
     * Put back a copy of this sheet: the safety copy a delete took, or one chosen from its history.
     */
    public function restoreCopy(Request $request, Spreadsheet $spreadsheet, SheetTab $sheetTab, SheetVersions $versions): JsonResponse
    {
        $this->authorizeTab('update', $spreadsheet, $sheetTab);

        $input = $request->validate(['version' => 'required|integer', 'copy' => 'required|integer']);

        $copy = SpreadsheetVersion::whereKey($input['copy'])
            ->where('spreadsheet_id', $spreadsheet->id)->where('sheet_tab_id', $sheetTab->id)->first();
        abort_if($copy === null, 404);

        $written = false;
        $busy = false;
        WriteLock::guard(function () use ($spreadsheet, $input, $copy, $versions, &$written) {
            $written = StructureVersion::writeIfCurrent($spreadsheet->id, (int) $input['version'], function () use ($copy, $versions) {
                $versions->restore($copy, (int) Auth::id());
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

        return response()->json(['version' => StructureVersion::current($spreadsheet->id)]);
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
            'italic' => 'sometimes|boolean',
            'underline' => 'sometimes|boolean',
            'strike' => 'sometimes|boolean',
            'number_format' => 'sometimes|in:general,percent,currency,number,date',
            'align' => 'sometimes|nullable|in:left,center,right',
            'font_color' => ['sometimes', 'nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'bg_color' => ['sometimes', 'nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'font_size' => 'sometimes|nullable|integer|min:6|max:96',
            'clear' => 'sometimes|accepted',
        ]);

        $count = ($input['r2'] - $input['r1'] + 1) * ($input['c2'] - $input['c1'] + 1);
        if ($count > self::MAX_FORMATTED) {
            return response()->json(['message' => 'Select fewer cells to format: '.number_format(self::MAX_FORMATTED).' at most.'], 422);
        }

        // Clearing is a patch of nothing that starts from nothing; a null takes one key off.
        $clear = isset($input['clear']);
        $patch = array_diff_key($input, array_flip(['version', 'r1', 'r2', 'c1', 'c2', 'clear']));
        $undo = [];
        $written = false;
        $busy = false;
        WriteLock::guard(function () use ($spreadsheet, $sheetTab, $input, $patch, $clear, $writer, &$written, &$undo) {
            $written = StructureVersion::writeIfCurrent($spreadsheet->id, (int) $input['version'], function () use ($spreadsheet, $sheetTab, $input, $patch, $clear, $writer, &$undo) {
                $undo = $writer->format($spreadsheet->id, $sheetTab->id, $input, $patch, $clear);
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
     * @param  array<string, mixed>|callable(): array<string, mixed>  $extra  more for the answer
     * @param  array<int, array{add?: list<array{r1: int, r2: int, c1: int, c2: int}>, remove?: list<array{r1: int, r2: int, c1: int, c2: int}>}>  $merges  sheet id => merged cells to change, ahead of the edits
     * @param  (callable(): list<array{id: int, tab: int, row: int, col: int}>)|null  $moveComments  moves comments after the edits, inside the lock, and says how to put them back
     */
    private function commit(Spreadsheet $spreadsheet, SheetTab $sheetTab, int $version, callable $plan, CellWriter $writer, array|callable $extra = [], array $merges = [], bool $watch = true, ?callable $moveComments = null): JsonResponse
    {
        $commentsBack = [];
        $results = [];
        $plans = [];
        $changes = [];
        $written = false;
        $busy = false;
        try {
            WriteLock::guard(function () use ($spreadsheet, $sheetTab, $version, $plan, $writer, $merges, $moveComments, &$commentsBack, &$results, &$plans, &$changes, &$written) {
                $written = StructureVersion::writeIfCurrent($spreadsheet->id, $version, function () use ($spreadsheet, $sheetTab, $plan, $writer, $merges, $moveComments, &$commentsBack, &$results, &$plans, &$changes) {
                    foreach ($merges as $tab => $change) {
                        $changes[$tab] = SheetMerges::apply($tab, $change['add'] ?? [], $change['remove'] ?? []);
                    }
                    $plans = $plan();
                    foreach ($plans as $tab => $edits) {
                        $results[$tab] = $writer->write($spreadsheet->id, $tab, $edits, (int) Auth::id());
                    }
                    $commentsBack = $moveComments === null ? [] : $moveComments();
                    app(SheetVersions::class)->autoSnapshot($spreadsheet->id, $sheetTab->id, (int) Auth::id());
                });
            }, function () use (&$busy) {
                $busy = true;
            });
        } catch (MergeRefused|SortRefused $refused) {
            return response()->json(['message' => $refused->getMessage()], 422);
        }

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

        // Values that only changed places are no news: a rule should not fire because rows were sorted.
        foreach ($watch ? $plans : [] as $tab => $edits) {
            $refused = array_map(fn (array $r) => $r['row'].':'.$r['col'], $results[$tab]['refused']);
            $written = array_values(array_filter($edits, fn (array $e) => ! in_array($e['row'].':'.$e['col'], $refused, true)));
            $this->workflows->cellsWritten($spreadsheet, $tab, $written, (int) Auth::id());
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
        foreach (array_keys($results + $changes) as $tab) {
            $undo[] = ['tab' => $tab, 'edits' => $results[$tab]['undo'] ?? [], ...(isset($changes[$tab]) ? ['merges' => $changes[$tab]['undo']] : [])];
        }
        if ($commentsBack !== []) {
            $undo[0]['comments'] = $commentsBack;
        }

        return response()->json([
            'refused' => $here['refused'],
            'cells' => $large ? [] : $this->cellsAt($sheetTab->id, array_values(array_unique($changed))),
            // An answer of many cells changed shape somewhere: the browser reloads what it holds.
            'reload' => $large || Cell::where('sheet_tab_id', $sheetTab->id)->whereNotNull('spill_rows')->exists(),
            'undo' => $undo,
            'version' => StructureVersion::current($spreadsheet->id),
            ...(isset($changes[$sheetTab->id]) ? ['merges' => $changes[$sheetTab->id]['list']] : []),
            ...($commentsBack !== [] ? ['marks' => app(SheetComments::class)->marks($sheetTab->id)] : []),
            ...(is_callable($extra) ? $extra() : $extra),
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
