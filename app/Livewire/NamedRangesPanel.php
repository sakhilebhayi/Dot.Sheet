<?php

namespace App\Livewire;

use App\Models\NamedRange;
use App\Models\Spreadsheet;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Cache;
use Livewire\Component;

/**
 * Lets a user name a cell/range (e.g. "Revenue" for A1:A40) and reference
 * that name directly in a formula (=SUM(Revenue)) instead of raw
 * coordinates -- see FormulaEvaluatorService::substituteNamedRanges() for
 * how the name is expanded before evaluation.
 *
 * Reserved function words (SUM, IF, ...) and anything that already looks
 * like a cell reference (A1, BC23) are rejected as names: either would be
 * ambiguous with the formula syntax it's meant to make more readable.
 */
class NamedRangesPanel extends Component
{
    use AuthorizesRequests;

    private const RESERVED_WORDS = [
        'SUM', 'AVERAGE', 'AVG', 'COUNT', 'MAX', 'MIN', 'ROUND', 'ABS',
        'IF', 'CONCAT', 'UPPER', 'LOWER', 'LEN', 'TRUE', 'FALSE',
    ];

    public $spreadsheet_id;

    public $sheet_tab_id;

    public $ranges = [];

    public $name = '';

    public $rangeInput = '';

    public $error = '';

    public function mount($spreadsheet_id, $sheet_tab_id = null)
    {
        $this->authorize('view', Spreadsheet::findOrFail($spreadsheet_id));

        $this->spreadsheet_id = $spreadsheet_id;
        $this->sheet_tab_id = $sheet_tab_id;
        $this->loadRanges();
    }

    public function render()
    {
        return view('livewire.named-ranges-panel');
    }

    public function loadRanges(): void
    {
        $this->ranges = NamedRange::where('spreadsheet_id', $this->spreadsheet_id)
            ->where('sheet_tab_id', $this->sheet_tab_id)
            ->orderBy('name')
            ->get()
            ->map(fn (NamedRange $range) => [
                'id' => $range->id,
                'name' => $range->name,
                'reference' => $range->toReferenceText(),
            ])
            ->toArray();
    }

    public function createRange(): void
    {
        $this->authorize('update', Spreadsheet::findOrFail($this->spreadsheet_id));

        $this->error = '';

        $name = trim($this->name);
        $range = strtoupper(trim($this->rangeInput));

        if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/', $name)) {
            $this->error = 'Name must start with a letter or underscore and contain only letters, numbers, and underscores.';

            return;
        }

        if (preg_match('/^[A-Z]{1,3}\d+$/', strtoupper($name))
            || in_array(strtoupper($name), self::RESERVED_WORDS, true)) {
            $this->error = "\"{$name}\" looks like a cell reference or function name and can't be used.";

            return;
        }

        $alreadyExists = NamedRange::where('sheet_tab_id', $this->sheet_tab_id)
            ->whereRaw('LOWER(name) = ?', [strtolower($name)])
            ->exists();

        if ($alreadyExists) {
            $this->error = "A named range called \"{$name}\" already exists on this sheet.";

            return;
        }

        $bounds = $this->parseRangeInput($range);
        if (! $bounds) {
            $this->error = 'Range must look like A1 or A1:B10.';

            return;
        }

        NamedRange::create([
            'spreadsheet_id' => $this->spreadsheet_id,
            'sheet_tab_id' => $this->sheet_tab_id,
            'name' => $name,
            'start_row' => $bounds['start_row'],
            'start_col' => $bounds['start_col'],
            'end_row' => $bounds['end_row'],
            'end_col' => $bounds['end_col'],
        ]);

        $this->forgetFormulaCaches();
        $this->name = '';
        $this->rangeInput = '';
        $this->loadRanges();
        $this->dispatch('named-range-changed');
    }

    public function deleteRange($rangeId): void
    {
        $this->authorize('update', Spreadsheet::findOrFail($this->spreadsheet_id));

        NamedRange::where('spreadsheet_id', $this->spreadsheet_id)
            ->where('sheet_tab_id', $this->sheet_tab_id)
            ->where('id', $rangeId)
            ->delete();

        $this->forgetFormulaCaches();
        $this->loadRanges();
        $this->dispatch('named-range-changed');
    }

    /**
     * @return array{start_row:int,start_col:int,end_row:int,end_col:int}|null
     */
    protected function parseRangeInput(string $range): ?array
    {
        if (preg_match('/^([A-Z]{1,3})(\d+)$/', $range, $m)) {
            $row = (int) $m[2] - 1;
            $col = $this->colLetterToIndex($m[1]);

            return ['start_row' => $row, 'start_col' => $col, 'end_row' => $row, 'end_col' => $col];
        }

        if (preg_match('/^([A-Z]{1,3})(\d+):([A-Z]{1,3})(\d+)$/', $range, $m)) {
            $startRow = (int) $m[2] - 1;
            $startCol = $this->colLetterToIndex($m[1]);
            $endRow = (int) $m[4] - 1;
            $endCol = $this->colLetterToIndex($m[3]);

            return [
                'start_row' => min($startRow, $endRow),
                'start_col' => min($startCol, $endCol),
                'end_row' => max($startRow, $endRow),
                'end_col' => max($startCol, $endCol),
            ];
        }

        return null;
    }

    protected function colLetterToIndex(string $letter): int
    {
        $index = 0;
        for ($i = 0; $i < strlen($letter); $i++) {
            $index = $index * 26 + (ord($letter[$i]) - 64);
        }

        return $index - 1;
    }

    protected function forgetFormulaCaches(): void
    {
        Cache::forget("spreadsheet:{$this->spreadsheet_id}:tab:{$this->sheet_tab_id}:named_ranges");
        Cache::forget("spreadsheet:{$this->spreadsheet_id}:tab:{$this->sheet_tab_id}:formula_dependency_graph");
    }
}
