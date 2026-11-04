<?php

namespace App\Services;

use App\Models\SheetTab;
use App\Models\Spreadsheet;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Turns a blueprint into a real workbook: sheets, headings, formulas, formats, allowed values, colour rules,
 * charts and names. Templates are blueprints, and so is what comes back from an idea. A blueprint holds
 * structure and never figures, so nothing in a new workbook pretends to be someone's business.
 */
class WorkbookBuilder
{
    public const MAX_SHEETS = 12;

    public const MAX_COLUMNS = 60;

    public const MAX_ROWS = 500;

    /** What a table's heading row looks like. */
    private const HEADING = ['bold' => true];

    public function __construct(private CellWriter $writer, private SheetCharts $charts, private SheetNames $names) {}

    /**
     * @param  array<string, mixed>  $blueprint
     */
    public function build(array $blueprint, string $name, User $user, ?int $teamId): Spreadsheet
    {
        return DB::transaction(function () use ($blueprint, $name, $user, $teamId) {
            $spreadsheet = new Spreadsheet([
                'uuid' => (string) Str::uuid(),
                'owner_id' => $user->id,
                'team_id' => $teamId,
                'name' => $name,
                'settings' => [],
            ]);
            // Quietly: a new workbook starts with an empty sheet, and this one brings its own.
            $spreadsheet->saveQuietly();

            // Every sheet exists before any formula is written, because they read each other.
            $tabs = [];
            foreach ($blueprint['sheets'] as $order => $sheet) {
                $tabs[$order] = SheetTab::create(['spreadsheet_id' => $spreadsheet->id, 'name' => $sheet['name'], 'sort_order' => $order, 'color' => $sheet['color'] ?? null]);
            }
            foreach ($blueprint['sheets'] as $order => $sheet) {
                $this->fill($spreadsheet, $tabs[$order], $sheet, $user->id);
            }

            return $spreadsheet;
        });
    }

    /**
     * @param  array<string, mixed>  $sheet
     */
    private function fill(Spreadsheet $spreadsheet, SheetTab $tab, array $sheet, int $userId): void
    {
        $edits = [];
        $widths = [];
        $allowed = [];
        foreach ($sheet['cells'] ?? [] as $reference => $cell) {
            $area = RangeText::parse($reference);
            $edits[] = ['row' => $area['r1'], 'col' => $area['c1'], 'value' => (string) (is_array($cell) ? $cell['v'] : $cell), 'x' => is_array($cell) ? ($cell['x'] ?? null) : null];
        }

        if (isset($sheet['table'])) {
            ['rows' => $rows, 'columns' => $columns] = $sheet['table'];
            $top = (int) ($sheet['table']['at'] ?? 0);
            foreach (array_values($columns) as $col => $column) {
                $edits[] = ['row' => $top, 'col' => $col, 'value' => $column['heading'], 'x' => self::HEADING];
                if (isset($column['width'])) {
                    $widths[$col] = $column['width'];
                }
                if (isset($column['allowed'])) {
                    $allowed[] = $this->allowedRule($top + 1, $top + $rows, $col, $column['allowed']);
                }
                if (! isset($column['formula']) && ! isset($column['format'])) {
                    continue;
                }
                for ($row = $top + 1; $row <= $top + $rows; $row++) {
                    $edits[] = ['row' => $row, 'col' => $col, 'value' => str_replace('{r}', (string) ($row + 1), $column['formula'] ?? ''), 'x' => $column['format'] ?? null];
                }
            }
        }

        $this->writer->write($spreadsheet->id, $tab->id, array_map(fn (array $edit) => array_filter($edit, fn ($value) => $value !== null), $edits), $userId);

        foreach ($sheet['widths'] ?? [] as $letters => $width) {
            $widths[RangeText::parse($letters.'1')['c1']] = $width;
        }
        $rules = array_map(fn (array $rule) => [
            'id' => (string) Str::uuid(),
            'target' => 'column',
            'row' => (int) ($sheet['table']['at'] ?? 0) + 1,
            'col' => RangeText::parse($rule['column'].'1')['c1'],
            'operator' => $rule['operator'],
            'value' => $rule['value'],
            'color' => $rule['color'],
        ], $sheet['rules'] ?? []);

        SheetTab::updateLayout($tab->id, fn (array $layout) => array_filter([
            ...$layout,
            'col_widths' => $widths,
            'frozen_rows' => $sheet['frozen_rows'] ?? 0,
            'frozen_cols' => $sheet['frozen_cols'] ?? 0,
            'validation_rules' => $allowed,
            'conditional_formatting_rules' => $rules,
        ]));

        foreach ($sheet['charts'] ?? [] as $chart) {
            $this->charts->add($spreadsheet->id, $tab->id, $chart['title'], $chart['type'], $chart['data'], $chart['labels'] ?? null);
        }
        foreach ($sheet['names'] ?? [] as $name => $range) {
            $this->names->add($spreadsheet->id, $tab->id, $name, $range);
        }
    }

    /**
     * @param  array<int|string, mixed>  $allowed  a list of choices, or a lowest and a highest
     * @return array<string, mixed>
     */
    private function allowedRule(int $from, int $to, int $col, array $allowed): array
    {
        $place = ['id' => (string) Str::uuid(), 'row' => $from, 'col' => $col, 'row2' => $to, 'col2' => $col];

        return array_is_list($allowed)
            ? [...$place, 'type' => 'list', 'items' => implode(', ', $allowed)]
            : [...$place, 'type' => 'number', ...array_filter($allowed, fn ($value) => $value !== null)];
    }
}
