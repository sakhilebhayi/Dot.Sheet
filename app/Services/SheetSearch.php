<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Finding text in a workbook, and working out what replacing it writes. A match is in what a cell holds
 * or in what it shows. Replacing only changes what it holds: a formula's text, never its answer.
 */
class SheetSearch
{
    /** Most matches sent to the browser. */
    public const MAX_MATCHES = 1000;

    /**
     * @param  list<int>  $tabs
     * @return array{matches: list<array{tab: int, r: int, c: int}>, more: bool}
     */
    public function find(array $tabs, string $query, bool $matchCase, bool $wholeCell): array
    {
        $matches = [];
        $more = false;
        foreach ($this->candidates($tabs, $query) as $cell) {
            $held = (string) ($cell->formula ?? $cell->raw_value);
            $shown = (string) ($cell->formula !== null || $cell->spill_row !== null ? $cell->computed_value : $cell->raw_value);
            if (! $this->matches($held, $query, $matchCase, $wholeCell) && ! $this->matches($shown, $query, $matchCase, $wholeCell)) {
                continue;
            }
            if (count($matches) === self::MAX_MATCHES) {
                $more = true;
                break;
            }
            $matches[] = ['tab' => (int) $cell->sheet_tab_id, 'r' => (int) $cell->row_index, 'c' => (int) $cell->col_index];
        }

        return ['matches' => $matches, 'more' => $more];
    }

    /**
     * The edits that replace the text wherever a cell holds it, sheet by sheet.
     *
     * @param  list<int>  $tabs
     * @param  array{tab: int, r: int, c: int}|null  $only  one cell, for a single Replace
     * @return array<int, list<array{row: int, col: int, value: string}>>
     */
    public function replacements(array $tabs, string $query, string $with, bool $matchCase, bool $wholeCell, ?array $only = null): array
    {
        $edits = [];
        foreach ($this->candidates($tabs, $query) as $cell) {
            if ($cell->spill_row !== null) {
                continue;
            }
            if ($only !== null && ((int) $cell->sheet_tab_id !== $only['tab'] || (int) $cell->row_index !== $only['r'] || (int) $cell->col_index !== $only['c'])) {
                continue;
            }

            $held = (string) ($cell->formula ?? $cell->raw_value);
            if (! $this->matches($held, $query, $matchCase, $wholeCell)) {
                continue;
            }

            $value = $wholeCell ? $with : (string) preg_replace('/'.preg_quote($query, '/').'/u'.($matchCase ? '' : 'i'), str_replace(['\\', '$'], ['\\\\', '\\$'], $with), $held);
            if ($value !== $held) {
                $edits[(int) $cell->sheet_tab_id][] = ['row' => (int) $cell->row_index, 'col' => (int) $cell->col_index, 'value' => $value];
            }
        }

        return $edits;
    }

    /**
     * Cells that may match, narrowed by the database, in reading order.
     *
     * @param  list<int>  $tabs
     * @return iterable<object>
     */
    private function candidates(array $tabs, string $query): iterable
    {
        $like = '%'.addcslashes($query, '%_\\').'%';

        return DB::table('cells')->whereIn('sheet_tab_id', $tabs)
            ->where(fn ($q) => $q->whereRaw("raw_value LIKE ? ESCAPE '\\'", [$like])
                ->orWhereRaw("formula LIKE ? ESCAPE '\\'", [$like])
                ->orWhereRaw("computed_value LIKE ? ESCAPE '\\'", [$like]))
            ->orderBy('sheet_tab_id')->orderBy('row_index')->orderBy('col_index')
            ->select('sheet_tab_id', 'row_index', 'col_index', 'raw_value', 'computed_value', 'formula', 'spill_row')
            ->cursor();
    }

    private function matches(string $text, string $query, bool $matchCase, bool $wholeCell): bool
    {
        if ($wholeCell) {
            return $matchCase ? $text === $query : mb_strtolower($text) === mb_strtolower($query);
        }

        return $matchCase ? str_contains($text, $query) : mb_stripos($text, $query) !== false;
    }
}
