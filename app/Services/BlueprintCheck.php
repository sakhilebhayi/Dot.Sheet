<?php

namespace App\Services;

use App\Services\Formula\FunctionCatalogue;

/**
 * A blueprint that came from AI is checked as anything from outside is. What is wrong is dropped, a formula
 * may use only the functions this product documents, and typed numbers go, because a blueprint holds
 * structure: whatever the model said, a new workbook must not carry figures that look like someone's own.
 */
class BlueprintCheck
{
    private const OPERATORS = ['>', '>=', '<', '<=', '==', '!=', 'contains'];

    private const CHART_TYPES = ['bar', 'line', 'pie', 'area'];

    /** The fills a rule may use: the ones the editor offers. */
    private const FILLS = ['#FBF0BF', '#DCEBD9', '#D5ECE7', '#F7E0DA', '#EBE2F4'];

    private const MAX_TEXT = 120;

    private const MAX_FORMULA = 500;

    private const MAX_CHOICES = 30;

    private const MAX_LOOSE_CELLS = 400;

    /**
     * @param  array<mixed>  $raw
     * @return array<string, mixed>|null a blueprint fit to build, or null when nothing usable is left
     */
    public function clean(array $raw): ?array
    {
        $sheets = [];
        $taken = [];
        foreach (array_slice(is_array($raw['sheets'] ?? null) ? array_values($raw['sheets']) : [], 0, WorkbookBuilder::MAX_SHEETS) as $sheet) {
            if (! is_array($sheet)) {
                continue;
            }
            $name = $this->sheetName($sheet['name'] ?? null, $taken);
            if ($name === null) {
                continue;
            }
            $built = $this->sheet($sheet);
            if ($built === null) {
                continue;
            }
            $taken[] = mb_strtolower($name);
            $sheets[] = ['name' => $name, 'color' => $sheets === [] ? 'teal' : 'green', 'frozen_rows' => isset($built['table']) ? 1 : 0, ...$built];
        }

        return $sheets === [] ? null : ['sheets' => $sheets];
    }

    /**
     * @param  list<string>  $taken
     */
    private function sheetName(mixed $name, array $taken): ?string
    {
        if (! is_string($name)) {
            return null;
        }
        $name = trim($name);
        $fits = $name !== '' && mb_strlen($name) <= WorkbookSheets::MAX_NAME && preg_match('/[\[\]:*?\/\\\\\']/', $name) === 0;

        return $fits && ! in_array(mb_strtolower($name), $taken, true) ? $name : null;
    }

    /**
     * @param  array<mixed>  $sheet
     * @return array<string, mixed>|null
     */
    private function sheet(array $sheet): ?array
    {
        $built = [];

        $columns = [];
        foreach (array_slice(is_array($sheet['columns'] ?? null) ? array_values($sheet['columns']) : [], 0, WorkbookBuilder::MAX_COLUMNS) as $column) {
            if (is_array($column) && ($clean = $this->column($column)) !== null) {
                $columns[] = $clean;
            }
        }
        if ($columns !== []) {
            $rows = filter_var($sheet['rows'] ?? 100, FILTER_VALIDATE_INT);
            $built['table'] = ['rows' => max(1, min(WorkbookBuilder::MAX_ROWS, $rows === false ? 100 : $rows)), 'columns' => $columns];
        }

        $cells = [];
        foreach (array_slice(is_array($sheet['cells'] ?? null) ? $sheet['cells'] : [], 0, self::MAX_LOOSE_CELLS, true) as $reference => $value) {
            if (is_string($reference) && RangeText::parse($reference) !== null && ($text = $this->cellValue($value)) !== null) {
                $cells[strtoupper($reference)] = $text;
            }
        }
        if ($cells !== []) {
            $built['cells'] = $cells;
        }

        if ($built === []) {
            return null;
        }

        $rules = [];
        foreach (is_array($sheet['rules'] ?? null) ? array_values($sheet['rules']) : [] as $rule) {
            if (is_array($rule) && ($clean = $this->rule($rule)) !== null) {
                $rules[] = $clean;
            }
        }
        if ($rules !== []) {
            $built['rules'] = $rules;
        }

        $charts = [];
        foreach (is_array($sheet['charts'] ?? null) ? array_slice(array_values($sheet['charts']), 0, 3) : [] as $chart) {
            if (is_array($chart) && ($clean = $this->chart($chart)) !== null) {
                $charts[] = $clean;
            }
        }
        if ($charts !== []) {
            $built['charts'] = $charts;
        }

        return $built;
    }

    /**
     * @param  array<mixed>  $column
     * @return array<string, mixed>|null
     */
    private function column(array $column): ?array
    {
        $heading = is_string($column['heading'] ?? null) ? trim(mb_substr($column['heading'], 0, self::MAX_TEXT)) : '';
        if ($heading === '') {
            return null;
        }

        $clean = ['heading' => $heading, 'width' => max(48, min(420, (int) ($column['width'] ?? 140)))];

        if (is_string($column['formula'] ?? null) && $this->fairFormula($column['formula'])) {
            $clean['formula'] = $column['formula'];
        }
        $format = is_array($column['format'] ?? null) ? CellFormat::clean($column['format']) : null;
        if ($format !== null) {
            $clean['format'] = $format;
        }
        $choices = is_array($column['choices'] ?? null) ? array_values(array_filter($column['choices'], fn ($item) => is_string($item) && trim($item) !== '' && mb_strlen($item) <= 60)) : [];
        if ($choices !== []) {
            $clean['allowed'] = array_slice($choices, 0, self::MAX_CHOICES);
        }

        return $clean;
    }

    private function cellValue(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '' || mb_strlen($value) > self::MAX_FORMULA) {
            return null;
        }
        if (str_starts_with($value, '=')) {
            return $this->fairFormula($value) ? $value : null;
        }

        return is_numeric(trim($value)) ? null : mb_substr($value, 0, self::MAX_TEXT);
    }

    public function fairFormula(string $formula): bool
    {
        if (! str_starts_with($formula, '=') || mb_strlen($formula) > self::MAX_FORMULA) {
            return false;
        }

        // Words in quotes are text, not functions.
        preg_match_all('/([A-Z][A-Z0-9.]*)\s*\(/i', preg_replace('/"[^"]*"/', '""', $formula) ?? '', $calls);
        foreach ($calls[1] as $function) {
            if (FunctionCatalogue::find($function) === null) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<mixed>  $rule
     * @return array{column: string, operator: string, value: string, color: string}|null
     */
    private function rule(array $rule): ?array
    {
        $column = is_string($rule['column'] ?? null) ? strtoupper($rule['column']) : '';
        $operator = $rule['operator'] ?? null;
        $value = $rule['value'] ?? null;
        if (RangeText::parse($column.'1') === null || ! in_array($operator, self::OPERATORS, true) || ! is_scalar($value)) {
            return null;
        }
        if (in_array($operator, ['>', '>=', '<', '<='], true) && ! is_numeric($value)) {
            return null;
        }
        $color = in_array($rule['color'] ?? null, self::FILLS, true) ? $rule['color'] : self::FILLS[0];

        return ['column' => $column, 'operator' => $operator, 'value' => (string) $value, 'color' => $color];
    }

    /**
     * @param  array<mixed>  $chart
     * @return array{title: string, type: string, data: string, labels: string|null}|null
     */
    private function chart(array $chart): ?array
    {
        $data = is_string($chart['data'] ?? null) ? RangeText::parse($chart['data']) : null;
        if ($data === null || ($data['r1'] === $data['r2'] && $data['c1'] === $data['c2']) || ! in_array($chart['type'] ?? null, self::CHART_TYPES, true)) {
            return null;
        }
        $labels = is_string($chart['labels'] ?? null) ? RangeText::parse($chart['labels']) : null;

        return [
            'title' => is_string($chart['title'] ?? null) ? mb_substr(trim($chart['title']), 0, self::MAX_TEXT) : 'Chart',
            'type' => $chart['type'],
            'data' => RangeText::of($data),
            'labels' => $labels !== null && $labels['c1'] === $labels['c2'] ? RangeText::of($labels) : null,
        ];
    }
}
