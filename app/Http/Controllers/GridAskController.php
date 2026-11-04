<?php

namespace App\Http\Controllers;

use App\Models\AiPrompt;
use App\Models\SheetTab;
use App\Models\Spreadsheet;
use App\Models\User;
use App\Services\SheetAssistant;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Asking about a sheet, and the record of what came of it.
 */
class GridAskController extends Controller
{
    use AuthorizesRequests;

    private const MOST_LISTED = 20;

    public function ask(Request $request, Spreadsheet $spreadsheet, SheetTab $sheetTab, SheetAssistant $assistant): JsonResponse
    {
        $this->authorize('view', $spreadsheet);
        abort_unless($sheetTab->spreadsheet_id === $spreadsheet->id, 404);

        $input = $request->validate(['question' => 'required|string|max:500', 'selection' => 'nullable|string|max:40'], ['question.required' => 'Say what you would like to know.']);

        return response()->json($assistant->ask($spreadsheet, $sheetTab, $request->user(), $input['question'], $input['selection'] ?? null));
    }

    /** A proposal was taken. What it was stays on the record. */
    public function applied(Request $request, Spreadsheet $spreadsheet, AiPrompt $prompt): JsonResponse
    {
        $this->authorize('update', $spreadsheet);
        abort_unless($prompt->spreadsheet_id === $spreadsheet->id, 404);

        $input = $request->validate(['what' => 'required|string|max:300']);

        $context = is_array($prompt->context) ? $prompt->context : [];
        $context['applied'] = [...($context['applied'] ?? []), ['what' => $input['what'], 'by' => $request->user()->id, 'at' => now()->toIso8601String()]];
        $prompt->update(['context' => $context]);

        return response()->json(['ok' => true]);
    }

    public function record(Spreadsheet $spreadsheet): JsonResponse
    {
        $this->authorize('view', $spreadsheet);

        $asked = AiPrompt::where('spreadsheet_id', $spreadsheet->id)->orderByDesc('created_at')->orderByDesc('id')->limit(self::MOST_LISTED)->get();
        $names = User::whereIn('id', $asked->pluck('user_id'))->pluck('name', 'id');

        return response()->json(['asked' => $asked->map(fn (AiPrompt $prompt) => [
            'id' => $prompt->id,
            'question' => $prompt->prompt,
            'who' => $names[$prompt->user_id] ?? null,
            'applied' => array_column($prompt->context['applied'] ?? [], 'what'),
        ])->values()->all()]);
    }
}
