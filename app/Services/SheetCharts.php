<?php

namespace App\Services;

use App\Models\Cell;
use App\Models\ChartConfig;
use App\Models\NamedRange;
use Illuminate\Support\Facades\DB;

/**
 * Charts of one sheet, in both editors. A chart never invents a figure: an empty or text cell is a gap,
 * not a nought. A heading row names the series and a first column of words labels the points, and a table
 * wider than it is tall runs across, so a plain selection of a table charts properly. The ranges follow their
 * cells as rows and columns move.
 */
class SheetCharts
{
    public const TYPES = ['bar', 'hbar', 'line', 'area', 'combo', 'pie', 'doughnut', 'scatter', 'histogram', 'waterfall'];

    /** Points in one chart. More than this cannot be read at this size. */
    public const MAX_POINTS = 500;

    /** Series in one chart: as many as the colours that stay apart. See DESIGN.md. */
    public const MAX_SERIES = 6;

    public const GONE = SheetStructureService::REF_ERROR;

    /**
     * @return list<array{id: int, title: string, type: string, data_range: string, labels_range: string|null}>
     */
    public function of(int $spreadsheetId, int $tabId): array
    {
        return ChartConfig::where('spreadsheet_id', $spreadsheetId)->where('sheet_tab_id', $tabId)->latest()->orderByDesc('id')
            ->get(['id', 'title', 'type', 'data_range', 'labels_range', 'options', 'position_row', 'position_col', 'width', 'height'])
            ->map(fn (ChartConfig $chart) => [
                'id' => $chart->id,
                'title' => $chart->title,
                'type' => $chart->type,
                'data_range' => $chart->data_range,
                'on_sheet' => (bool) ($chart->options['on_sheet'] ?? false),
                'row' => (int) $chart->position_row,
                'col' => (int) $chart->position_col,
                'cols' => (int) $chart->width,
                'rows' => (int) $chart->height,
                'labels_range' => $chart->labels_range,
            ])->all();
    }

    /**
     * @throws ChartRefused
     */
    public function add(int $spreadsheetId, int $tabId, string $title, string $type, string $dataRange, ?string $labelsRange): ChartConfig
    {
        $area = RangeText::parse($dataRange);
        if ($area === null) {
            throw new ChartRefused('Figures must look like B2:D8.');
        }
        if ($area['r1'] === $area['r2'] && $area['c1'] === $area['c2']) {
            throw new ChartRefused('Choose more than one cell to chart.');
        }

        $labels = null;
        if ($labelsRange !== null && trim($labelsRange) !== '') {
            $labels = RangeText::parse($labelsRange);
            if ($labels === null) {
                throw new ChartRefused('Labels must look like A2:A8.');
            }
            if ($labels['c1'] !== $labels['c2']) {
                throw new ChartRefused('Labels come from one column.');
            }
        }

        return ChartConfig::create([
            'spreadsheet_id' => $spreadsheetId,
            'sheet_tab_id' => $tabId,
            'title' => trim($title),
            'type' => $type,
            'data_range' => RangeText::of($area),
            'labels_range' => $labels === null ? null : RangeText::of($labels),
            'options' => [],
        ]);
    }

    /**
     * Put a chart on the sheet, take it off, or move and size it there.
     *
     * @param  array{on_sheet: bool, row?: int, col?: int, cols?: int, rows?: int}  $place
     */
    public function place(ChartConfig $chart, array $place): void
    {
        $chart->options = [...(is_array($chart->options) ? $chart->options : []), 'on_sheet' => $place['on_sheet']];
        foreach (['row' => 'position_row', 'col' => 'position_col', 'cols' => 'width', 'rows' => 'height'] as $given => $column) {
            if (isset($place[$given])) {
                $chart->{$column} = $place[$given];
            }
        }
        $chart->save();
    }

    /**
     * What a chart shows.
     *
     * @return array{labels: list<string>, series: list<array{name: string, values: list<float|null>}>, more_points: int, more_series: int, gone: bool}
     */
    public function read(int $tabId, string $dataRange, ?string $labelsRange): array
    {
        $area = RangeText::parse($dataRange);
        if ($area === null) {
            return ['labels' => [], 'series' => [], 'more_points' => 0, 'more_series' => 0, 'gone' => true];
        }

        // A whole column is a million rows on paper and however many hold data in fact.
        $inArea = fn () => Cell::where('sheet_tab_id', $tabId)->whereBetween('row_index', [$area['r1'], $area['r2']])->whereBetween('col_index', [$area['c1'], $area['c2']]);
        $lastRow = (int) ($inArea()->max('row_index') ?? $area['r1']);
        $lastShown = min($lastRow, $area['r1'] + self::MAX_POINTS);

        $grid = [];
        foreach ($inArea()->where('row_index', '<=', $lastShown)->get(['row_index', 'col_index', 'raw_value', 'computed_value']) as $cell) {
            $grid[$cell->row_index][$cell->col_index] = (string) ($cell->computed_value ?? $cell->raw_value);
        }

        $columns = range($area['c1'], min($area['c2'], $area['c1'] + 99));
        $hasLabels = $labelsRange === null && count($columns) > 1 && $this->holdsWords($grid, $area['c1']);
        $figures = $hasLabels ? array_slice($columns, 1) : $columns;
        $firstRow = $this->readsAsHeading($grid, $area['r1'], $lastShown, $figures) ? $area['r1'] + 1 : $area['r1'];
        $rows = $lastShown >= $firstRow ? range($firstRow, min($lastShown, $firstRow + self::MAX_POINTS - 1)) : [];

        // A table wider than it is tall runs across, month by month: its rows are the series. Labels set
        // apart say the table runs down, and are left as they are.
        if ($labelsRange === null && count($figures) > count($rows)) {
            return $this->across($tabId, $grid, $rows, $figures, $hasLabels ? $area['c1'] : null, $firstRow > $area['r1'] ? $area['r1'] : null, max(0, $lastRow - $lastShown));
        }

        $series = [];
        foreach (array_slice($figures, 0, self::MAX_SERIES) as $col) {
            $values = array_map(fn (int $row) => $this->figure($grid[$row][$col] ?? ''), $rows);
            $heading = $firstRow > $area['r1'] ? trim($grid[$area['r1']][$col] ?? '') : '';
            $series[] = ['name' => $heading !== '' ? $heading : NamedRange::colIndexToLetter($col), 'values' => $values];
        }

        return [
            'labels' => $this->labels($tabId, $labelsRange, $grid, $hasLabels ? $area['c1'] : null, $rows, $area['r1']),
            'series' => $series,
            'more_points' => max(0, $lastRow - ($rows === [] ? $lastRow : end($rows))),
            'more_series' => max(0, count($figures) - self::MAX_SERIES),
            'gone' => false,
        ];
    }

    /**
     * Rows as series, with the figures running across them.
     *
     * @param  array<int, array<int, string>>  $grid
     * @param  list<int>  $rows
     * @param  list<int>  $columns
     * @return array{labels: list<string>, series: list<array{name: string, values: list<float|null>}>, more_points: int, more_series: int, gone: bool}
     */
    private function across(int $tabId, array $grid, array $rows, array $columns, ?int $nameColumn, ?int $headingRow, int $moreRows): array
    {
        $shown = array_slice($columns, 0, self::MAX_POINTS);

        $series = [];
        foreach (array_slice($rows, 0, self::MAX_SERIES) as $row) {
            $name = $nameColumn === null ? '' : trim($grid[$row][$nameColumn] ?? '');
            $series[] = ['name' => $name !== '' ? $name : 'Row '.($row + 1), 'values' => array_map(fn (int $col) => $this->figure($grid[$row][$col] ?? ''), $shown)];
        }

        return [
            'labels' => $this->acrossLabels($tabId, $grid, $shown, $headingRow),
            'series' => $series,
            'more_points' => count($columns) - count($shown),
            'more_series' => max(0, count($rows) - self::MAX_SERIES) + $moreRows,
            'gone' => false,
        ];
    }

    /**
     * What each column is called: its heading in the selection, or else the words at the top of the sheet,
     * which is where a table that runs across keeps its months.
     *
     * @param  array<int, array<int, string>>  $grid
     * @param  list<int>  $columns
     * @return list<string>
     */
    private function acrossLabels(int $tabId, array $grid, array $columns, ?int $headingRow): array
    {
        $top = $headingRow === null
            ? Cell::where('sheet_tab_id', $tabId)->where('row_index', 0)->whereIn('col_index', $columns)->get(['col_index', 'raw_value', 'computed_value'])
                ->mapWithKeys(fn (Cell $cell) => [$cell->col_index => (string) ($cell->computed_value ?? $cell->raw_value)])->all()
            : [];

        return array_map(function (int $col) use ($grid, $headingRow, $top) {
            $word = $headingRow === null ? ($top[$col] ?? '') : ($grid[$headingRow][$col] ?? '');

            return trim($word) !== '' && ! is_numeric($word) ? trim($word) : NamedRange::colIndexToLetter($col);
        }, $columns);
    }

    /**
     * Where a sheet's charts read, for a copy of the sheet.
     *
     * @return list<array{0: int, 1: string, 2: string|null}> id, figures, labels
     */
    public function rangesOf(int $tabId): array
    {
        return DB::table('chart_configs')->where('sheet_tab_id', $tabId)->orderBy('id')->get(['id', 'data_range', 'labels_range'])
            ->map(fn ($chart) => [(int) $chart->id, (string) $chart->data_range, $chart->labels_range])->all();
    }

    /**
     * Put a copy's charts back on their cells. Charts made since the copy stay as they are.
     *
     * @param  list<array{0: int, 1: string, 2: string|null}>  $ranges
     */
    public function restoreRanges(int $tabId, array $ranges): void
    {
        foreach ($ranges as [$id, $data, $labels]) {
            DB::table('chart_configs')->where('id', $id)->where('sheet_tab_id', $tabId)->update(['data_range' => $data, 'labels_range' => $labels]);
        }
    }

    /**
     * @param  array<int, array<int, string>>  $grid
     * @param  list<int>  $rows
     * @return list<string>
     */
    private function labels(int $tabId, ?string $labelsRange, array $grid, ?int $column, array $rows, int $firstRow): array
    {
        $labels = $labelsRange === null ? null : RangeText::parse($labelsRange);
        if ($labels !== null) {
            // Row for row with the figures, so a blank label leaves a gap and moves nothing along.
            $text = Cell::where('sheet_tab_id', $tabId)->where('col_index', $labels['c1'])
                ->whereBetween('row_index', [$labels['r1'], $labels['r2']])->get(['row_index', 'raw_value', 'computed_value'])
                ->mapWithKeys(fn (Cell $cell) => [$cell->row_index => (string) ($cell->computed_value ?? $cell->raw_value)]);

            return array_map(fn (int $row) => $text[$labels['r1'] + ($row - $firstRow)] ?? '', $rows);
        }

        return array_map(fn (int $row) => $column === null ? 'Row '.($row + 1) : ($grid[$row][$column] ?? ''), $rows);
    }

    private function figure(string $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * @param  array<int, array<int, string>>  $grid
     */
    private function holdsWords(array $grid, int $column): bool
    {
        $values = array_filter(array_map(fn (array $row) => $row[$column] ?? '', $grid), fn (string $value) => $value !== '');

        return $values !== [] && array_filter($values, 'is_numeric') === [];
    }

    /**
     * A first row with no figures in it, over rows that have them.
     *
     * @param  array<int, array<int, string>>  $grid
     * @param  list<int>  $columns
     */
    private function readsAsHeading(array $grid, int $first, int $last, array $columns): bool
    {
        $figuresIn = function (int $row) use ($grid, $columns) {
            foreach ($columns as $col) {
                if (is_numeric($grid[$row][$col] ?? '')) {
                    return true;
                }
            }

            return false;
        };

        if ($figuresIn($first)) {
            return false;
        }
        for ($row = $first + 1; $row <= $last; $row++) {
            if ($figuresIn($row)) {
                return true;
            }
        }

        return false;
    }
}
