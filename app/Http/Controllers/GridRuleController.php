<?php

namespace App\Http\Controllers;

use App\Models\Spreadsheet;
use App\Services\WorkflowRules;
use App\Services\WriteLock;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Workflow rules in the grid editor (EA-6). Anyone who can open the workbook sees its rules; changing them needs
 * the right to edit. Every answer is the workbook's rules as they now stand.
 */
class GridRuleController extends Controller
{
    use AuthorizesRequests;

    public function __construct(private WorkflowRules $rules) {}

    public function index(Request $request, Spreadsheet $spreadsheet): JsonResponse
    {
        $this->authorize('view', $spreadsheet);

        return $this->answer($request, $spreadsheet);
    }

    public function store(Request $request, Spreadsheet $spreadsheet): JsonResponse
    {
        $this->authorize('update', $spreadsheet);

        $input = $request->validate([
            'name' => 'required|string|min:2|max:80',
            'column' => ['required', 'regex:/^[A-Za-z]{1,3}$/'],
            'operator' => 'required|in:'.implode(',', WorkflowRules::OPERATORS),
            'value' => 'required|string|max:50',
            'action' => 'required|in:'.implode(',', WorkflowRules::ACTIONS),
            'email' => 'sometimes|boolean',
            'in_app' => 'sometimes|boolean',
        ], [
            'name.required' => 'Give the rule a name.',
            'column.regex' => 'Name the column by its letters, for example B.',
            'value.required' => 'Say what the cell is compared with.',
        ]);

        $refused = null;
        $busy = false;
        WriteLock::guard(function () use ($spreadsheet, $input, &$refused) {
            $refused = $this->rules->add($spreadsheet->id, [
                'name' => $input['name'], 'column' => $input['column'], 'operator' => $input['operator'], 'value' => $input['value'],
                'action' => $input['action'], 'email' => (bool) ($input['email'] ?? true), 'inApp' => (bool) ($input['in_app'] ?? false),
            ]);
        }, function () use (&$busy) {
            $busy = true;
        });

        if ($busy) {
            return response()->json(['message' => WriteLock::BUSY_MESSAGE], 503);
        }

        return $refused !== null ? response()->json(['message' => $refused], 422) : $this->answer($request, $spreadsheet->refresh());
    }

    public function destroy(Request $request, Spreadsheet $spreadsheet, string $rule): JsonResponse
    {
        $this->authorize('update', $spreadsheet);

        $busy = false;
        WriteLock::guard(fn () => $this->rules->remove($spreadsheet->id, $rule), function () use (&$busy) {
            $busy = true;
        });

        return $busy ? response()->json(['message' => WriteLock::BUSY_MESSAGE], 503) : $this->answer($request, $spreadsheet->refresh());
    }

    private function answer(Request $request, Spreadsheet $spreadsheet): JsonResponse
    {
        return response()->json([
            'can_change' => $request->user()->can('update', $spreadsheet),
            'rules' => array_map(fn (array $rule) => [
                'id' => (string) ($rule['id'] ?? ''),
                'name' => (string) ($rule['name'] ?? 'Rule'),
                'column' => (string) ($rule['column'] ?? ''),
                'operator' => (string) ($rule['operator'] ?? '=='),
                'value' => (string) ($rule['value'] ?? ''),
                'action' => (string) ($rule['action'] ?? 'email_owner'),
                'channels' => array_values((array) ($rule['notify_channels'] ?? ['email'])),
                'enabled' => (bool) ($rule['enabled'] ?? true),
            ], $this->rules->of($spreadsheet)),
        ]);
    }
}
