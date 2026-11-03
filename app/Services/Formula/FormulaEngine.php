<?php

namespace App\Services\Formula;

use App\Models\NamedRange;
use App\Models\SheetTab;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet as Workbook;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Works out one formula with PhpSpreadsheet's calculation engine (the one Excel files are read and
 * written with), against the stored values of exactly the cells the formula reads.
 *
 * Each sheet is put into a small in-memory workbook under an alias (S<id>), so any sheet name works,
 * and only the referenced ranges are loaded. A formula cell's value is its stored result: the
 * Recalculator works dependants out in order, so those results are current.
 *
 * Usage: begin(), evaluate() for each formula in order, finish().
 */
class FormulaEngine
{
    /** Excel's error values, which a result or a stored value can be. */
    public const ERRORS = ['#NULL!', '#DIV/0!', '#VALUE!', '#REF!', '#NAME?', '#NUM!', '#N/A', '#SPILL!', '#CALC!'];

    /** A formula's value when it cannot be read at all. */
    public const UNREADABLE = '#ERROR!';

    /** Most cells one formula may read; past it the formula answers an error instead of loading them. */
    public const MAX_CELLS_READ = 200000;

    /** What each error means, for the person who sees it. */
    public const EXPLANATIONS = [
        '#DIV/0!' => 'The formula divides by zero or by an empty cell.',
        '#VALUE!' => 'A value has the wrong type for this calculation, such as text where a number is needed.',
        '#REF!' => 'The formula refers to a cell or sheet that does not exist.',
        '#NAME?' => 'The formula uses a name or function that is not recognised.',
        '#NUM!' => 'The calculation gives a number that is not valid, or too large.',
        '#N/A' => 'A value the formula looks for was not found.',
        '#NULL!' => 'Two ranges in the formula do not overlap.',
        '#SPILL!' => 'The result needs more cells, and something is in the way.',
        '#CALC!' => 'The calculation gives no result, such as a filter that matches nothing.',
    ];

    /** Most cells held in memory at once (about 1 KB each): a host may allow a process only 128 MB. */
    public const MAX_CELLS_HELD = 60000;

    /** @var array<string, int> sheet name => id */
    private array $tabs = [];

    /** @var array<int, array<string, string>> */
    private array $names = [];

    private ?Workbook $workbook = null;

    /** @var array<int, Worksheet> */
    private array $sheets = [];

    /** @var array<int, list<array{0: int, 1: int, 2: int, 3: int}>> tab id => loaded ranges [r1, r2, c1, c2] */
    private array $loaded = [];

    private int $held = 0;

    private int $spreadsheetId = 0;

    /**
     * Start a pass over one workbook: the formulas evaluated until finish() share one in-memory
     * workbook, so each range is read from the database once. Give it the ranges the pass will read.
     *
     * @param  list<array{0: int, 1: int, 2: int, 3: int, 4: int}>  $ranges  [tab, r1, r2, c1, c2]
     */
    public function begin(int $spreadsheetId, array $ranges = []): void
    {
        $this->finish();

        $this->spreadsheetId = $spreadsheetId;
        $this->tabs = SheetTab::where('spreadsheet_id', $spreadsheetId)->pluck('id', 'name')->map(fn ($id) => (int) $id)->all();
        $this->names = [];
        $this->reset();

        foreach ($this->widest($ranges) as [$tab, $r1, $r2, $c1, $c2]) {
            if ($this->held < self::MAX_CELLS_HELD) {
                $this->load($tab, $r1, $r2, $c1, $c2);
            }
        }
    }

    public function finish(): void
    {
        $this->workbook?->disconnectWorksheets();
        $this->workbook = null;
        $this->sheets = [];
        $this->loaded = [];
        $this->held = 0;
    }

    /**
     * Work one formula out in the current pass. Its result takes the formula's place in the
     * in-memory workbook, so a formula evaluated after it reads the new value.
     *
     * @return array{value: string, error: ?string}
     */
    public function evaluate(int $sheetTabId, int $row, int $col, string $formula): array
    {
        $aliasFor = function (string $name): ?string {
            foreach ($this->tabs as $tabName => $id) {
                if (strcasecmp((string) $tabName, $name) === 0) {
                    return 'S'.$id;
                }
            }

            return null;
        };

        $text = FormulaText::withNamedRanges($formula, $this->names[$sheetTabId] ??= $this->namedRanges($this->spreadsheetId, $sheetTabId));
        $text = FormulaText::withSheetAliases($text, $aliasFor);
        $target = FormulaText::columnLetters($col).($row + 1);

        try {
            $read = 0;
            foreach (FormulaText::references($text) as $reference) {
                $tabId = $reference['sheet'] === null ? $sheetTabId : (int) ltrim($reference['sheet'], 'S');
                if ($reference['sheet'] !== null && ! in_array($tabId, $this->tabs, true)) {
                    continue;   // an unknown sheet: PhpSpreadsheet answers #REF!
                }

                $read += $this->ensureLoaded($tabId, $reference['r1'], $reference['r2'], $reference['c1'], $reference['c2']);
                if ($read > self::MAX_CELLS_READ) {
                    return ['value' => '#NUM!', 'error' => 'This formula reads more than '.number_format(self::MAX_CELLS_READ).' cells.'];
                }
            }

            $sheet = $this->sheet($sheetTabId);
            $sheet->setCellValueExplicit($target, $text, DataType::TYPE_FORMULA);
            $result = $this->result($sheet->getCell($target)->getCalculatedValue());
        } catch (\Throwable $e) {
            $result = ['value' => self::UNREADABLE, 'error' => 'This formula could not be read: '.$this->reason($e->getMessage())];
        }

        $this->put($this->sheet($sheetTabId), $row, $col, $result['value']);

        return $result;
    }

    private function reset(): void
    {
        $this->workbook?->disconnectWorksheets();

        $this->workbook = new Workbook;
        $this->workbook->removeSheetByIndex(0);
        // Each cell is calculated once and then holds its value: nothing here repeats.
        $this->workbook->getCalculationEngine()->setCalculationCacheEnabled(false);
        $this->sheets = [];
        $this->loaded = [];
        $this->held = 0;
    }

    private function sheet(int $tabId): Worksheet
    {
        return $this->sheets[$tabId] ??= $this->workbook->createSheet()->setTitle('S'.$tabId);
    }

    /**
     * Have this range in memory, starting a fresh workbook first if it would not fit. Stored results are
     * current by then: the Recalculator writes each one as it is worked out.
     *
     * @return int the cells the formula reads from it
     */
    private function ensureLoaded(int $tabId, int $r1, int $r2, int $c1, int $c2): int
    {
        foreach ($this->loaded[$tabId] ?? [] as [$a1, $a2, $b1, $b2]) {
            if ($r1 >= $a1 && $r2 <= $a2 && $c1 >= $b1 && $c2 <= $b2) {
                return 0;
            }
        }

        if ($this->held >= self::MAX_CELLS_HELD) {
            $this->reset();
        }

        return $this->load($tabId, $r1, $r2, $c1, $c2);
    }

    private function load(int $tabId, int $r1, int $r2, int $c1, int $c2): int
    {
        $sheet = $this->sheet($tabId);
        $count = 0;

        $cells = DB::table('cells')
            ->where('spreadsheet_id', $this->spreadsheetId)->where('sheet_tab_id', $tabId)
            ->whereBetween('row_index', [$r1, $r2])
            ->whereBetween('col_index', [$c1, $c2])
            ->select('row_index', 'col_index', 'raw_value', 'computed_value', 'formula')
            ->cursor();

        foreach ($cells as $cell) {
            $this->put($sheet, (int) $cell->row_index, (int) $cell->col_index, $cell->formula !== null ? $cell->computed_value : $cell->raw_value);
            $count++;
        }

        $this->loaded[$tabId][] = [$r1, $r2, $c1, $c2];
        $this->held += $count;

        return $count;
    }

    /**
     * The ranges without those another one contains, widest first: a column of running totals reads
     * one range, not a thousand overlapping ones.
     *
     * @param  list<array{0: int, 1: int, 2: int, 3: int, 4: int}>  $ranges
     * @return list<array{0: int, 1: int, 2: int, 3: int, 4: int}>
     */
    private function widest(array $ranges): array
    {
        $ranges = array_values(array_unique($ranges, SORT_REGULAR));
        usort($ranges, fn (array $a, array $b) => ($b[2] - $b[1] + 1) * ($b[4] - $b[3] + 1) <=> ($a[2] - $a[1] + 1) * ($a[4] - $a[3] + 1));

        $kept = [];
        foreach ($ranges as $range) {
            foreach ($kept as $wider) {
                if ($range[0] === $wider[0] && $range[1] >= $wider[1] && $range[2] <= $wider[2] && $range[3] >= $wider[3] && $range[4] <= $wider[4]) {
                    continue 2;
                }
            }
            $kept[] = $range;
        }

        return $kept;
    }

    /**
     * @return array<string, string> lower-case name => A1-style reference, for this sheet
     */
    protected function namedRanges(int $spreadsheetId, int $sheetTabId): array
    {
        return NamedRange::where('spreadsheet_id', $spreadsheetId)
            ->where('sheet_tab_id', $sheetTabId)
            ->get()
            ->mapWithKeys(fn (NamedRange $range) => [strtolower($range->name) => $range->toReferenceText()])
            ->all();
    }

    /**
     * A stored value, typed the way a spreadsheet reads what was typed: numbers as numbers (but
     * "007" stays text), TRUE and FALSE as logical values, error values as errors.
     */
    protected function put(Worksheet $sheet, int $row, int $col, ?string $value): void
    {
        $coordinate = FormulaText::columnLetters($col).($row + 1);

        if ($value === null || $value === '') {
            if ($sheet->cellExists($coordinate)) {
                $sheet->getCell($coordinate)->setValueExplicit(null, DataType::TYPE_NULL);
            }

            return;
        }

        $upper = strtoupper($value);

        if (in_array($upper, self::ERRORS, true) || $value === self::UNREADABLE) {
            $sheet->setCellValueExplicit($coordinate, in_array($upper, self::ERRORS, true) ? $upper : '#VALUE!', DataType::TYPE_ERROR);
        } elseif ($upper === 'TRUE' || $upper === 'FALSE') {
            $sheet->setCellValueExplicit($coordinate, $upper === 'TRUE', DataType::TYPE_BOOL);
        } elseif (preg_match('/^-?(0|[1-9]\d*)(\.\d+)?([eE][-+]?\d+)?$/', $value) === 1 || preg_match('/^-?0?\.\d+$/', $value) === 1) {
            $sheet->setCellValueExplicit($coordinate, (float) $value, DataType::TYPE_NUMERIC);
        } else {
            $sheet->setCellValueExplicit($coordinate, $value, DataType::TYPE_STRING);
        }
    }

    /**
     * @return array{value: string, error: ?string}
     */
    protected function result(mixed $value): array
    {
        while (is_array($value)) {
            $value = $value === [] ? null : reset($value);   // the top-left of an array result (spilling is S2c)
        }

        if (is_string($value) && in_array($value, self::ERRORS, true)) {
            return ['value' => $value, 'error' => self::EXPLANATIONS[$value]];
        }

        return ['value' => match (true) {
            $value === null => '0',
            is_bool($value) => $value ? 'TRUE' : 'FALSE',
            is_int($value) => (string) $value,
            is_float($value) => self::number($value),
            default => (string) $value,
        }, 'error' => null];
    }

    /**
     * A number as a spreadsheet shows it by default: 15 significant digits, so 0.1+0.2 is 0.3.
     */
    public static function number(float $value): string
    {
        if (! is_finite($value)) {
            return '#NUM!';
        }

        $text = sprintf('%.15G', $value);
        if (str_contains($text, 'E')) {
            [$mantissa, $exponent] = explode('E', $text);
            $mantissa = str_contains($mantissa, '.') ? rtrim(rtrim($mantissa, '0'), '.') : $mantissa;

            return $mantissa.'E'.($exponent[0] === '-' ? '-' : '+').ltrim(ltrim($exponent, '+-'), '0');
        }

        $text = str_contains($text, '.') ? rtrim(rtrim($text, '0'), '.') : $text;

        return $text === '-0' ? '0' : $text;
    }

    private function reason(string $message): string
    {
        $message = preg_replace('/^[^:]*![A-Z]+\d+ -> /', '', $message) ?? $message;

        return rtrim(mb_strimwidth($message, 0, 180, '…'), '.').'.';
    }
}
