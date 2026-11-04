<?php

namespace App\Services;

use App\Models\Cell;
use App\Models\NamedRange;
use App\Models\SheetTab;
use App\Models\Spreadsheet;
use App\Services\Formula\FormulaFromWords;
use App\Services\Formula\Recalculator;

/**
 * A new sheet that sums up a table: key figures across the top, a summary by the table's first useful
 * column of words, and a chart of it. Everything on it is a formula reading the table, so it stays right.
 */
class DashboardBuilder
{
    private const MOST_FIGURES = 3;

    private const MOST_GROUPS = 12;

    public function __construct(private FormulaFromWords $words, private WorkbookSheets $sheets, private CellWriter $writer, private SheetCharts $charts, private Recalculator $recalculator) {}

    /**
     * @throws DashboardRefused
     */
    public function build(Spreadsheet $spreadsheet, SheetTab $source, int $userId): SheetTab
    {
        $this->recalculator->ensureBuilt($spreadsheet->id, $source->id);

        $table = $this->words->tableOn($source->id);
        if ($table === null) {
            throw new DashboardRefused('This sheet has no table to sum up. Put headings in the top row of one first.');
        }

        $values = fn (int $col) => Cell::where('sheet_tab_id', $source->id)->where('col_index', $col)->whereBetween('row_index', [$table['top'] + 1, $table['last']])
            ->get(['computed_value', 'raw_value'])->map(fn (Cell $cell) => trim((string) ($cell->computed_value ?? $cell->raw_value)))->filter(fn (string $value) => $value !== '')->values();

        $figures = [];
        $groups = [];
        foreach ($table['columns'] as $column) {
            $held = $values($column['col']);
            if ($held->isEmpty()) {
                continue;
            }
            if ($held->filter(fn (string $value) => is_numeric($value))->count() * 2 > $held->count()) {
                $figures[] = $column;
            } elseif ($held->unique()->count() >= 2 && $held->unique()->count() <= self::MOST_GROUPS) {
                $groups[] = ['column' => $column, 'keys' => $held->unique()->values()->all()];
            }
        }
        if ($figures === []) {
            throw new DashboardRefused('This table has no column of figures to sum up.');
        }

        $reference = $this->reference((string) $source->name);
        $range = fn (int $col) => $reference.'!$'.NamedRange::colIndexToLetter($col).'$'.($table['top'] + 2).':$'.NamedRange::colIndexToLetter($col).'$'.($table['last'] + 1);

        $edits = [
            ['row' => 0, 'col' => 0, 'value' => "{$source->name} at a glance", 'x' => ['bold' => true, 'font_size' => 20]],
            ['row' => 3, 'col' => 0, 'value' => 'Rows', 'x' => ['bold' => true]],
            ['row' => 4, 'col' => 0, 'value' => '=COUNTA('.$range($table['columns'][0]['col']).')', 'x' => ['font_size' => 20]],
        ];
        foreach (array_slice($figures, 0, self::MOST_FIGURES) as $i => $figure) {
            $edits[] = ['row' => 3, 'col' => $i + 1, 'value' => "Total {$figure['heading']}", 'x' => ['bold' => true]];
            $edits[] = ['row' => 4, 'col' => $i + 1, 'value' => '=SUM('.$range($figure['col']).')', 'x' => ['font_size' => 20, 'number_format' => 'number', 'decimals' => 0]];
        }

        $group = $groups[0] ?? null;
        $note = "Built from {$source->name}. The figures follow that sheet.";
        if ($group !== null) {
            $note .= " A new {$group['column']['heading']} needs a new line.";
            $measure = $figures[0];
            $top = 6;
            $edits[] = ['row' => $top, 'col' => 0, 'value' => $group['column']['heading'], 'x' => ['bold' => true]];
            $edits[] = ['row' => $top, 'col' => 1, 'value' => $measure['heading'], 'x' => ['bold' => true]];
            $edits[] = ['row' => $top, 'col' => 2, 'value' => 'Share', 'x' => ['bold' => true]];
            $count = count($group['keys']);
            foreach ($group['keys'] as $i => $key) {
                $row = $top + 1 + $i;
                $edits[] = ['row' => $row, 'col' => 0, 'value' => $key];
                $edits[] = ['row' => $row, 'col' => 1, 'value' => '=SUMIFS('.$range($measure['col']).','.$range($group['column']['col']).',$A'.($row + 1).')', 'x' => ['number_format' => 'number', 'decimals' => 0]];
                $edits[] = ['row' => $row, 'col' => 2, 'value' => '=IF($B$5=0,"",B'.($row + 1).'/$B$5)', 'x' => ['number_format' => 'percent', 'decimals' => 0]];
            }
            $edits[] = ['row' => 1, 'col' => 0, 'value' => $note];

            $tab = $this->sheets->add($spreadsheet, $this->freeName($spreadsheet->id));
            $this->writer->write($spreadsheet->id, $tab->id, $edits, $userId);
            $this->place($spreadsheet, $tab, "{$measure['heading']} by {$group['column']['heading']}", 'B'.($top + 2).':B'.($top + 1 + $count), 'A'.($top + 2).':A'.($top + 1 + $count), $top);
        } else {
            $edits[] = ['row' => 1, 'col' => 0, 'value' => $note];
            $tab = $this->sheets->add($spreadsheet, $this->freeName($spreadsheet->id));
            $this->writer->write($spreadsheet->id, $tab->id, $edits, $userId);
        }

        SheetTab::updateLayout($tab->id, fn (array $layout) => [...$layout, 'col_widths' => [0 => 200, 1 => 150, 2 => 150, 3 => 150]]);
        $tab->update(['color' => 'teal']);

        return $tab;
    }

    private function place(Spreadsheet $spreadsheet, SheetTab $tab, string $title, string $data, string $labels, int $row): void
    {
        $chart = $this->charts->add($spreadsheet->id, $tab->id, $title, 'bar', $data, $labels);
        $this->charts->place($chart, ['on_sheet' => true, 'row' => $row, 'col' => 4, 'cols' => 8, 'rows' => 14]);
    }

    private function reference(string $name): string
    {
        return preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) === 1 ? $name : "'".str_replace("'", "''", $name)."'";
    }

    private function freeName(int $spreadsheetId): string
    {
        $name = 'Dashboard';
        for ($count = 2; $this->sheets->refusal($spreadsheetId, $name) !== null && $count < 200; $count++) {
            $name = "Dashboard {$count}";
        }

        return $name;
    }
}
