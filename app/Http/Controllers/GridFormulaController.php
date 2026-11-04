<?php

namespace App\Http\Controllers;

use App\Models\Cell;
use App\Models\SheetTab;
use App\Models\Spreadsheet;
use App\Services\Formula\FormulaDoctor;
use App\Services\Formula\FormulaExplainer;
use App\Services\Formula\FormulaFromWords;
use App\Services\Formula\Recalculator;
use App\Services\RangeText;
use App\Services\SheetStructureService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * What a formula does and what is wrong with it, for anyone who can read the sheet. A repair comes back as
 * a formula the page writes through the normal path, so it is checked, protected and undone like any edit.
 */
class GridFormulaController extends Controller
{
    use AuthorizesRequests;

    /** A sentence read against the sheet's own headings. Changes nothing: the page writes what is taken. */
    public function fromWords(Request $request, Spreadsheet $spreadsheet, SheetTab $sheetTab, FormulaFromWords $words, FormulaExplainer $explainer, Recalculator $recalculator): JsonResponse
    {
        $this->authorize('view', $spreadsheet);
        abort_unless($sheetTab->spreadsheet_id === $spreadsheet->id, 404);

        $input = $request->validate(['sentence' => 'required|string|max:300'], ['sentence.required' => 'Say what to work out.']);

        $recalculator->ensureBuilt($spreadsheet->id, $sheetTab->id);
        $answer = $words->read($sheetTab->id, $input['sentence']);

        return response()->json($answer + (isset($answer['formula']) ? ['plain' => $explainer->explain($answer['formula'])] : []));
    }

    public function explain(Spreadsheet $spreadsheet, SheetTab $sheetTab, int $row, int $col, FormulaExplainer $explainer, FormulaDoctor $doctor, Recalculator $recalculator): JsonResponse
    {
        $this->authorize('view', $spreadsheet);
        abort_unless($sheetTab->spreadsheet_id === $spreadsheet->id, 404);
        abort_unless($row < SheetStructureService::GRID_ROWS && $col < SheetStructureService::GRID_COLS, 404);

        $recalculator->ensureBuilt($spreadsheet->id, $sheetTab->id);

        $cell = Cell::where('sheet_tab_id', $sheetTab->id)->where('row_index', $row)->where('col_index', $col)->first();
        $place = RangeText::of(['r1' => $row, 'c1' => $col, 'r2' => $row, 'c2' => $col]);

        return response()->json([
            'cell' => explode(':', $place)[0],
            'formula' => $cell?->formula,
            'plain' => $cell?->formula === null ? null : $explainer->explain((string) $cell->formula),
            'error' => $cell === null ? null : $doctor->diagnose($cell),
        ]);
    }
}
