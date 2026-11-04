<?php

namespace App\Services;

use App\Models\Cell;
use App\Models\NamedRange;
use App\Models\PivotTable;
use Illuminate\Support\Facades\DB;

/**
 * Pivot tables of one sheet, in both editors. A pivot stores only what to count, never the answer, so it
 * always reflects the sheet.
 *
 * Totals are worked out from the figures underneath, never from the cells above them: the average of two
 * averages is not an average, and a sum of lowest figures is nothing at all. A combination with no figures
 * is empty, not nought. The first row of the source holds the headings.
 */
class PivotTableService
{
    /** Rows and columns shown. More are counted and stay in the totals. */
    public const MAX_ROWS = 500;

    public const MAX_COLUMNS = 50;

    /** Cells read at once. Past this the sheet is too large to summarise in one request. */
    public const MAX_CELLS = 100000;

    /** How many of a figure's rows are listed, and how wide. */
    private const MAX_RECORDS = 200;

    private const MAX_RECORD_COLUMNS = 12;

    public const BLANK = '(blank)';

    /** The label of the one column of a pivot with nothing across it. */
    public const ONE_COLUMN = 'Value';

    /**
     * @return array{
     *   rowLabels: list<string>,
     *   columnLabels: list<string>,
     *   matrix: array<string, array<string, float|null>>,
     *   rowTotals: array<string, float|null>,
     *   columnTotals: array<string, float|null>,
     *   grandTotal: float|null,
     *   more_rows: int,
     *   more_columns: int,
     *   words: int,
     *   gone: bool,
     *   too_large: bool,
     * }
     */
    public function compute(PivotTable $pivot): array
    {
        $area = RangeText::parse($pivot->source_range);
        $rowField = $this->column($pivot->row_field);
        $valueField = $this->column($pivot->value_field);
        $columnField = $pivot->column_field === null ? null : $this->column($pivot->column_field);
        $filterField = $pivot->filter_field === null ? null : $this->column($pivot->filter_field);

        if ($area === null || $rowField === null || $valueField === null || ($pivot->column_field !== null && $columnField === null)) {
            return $this->emptyResult(gone: true);
        }
        foreach (array_filter([$rowField, $valueField, $columnField], fn (?int $col) => $col !== null) as $col) {
            if ($col < $area['c1'] || $col > $area['c2']) {
                return $this->emptyResult(gone: true);
            }
        }

        $inSource = Cell::where('sheet_tab_id', $pivot->sheet_tab_id)
            ->whereBetween('row_index', [$area['r1'] + 1, $area['r2']])
            ->whereIn('col_index', array_values(array_unique(array_filter([$rowField, $valueField, $columnField, $filterField], fn (?int $col) => $col !== null))));

        if ((clone $inSource)->count() > self::MAX_CELLS) {
            return $this->emptyResult(tooLarge: true);
        }

        $rows = [];
        foreach ($inSource->get(['row_index', 'col_index', 'raw_value', 'computed_value']) as $cell) {
            $rows[$cell->row_index][$cell->col_index] = (string) ($cell->computed_value ?? $cell->raw_value);
        }

        // [row label][column label] => how many cells hold anything, and which of them are figures
        $groups = [];
        $seenColumns = [];
        $words = 0;
        foreach ($rows as $cells) {
            if ($filterField !== null && mb_strtolower(trim($cells[$filterField] ?? '')) !== mb_strtolower(trim((string) $pivot->filter_value))) {
                continue;
            }
            $rowText = trim($cells[$rowField] ?? '');
            $columnText = $columnField === null ? self::ONE_COLUMN : trim($cells[$columnField] ?? '');
            $value = $cells[$valueField] ?? '';

            // A row with nothing in the columns this pivot reads is not data.
            if ($rowText === '' && $value === '' && ($columnField === null || $columnText === '')) {
                continue;
            }

            $rowLabel = $rowText === '' ? self::BLANK : $rowText;
            $columnLabel = $columnText === '' ? self::BLANK : $columnText;
            $seenColumns[$columnLabel] = true;

            $group = $groups[$rowLabel][$columnLabel] ?? ['held' => 0, 'figures' => []];
            if ($value !== '') {
                $group['held']++;
                if (is_numeric($value)) {
                    $group['figures'][] = (float) $value;
                } else {
                    $words++;
                }
            }
            $groups[$rowLabel][$columnLabel] = $group;
        }

        $rowLabels = $this->sorted(array_keys($groups));
        $columnLabels = $this->sorted(array_keys($seenColumns));
        $shownRows = array_slice($rowLabels, 0, self::MAX_ROWS);
        $shownColumns = array_slice($columnLabels, 0, self::MAX_COLUMNS);

        $matrix = [];
        $rowTotals = [];
        foreach ($shownRows as $row) {
            foreach ($shownColumns as $column) {
                $matrix[$row][$column] = $this->answer([$groups[$row][$column] ?? null], $pivot->aggregation);
            }
            $rowTotals[$row] = $this->answer(array_values($groups[$row]), $pivot->aggregation);
        }

        $columnTotals = [];
        foreach ($shownColumns as $column) {
            $columnTotals[$column] = $this->answer(array_map(fn (array $row) => $row[$column] ?? null, $groups), $pivot->aggregation);
        }

        return [
            'rowLabels' => $shownRows,
            'columnLabels' => $shownColumns,
            'matrix' => $matrix,
            'rowTotals' => $rowTotals,
            'columnTotals' => $columnTotals,
            'grandTotal' => $this->answer(array_merge(...[[], ...array_map('array_values', array_values($groups))]), $pivot->aggregation),
            'more_rows' => count($rowLabels) - count($shownRows),
            'more_columns' => count($columnLabels) - count($shownColumns),
            // Words cannot be added up, though they can be counted.
            'words' => $pivot->aggregation === 'count' ? 0 : $words,
            'gone' => false,
            'too_large' => false,
        ];
    }

    /**
     * The rows of the table behind one figure of a pivot.
     *
     * @return array{headings: list<string>, rows: list<list<string>>, more: int}
     */
    public function records(PivotTable $pivot, ?string $rowLabel, ?string $columnLabel): array
    {
        $area = RangeText::parse($pivot->source_range);
        $rowField = $this->column($pivot->row_field);
        $columnField = $pivot->column_field === null ? null : $this->column($pivot->column_field);
        $filterField = $pivot->filter_field === null ? null : $this->column($pivot->filter_field);
        if ($area === null || $rowField === null) {
            return ['headings' => [], 'rows' => [], 'more' => 0];
        }

        $grid = [];
        foreach (Cell::where('sheet_tab_id', $pivot->sheet_tab_id)->whereBetween('row_index', [$area['r1'], $area['r2']])->whereBetween('col_index', [$area['c1'], $area['c2']])
            ->get(['row_index', 'col_index', 'raw_value', 'computed_value']) as $cell) {
            $grid[$cell->row_index][$cell->col_index] = (string) ($cell->computed_value ?? $cell->raw_value);
        }
        ksort($grid);

        $columns = range($area['c1'], min($area['c2'], $area['c1'] + self::MAX_RECORD_COLUMNS - 1));
        $headings = array_map(fn (int $col) => trim($grid[$area['r1']][$col] ?? ''), $columns);
        unset($grid[$area['r1']]);

        $same = fn (string $text, ?string $label) => $label === null || (trim($text) === '' ? self::BLANK : trim($text)) === $label;
        $found = array_filter($grid, fn (array $cells) => $same($cells[$rowField] ?? '', $rowLabel)
            && ($columnField === null || $same($cells[$columnField] ?? '', $columnLabel))
            && ($filterField === null || mb_strtolower(trim($cells[$filterField] ?? '')) === mb_strtolower(trim((string) $pivot->filter_value))));

        return [
            'headings' => $headings,
            'rows' => array_map(fn (array $cells) => array_map(fn (int $col) => $cells[$col] ?? '', $columns), array_slice($found, 0, self::MAX_RECORDS)),
            'more' => max(0, count($found) - self::MAX_RECORDS),
        ];
    }

    /**
     * The columns of a table, named by its first row, and what each mostly holds.
     *
     * @return list<array{col: string, name: string, figures: bool}>
     */
    public function fields(int $tabId, string $range): array
    {
        $area = RangeText::parse($range);
        if ($area === null || $area['r1'] === $area['r2'] || $area['c2'] - $area['c1'] >= self::MAX_COLUMNS) {
            return [];
        }

        $headings = Cell::where('sheet_tab_id', $tabId)->where('row_index', $area['r1'])->whereBetween('col_index', [$area['c1'], $area['c2']])
            ->get(['col_index', 'raw_value', 'computed_value'])
            ->mapWithKeys(fn (Cell $cell) => [$cell->col_index => trim((string) ($cell->computed_value ?? $cell->raw_value))]);

        $figures = [];
        $held = [];
        $sample = Cell::where('sheet_tab_id', $tabId)->whereBetween('row_index', [$area['r1'] + 1, min($area['r2'], $area['r1'] + 200)])->whereBetween('col_index', [$area['c1'], $area['c2']])
            ->get(['col_index', 'raw_value', 'computed_value']);
        foreach ($sample as $cell) {
            $value = (string) ($cell->computed_value ?? $cell->raw_value);
            if ($value !== '') {
                $held[$cell->col_index] = ($held[$cell->col_index] ?? 0) + 1;
                $figures[$cell->col_index] = ($figures[$cell->col_index] ?? 0) + (is_numeric($value) ? 1 : 0);
            }
        }

        $fields = [];
        for ($col = $area['c1']; $col <= $area['c2']; $col++) {
            $letter = NamedRange::colIndexToLetter($col);
            $heading = $headings[$col] ?? '';
            $fields[] = ['col' => $letter, 'name' => $heading === '' ? "Column {$letter}" : $heading, 'figures' => ($figures[$col] ?? 0) * 2 > ($held[$col] ?? 0)];
        }

        return $fields;
    }

    /**
     * @return list<array{id: int, title: string, source_range: string, row_field: string, column_field: string|null, value_field: string, aggregation: string}>
     */
    public function of(int $spreadsheetId, int $tabId): array
    {
        return PivotTable::where('spreadsheet_id', $spreadsheetId)->where('sheet_tab_id', $tabId)->latest()->orderByDesc('id')
            ->get(['id', 'title', 'source_range', 'row_field', 'column_field', 'value_field', 'aggregation'])
            ->map(fn (PivotTable $pivot) => $pivot->only(['id', 'title', 'source_range', 'row_field', 'column_field', 'value_field', 'aggregation']))
            ->all();
    }

    /**
     * @throws PivotRefused
     */
    public function add(int $spreadsheetId, int $tabId, string $title, string $source, string $rowField, ?string $columnField, string $valueField, string $aggregation, ?string $filterField = null, ?string $filterValue = null): PivotTable
    {
        $area = RangeText::parse($source);
        if ($area === null) {
            throw new PivotRefused('The table must look like A1:D20.');
        }
        if ($area['r1'] === $area['r2']) {
            throw new PivotRefused('Choose a table with headings and at least one row under them.');
        }

        $filterField = $filterField === null || $filterField === '' ? null : $filterField;
        $fields = [$rowField, $valueField, ...($columnField === null || $columnField === '' ? [] : [$columnField]), ...($filterField === null ? [] : [$filterField])];
        foreach ($fields as $field) {
            $col = $this->column($field);
            if ($col === null || $col < $area['c1'] || $col > $area['c2']) {
                throw new PivotRefused('Choose columns inside the table.');
            }
        }

        return PivotTable::create([
            'spreadsheet_id' => $spreadsheetId,
            'sheet_tab_id' => $tabId,
            'title' => trim($title),
            'source_range' => RangeText::of($area),
            'row_field' => strtoupper($rowField),
            'column_field' => $columnField === null || $columnField === '' ? null : strtoupper($columnField),
            'value_field' => strtoupper($valueField),
            'aggregation' => $aggregation,
            'filter_field' => $filterField === null ? null : strtoupper($filterField),
            'filter_value' => $filterField === null ? null : (string) $filterValue,
        ]);
    }

    /**
     * Where a sheet's pivots read, for a copy of the sheet.
     *
     * @return list<array{0: int, 1: string, 2: string, 3: string|null, 4: string, 5: string|null}> id, source, row, column, value, filter
     */
    public function sourcesOf(int $tabId): array
    {
        return DB::table('pivot_tables')->where('sheet_tab_id', $tabId)->orderBy('id')->get(['id', 'source_range', 'row_field', 'column_field', 'value_field', 'filter_field'])
            ->map(fn ($pivot) => [(int) $pivot->id, (string) $pivot->source_range, (string) $pivot->row_field, $pivot->column_field, (string) $pivot->value_field, $pivot->filter_field])->all();
    }

    /**
     * Put a copy's pivots back on their cells. Pivots made since the copy stay as they are.
     *
     * @param  list<array<int, string|int|null>>  $sources
     */
    public function restoreSources(int $tabId, array $sources): void
    {
        foreach ($sources as $source) {
            // A copy from before filters has only five parts.
            DB::table('pivot_tables')->where('id', $source[0])->where('sheet_tab_id', $tabId)
                ->update(['source_range' => $source[1], 'row_field' => $source[2], 'column_field' => $source[3], 'value_field' => $source[4], ...(array_key_exists(5, $source) ? ['filter_field' => $source[5]] : [])]);
        }
    }

    /**
     * One answer from whole groups of cells.
     *
     * @param  array<int|string, array{held: int, figures: list<float>}|null>  $groups
     */
    private function answer(array $groups, string $aggregation): ?float
    {
        $held = 0;
        $figures = [];
        foreach ($groups as $group) {
            if ($group !== null) {
                $held += $group['held'];
                array_push($figures, ...$group['figures']);
            }
        }

        if ($aggregation === 'count') {
            return $held === 0 ? null : (float) $held;
        }
        if ($figures === []) {
            return null;
        }

        return match ($aggregation) {
            'average' => array_sum($figures) / count($figures),
            'min' => min($figures),
            'max' => max($figures),
            default => array_sum($figures),
        };
    }

    /**
     * Numbers by size and words in natural order, with blanks last.
     *
     * @param  list<int|string>  $labels
     * @return list<string>
     */
    private function sorted(array $labels): array
    {
        $labels = array_map('strval', $labels);
        usort($labels, function (string $left, string $right) {
            if ($left === self::BLANK || $right === self::BLANK) {
                return ($left === self::BLANK) <=> ($right === self::BLANK);
            }
            if (is_numeric($left) && is_numeric($right)) {
                return (float) $left <=> (float) $right;
            }

            return strnatcasecmp($left, $right);
        });

        return $labels;
    }

    private function column(string $letters): ?int
    {
        $area = preg_match('/^[A-Za-z]{1,3}$/', $letters) === 1 ? RangeText::parse($letters.'1') : null;

        return $area['c1'] ?? null;
    }

    /**
     * @return array{rowLabels: list<string>, columnLabels: list<string>, matrix: array<string, array<string, float|null>>, rowTotals: array<string, float|null>, columnTotals: array<string, float|null>, grandTotal: float|null, more_rows: int, more_columns: int, words: int, gone: bool, too_large: bool}
     */
    private function emptyResult(bool $gone = false, bool $tooLarge = false): array
    {
        return [
            'rowLabels' => [],
            'columnLabels' => [],
            'matrix' => [],
            'rowTotals' => [],
            'columnTotals' => [],
            'grandTotal' => null,
            'more_rows' => 0,
            'more_columns' => 0,
            'words' => 0,
            'gone' => $gone,
            'too_large' => $tooLarge,
        ];
    }
}
