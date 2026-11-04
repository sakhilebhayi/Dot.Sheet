<?php

namespace App\Http\Controllers;

use App\Models\ChartConfig;
use App\Models\SheetTab;
use App\Models\Spreadsheet;
use App\Services\ChartRefused;
use App\Services\Formula\Recalculator;
use App\Services\SheetCharts;
use App\Services\WriteLock;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Charts in the new editor. A list answer is the sheet's charts as they now stand. What a chart shows is
 * read when it is asked for, so it always agrees with the sheet.
 */
class GridChartController extends Controller
{
    use AuthorizesRequests;

    public function __construct(private SheetCharts $charts) {}

    public function index(Spreadsheet $spreadsheet, SheetTab $sheetTab): JsonResponse
    {
        $this->authorizeTab('view', $spreadsheet, $sheetTab);

        return $this->answer($spreadsheet, $sheetTab);
    }

    public function show(Spreadsheet $spreadsheet, SheetTab $sheetTab, ChartConfig $chart, Recalculator $recalculator): JsonResponse
    {
        $this->authorizeTab('view', $spreadsheet, $sheetTab);
        abort_unless($chart->sheet_tab_id === $sheetTab->id, 404);

        $recalculator->ensureBuilt($spreadsheet->id, $sheetTab->id);

        return response()->json([
            'id' => $chart->id,
            'title' => $chart->title,
            'type' => $chart->type,
            ...$this->charts->read($sheetTab->id, $chart->data_range, $chart->labels_range),
        ]);
    }

    public function store(Request $request, Spreadsheet $spreadsheet, SheetTab $sheetTab): JsonResponse
    {
        $this->authorizeTab('update', $spreadsheet, $sheetTab);

        $input = $request->validate([
            'title' => 'required|string|max:100',
            'type' => ['required', Rule::in(SheetCharts::TYPES)],
            'data_range' => 'required|string|max:40',
            'labels_range' => 'present|nullable|string|max:40',
        ], [
            'title.required' => 'Give the chart a title.',
            'title.max' => 'Use a shorter title: 100 characters at most.',
            'data_range.required' => 'Say which cells hold the figures.',
        ]);

        try {
            $chart = null;
            $busy = false;
            WriteLock::guard(function () use ($spreadsheet, $sheetTab, $input, &$chart) {
                $chart = $this->charts->add($spreadsheet->id, $sheetTab->id, $input['title'], $input['type'], $input['data_range'], $input['labels_range']);
            }, function () use (&$busy) {
                $busy = true;
            });
        } catch (ChartRefused $refused) {
            return response()->json(['message' => $refused->getMessage()], 422);
        }

        return $busy ? response()->json(['message' => WriteLock::BUSY_MESSAGE], 503) : $this->answer($spreadsheet, $sheetTab, $chart?->id);
    }

    public function destroy(Spreadsheet $spreadsheet, SheetTab $sheetTab, ChartConfig $chart): JsonResponse
    {
        $this->authorizeTab('update', $spreadsheet, $sheetTab);
        abort_unless($chart->sheet_tab_id === $sheetTab->id, 404);

        $busy = false;
        WriteLock::guard(fn () => $chart->delete(), function () use (&$busy) {
            $busy = true;
        });

        return $busy ? response()->json(['message' => WriteLock::BUSY_MESSAGE], 503) : $this->answer($spreadsheet, $sheetTab);
    }

    private function answer(Spreadsheet $spreadsheet, SheetTab $sheetTab, ?int $added = null): JsonResponse
    {
        return response()->json(['charts' => $this->charts->of($spreadsheet->id, $sheetTab->id), 'added' => $added]);
    }

    private function authorizeTab(string $ability, Spreadsheet $spreadsheet, SheetTab $sheetTab): void
    {
        $this->authorize($ability, $spreadsheet);
        abort_unless($sheetTab->spreadsheet_id === $spreadsheet->id, 404);
    }
}
