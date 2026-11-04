<?php

namespace App\Http\Controllers;

use App\Models\PivotTable;
use App\Models\SheetTab;
use App\Models\Spreadsheet;
use App\Services\Formula\Recalculator;
use App\Services\PivotRefused;
use App\Services\PivotTableService;
use App\Services\WriteLock;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Pivot tables in the new editor. What one shows is worked out when it is asked for, so it always
 * agrees with the sheet.
 */
class GridPivotController extends Controller
{
    use AuthorizesRequests;

    public function __construct(private PivotTableService $pivots) {}

    public function index(Spreadsheet $spreadsheet, SheetTab $sheetTab): JsonResponse
    {
        $this->authorizeTab('view', $spreadsheet, $sheetTab);

        return $this->answer($spreadsheet, $sheetTab);
    }

    /** The columns of a table, so the form offers its headings. */
    public function fields(Request $request, Spreadsheet $spreadsheet, SheetTab $sheetTab): JsonResponse
    {
        $this->authorizeTab('update', $spreadsheet, $sheetTab);

        $input = $request->validate(['range' => 'required|string|max:40']);

        return response()->json(['fields' => $this->pivots->fields($sheetTab->id, $input['range'])]);
    }

    public function show(Spreadsheet $spreadsheet, SheetTab $sheetTab, PivotTable $pivot, Recalculator $recalculator): JsonResponse
    {
        $this->authorizeTab('view', $spreadsheet, $sheetTab);
        abort_unless($pivot->sheet_tab_id === $sheetTab->id, 404);

        $recalculator->ensureBuilt($spreadsheet->id, $sheetTab->id);

        return response()->json([
            'id' => $pivot->id,
            'title' => $pivot->title,
            'aggregation' => $pivot->aggregation,
            'across' => $pivot->column_field !== null,
            'filter' => $pivot->filter_field === null ? null : $this->heading($sheetTab, $pivot, $pivot->filter_field).' is '.$pivot->filter_value,
            'headings' => [
                'row' => $this->heading($sheetTab, $pivot, $pivot->row_field),
                'value' => $this->heading($sheetTab, $pivot, $pivot->value_field),
            ],
            ...$this->pivots->compute($pivot),
        ]);
    }

    /** The rows behind one figure. */
    public function records(Request $request, Spreadsheet $spreadsheet, SheetTab $sheetTab, PivotTable $pivot, Recalculator $recalculator): JsonResponse
    {
        $this->authorizeTab('view', $spreadsheet, $sheetTab);
        abort_unless($pivot->sheet_tab_id === $sheetTab->id, 404);

        $input = $request->validate(['row' => 'sometimes|nullable|string|max:255', 'column' => 'sometimes|nullable|string|max:255']);

        $recalculator->ensureBuilt($spreadsheet->id, $sheetTab->id);

        return response()->json($this->pivots->records($pivot, $input['row'] ?? null, $input['column'] ?? null));
    }

    public function store(Request $request, Spreadsheet $spreadsheet, SheetTab $sheetTab): JsonResponse
    {
        $this->authorizeTab('update', $spreadsheet, $sheetTab);

        $input = $request->validate([
            'title' => 'required|string|max:100',
            'source_range' => 'required|string|max:40',
            'row_field' => 'required|string|max:3',
            'column_field' => 'present|nullable|string|max:3',
            'value_field' => 'required|string|max:3',
            'aggregation' => ['required', Rule::in(PivotTable::AGGREGATIONS)],
            'filter_field' => 'sometimes|nullable|string|max:3',
            'filter_value' => 'sometimes|nullable|string|max:255',
        ], [
            'title.required' => 'Give the pivot table a title.',
            'title.max' => 'Use a shorter title: 100 characters at most.',
            'source_range.required' => 'Say which table to summarise.',
            'row_field.required' => 'Choose what to group by.',
            'value_field.required' => 'Choose what to add up.',
        ]);

        try {
            $pivot = null;
            $busy = false;
            WriteLock::guard(function () use ($spreadsheet, $sheetTab, $input, &$pivot) {
                $pivot = $this->pivots->add($spreadsheet->id, $sheetTab->id, $input['title'], $input['source_range'], $input['row_field'], $input['column_field'], $input['value_field'], $input['aggregation'], $input['filter_field'] ?? null, $input['filter_value'] ?? null);
            }, function () use (&$busy) {
                $busy = true;
            });
        } catch (PivotRefused $refused) {
            return response()->json(['message' => $refused->getMessage()], 422);
        }

        return $busy ? response()->json(['message' => WriteLock::BUSY_MESSAGE], 503) : $this->answer($spreadsheet, $sheetTab, $pivot?->id);
    }

    public function destroy(Spreadsheet $spreadsheet, SheetTab $sheetTab, PivotTable $pivot): JsonResponse
    {
        $this->authorizeTab('update', $spreadsheet, $sheetTab);
        abort_unless($pivot->sheet_tab_id === $sheetTab->id, 404);

        $busy = false;
        WriteLock::guard(fn () => $pivot->delete(), function () use (&$busy) {
            $busy = true;
        });

        return $busy ? response()->json(['message' => WriteLock::BUSY_MESSAGE], 503) : $this->answer($spreadsheet, $sheetTab);
    }

    private function heading(SheetTab $sheetTab, PivotTable $pivot, string $field): string
    {
        foreach ($this->pivots->fields($sheetTab->id, $pivot->source_range) as $column) {
            if ($column['col'] === $field) {
                return $column['name'];
            }
        }

        return $field;
    }

    private function answer(Spreadsheet $spreadsheet, SheetTab $sheetTab, ?int $added = null): JsonResponse
    {
        return response()->json(['pivots' => $this->pivots->of($spreadsheet->id, $sheetTab->id), 'added' => $added]);
    }

    private function authorizeTab(string $ability, Spreadsheet $spreadsheet, SheetTab $sheetTab): void
    {
        $this->authorize($ability, $spreadsheet);
        abort_unless($sheetTab->spreadsheet_id === $spreadsheet->id, 404);
    }
}
