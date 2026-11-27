<?php

namespace App\Services;

use App\Models\Cell;
use App\Models\Spreadsheet;
use App\Models\User;
use App\Models\WorkflowExecutionLog;
use App\Notifications\WorkflowRuleTriggered;
use Illuminate\Support\Facades\Cache;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use Throwable;

/**
 * A sheet's workflow rules: when a cell in a watched column meets a condition, tell the owner and, if the
 * rule says so, mark the cell. Both editors come through here, so a rule holds wherever someone types.
 */
class SheetWorkflows
{
    /** Most cells one change is checked for, so a large paste cannot send hundreds of emails. */
    public const MAX_WATCHED = 200;

    private const HIGHLIGHT = '#FEF08A';

    /** The same rule stays quiet for the same value in the same cell for this long. */
    private const QUIET_SECONDS = 300;

    /**
     * While one change's cells are checked, the notices it raises, one per rule (EA-2, E-08): null outside a batch.
     *
     * @var array<string, array{rule: array<string, mixed>, first: string, value: mixed, channels: list<string>, count: int, logs: list<int>}>|null
     */
    private ?array $batch = null;

    /**
     * @param  list<array{row: int, col: int}>  $written
     */
    public function cellsWritten(Spreadsheet $spreadsheet, int $sheetTabId, array $written, ?int $userId): void
    {
        if ($written === [] || count($written) > self::MAX_WATCHED || $this->rules($spreadsheet) === []) {
            return;
        }

        $cells = Cell::where('sheet_tab_id', $sheetTabId)
            ->whereIn('row_index', array_unique(array_column($written, 'row')))
            ->whereIn('col_index', array_unique(array_column($written, 'col')))
            ->get()->keyBy(fn (Cell $cell) => $cell->row_index.':'.$cell->col_index);

        $this->batch = [];
        try {
            foreach ($written as ['row' => $row, 'col' => $col]) {
                $cell = $cells[$row.':'.$col] ?? null;
                if ($cell !== null) {
                    $this->cellChanged($spreadsheet, $sheetTabId, $row, $col, $cell->computed_value ?? $cell->raw_value, $userId);
                }
            }
        } finally {
            /** @var array<string, array{rule: array<string, mixed>, first: string, value: mixed, channels: list<string>, count: int, logs: list<int>}> $batch */
            $batch = $this->batch ?? [];
            $this->batch = null;
        }

        foreach ($batch as $notice) {
            if (! $this->send($spreadsheet, $notice['rule'], $notice['first'], $notice['value'], $notice['channels'], $notice['count'] - 1)) {
                WorkflowExecutionLog::whereIn('id', $notice['logs'])->update(['status' => 'failed']);
            }
        }
    }

    /**
     * Run the rules that watch one cell's column.
     *
     * @return bool true when a rule marked the cell
     */
    public function cellChanged(Spreadsheet $spreadsheet, int $sheetTabId, int $row, int $col, mixed $value, ?int $userId): bool
    {
        $column = Coordinate::stringFromColumnIndex($col + 1);
        $marked = false;

        foreach ($this->rules($spreadsheet) as $rule) {
            if (! is_array($rule) || ! ($rule['enabled'] ?? true) || strtoupper((string) ($rule['column'] ?? '')) !== $column) {
                continue;
            }
            if (! $this->meets($value, (string) ($rule['operator'] ?? '=='), $rule['value'] ?? null)) {
                continue;
            }

            $ruleId = (string) ($rule['id'] ?? md5((string) json_encode($rule)));
            $quiet = "workflow:{$spreadsheet->id}:{$sheetTabId}:{$ruleId}:{$row}:{$col}:".md5((string) $value);
            if (! Cache::add($quiet, true, self::QUIET_SECONDS)) {
                continue;
            }

            $channels = $this->channels($rule);
            $reference = $column.($row + 1);
            $sent = $this->notifyOwner($spreadsheet, $rule, $reference, $value, $channels);

            $action = (string) ($rule['action'] ?? 'email_owner');
            if ($action === 'highlight_cell' && $this->mark($sheetTabId, $row, $col)) {
                $marked = true;
            }

            $log = WorkflowExecutionLog::create([
                'spreadsheet_id' => $spreadsheet->id,
                'triggered_by_user_id' => $userId,
                'rule_id' => $ruleId,
                'rule_name' => (string) ($rule['name'] ?? 'Workflow Rule'),
                'rule_action' => $action,
                'rule_operator' => (string) ($rule['operator'] ?? ''),
                'rule_expected_value' => (string) ($rule['value'] ?? ''),
                'row_index' => $row,
                'col_index' => $col,
                'cell_reference' => $reference,
                'actual_value' => is_scalar($value) || $value === null ? (string) $value : json_encode($value),
                'notification_channels' => $channels,
                'status' => ! $sent ? 'failed' : match ($action) {
                    'highlight_cell' => 'highlighted',
                    'email_owner' => 'notified',
                    default => 'triggered',
                },
            ]);

            if ($this->batch !== null && isset($this->batch[$ruleId])) {
                $this->batch[$ruleId]['logs'][] = $log->id;
            }
        }

        return $marked;
    }

    /**
     * @return array<int|string, mixed>
     */
    private function rules(Spreadsheet $spreadsheet): array
    {
        $rules = $spreadsheet->settings['ai_workflows'] ?? [];

        return is_array($rules) ? $rules : [];
    }

    private function meets(mixed $actual, string $operator, mixed $expected): bool
    {
        $left = is_numeric($actual) ? (float) $actual : (string) $actual;
        $right = is_numeric($expected) ? (float) $expected : (string) $expected;

        return match ($operator) {
            '<' => $left < $right,
            '<=' => $left <= $right,
            '>' => $left > $right,
            '>=' => $left >= $right,
            '!=' => $left != $right,
            default => $left == $right,
        };
    }

    /**
     * @param  array<string, mixed>  $rule
     * @return list<string>
     */
    private function channels(array $rule): array
    {
        $channels = $rule['notify_channels'] ?? ['email'];
        $allowed = is_array($channels) ? array_values(array_intersect($channels, ['email', 'database'])) : [];

        return $allowed === [] ? ['email'] : $allowed;
    }

    /**
     * Mail that cannot be sent must not fail the edit that set it off, which has already been saved.
     *
     * @param  array<string, mixed>  $rule
     * @param  list<string>  $channels
     */
    private function notifyOwner(Spreadsheet $spreadsheet, array $rule, string $reference, mixed $value, array $channels): bool
    {
        if ($this->batch !== null) {
            $key = (string) ($rule['id'] ?? md5((string) json_encode($rule)));
            if (isset($this->batch[$key])) {
                $this->batch[$key]['count']++;
            } else {
                $this->batch[$key] = ['rule' => $rule, 'first' => $reference, 'value' => $value, 'channels' => $channels, 'count' => 1, 'logs' => []];
            }

            return true;
        }

        return $this->send($spreadsheet, $rule, $reference, $value, $channels, 0);
    }

    /**
     * @param  array<string, mixed>  $rule
     * @param  list<string>  $channels
     */
    private function send(Spreadsheet $spreadsheet, array $rule, string $reference, mixed $value, array $channels, int $more): bool
    {
        $owner = $spreadsheet->owner;
        if (! $owner instanceof User) {
            return true;
        }

        try {
            $owner->notify(new WorkflowRuleTriggered(
                spreadsheetId: $spreadsheet->id,
                spreadsheetName: (string) $spreadsheet->name,
                ruleName: (string) ($rule['name'] ?? 'Workflow Rule'),
                cellReference: $reference,
                actualValue: $value,
                channels: $channels,
                more: $more,
            ));
        } catch (Throwable $failed) {
            report($failed);

            return false;
        }

        return true;
    }

    private function mark(int $sheetTabId, int $row, int $col): bool
    {
        $cell = Cell::where('sheet_tab_id', $sheetTabId)->where('row_index', $row)->where('col_index', $col)->first();
        if ($cell === null) {
            return false;
        }

        $cell->formatting = [...($cell->formatting ?? []), 'bg_color' => self::HIGHLIGHT];
        $cell->save();

        return true;
    }
}
