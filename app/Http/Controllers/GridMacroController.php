<?php

namespace App\Http\Controllers;

use App\Models\Spreadsheet;
use App\Services\SheetMacros;
use App\Services\WriteLock;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Macros in the new editor. They need the right to edit, as playing one writes cells. Every answer is
 * the spreadsheet's macros as they now stand.
 */
class GridMacroController extends Controller
{
    use AuthorizesRequests;

    public function __construct(private SheetMacros $macros) {}

    public function index(Spreadsheet $spreadsheet): JsonResponse
    {
        $this->authorize('update', $spreadsheet);

        return $this->answer($spreadsheet);
    }

    public function store(Request $request, Spreadsheet $spreadsheet): JsonResponse
    {
        $this->authorize('update', $spreadsheet);

        $input = $request->validate(['name' => 'present|nullable|string|max:80', 'actions' => 'required|array|max:'.SheetMacros::MAX_ACTIONS], [
            'name.max' => 'Use a shorter name: 80 characters at most.',
            'actions.required' => 'Nothing was recorded.',
            'actions.max' => 'A macro can hold '.number_format(SheetMacros::MAX_ACTIONS).' edits at most.',
        ]);

        $refused = null;
        $busy = false;
        WriteLock::guard(function () use ($spreadsheet, $input, &$refused) {
            $refused = $this->macros->add($spreadsheet->id, (string) $input['name'], $input['actions']);
        }, function () use (&$busy) {
            $busy = true;
        });

        if ($busy) {
            return response()->json(['message' => WriteLock::BUSY_MESSAGE], 503);
        }

        return $refused !== null ? response()->json(['message' => $refused], 422) : $this->answer($spreadsheet->refresh());
    }

    public function destroy(Spreadsheet $spreadsheet, string $macro): JsonResponse
    {
        $this->authorize('update', $spreadsheet);

        $busy = false;
        WriteLock::guard(fn () => $this->macros->remove($spreadsheet->id, $macro), function () use (&$busy) {
            $busy = true;
        });

        return $busy ? response()->json(['message' => WriteLock::BUSY_MESSAGE], 503) : $this->answer($spreadsheet->refresh());
    }

    private function answer(Spreadsheet $spreadsheet): JsonResponse
    {
        return response()->json(['macros' => array_map(fn (array $macro) => [
            'id' => $macro['id'] ?? '',
            'name' => $macro['name'] ?? 'Macro',
            'edits' => $this->edits($macro),
        ], $this->macros->of($spreadsheet))]);
    }

    /**
     * Only checked edits reach the page, however old the macro is.
     *
     * @param  array<string, mixed>  $macro
     * @return list<array<string, mixed>>
     */
    private function edits(array $macro): array
    {
        $actions = is_array($macro['actions'] ?? null) ? $macro['actions'] : [];

        return array_values(array_filter($this->macros->clean($actions), fn (array $action) => $action['type'] === 'set'));
    }
}
