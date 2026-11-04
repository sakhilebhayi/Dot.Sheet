<?php

namespace App\Services\Formula;

use App\Models\Cell;
use App\Models\NamedRange;
use Illuminate\Support\Collection;

/**
 * Turns a sentence into a formula using the headings actually on the sheet, such as "total expenses for
 * each department". It reads what it can and asks nothing of AI, so the answer is always a formula that
 * can be read and checked. When a heading is not found it says so and lists the ones it can see.
 */
class FormulaFromWords
{
    private const MOST_GROUPS = 100;

    private const MOST_ROWS_READ = 5000;

    /** The word people use => [function, function with conditions]. */
    private const MEASURES = [
        'total' => ['SUM', 'SUMIFS'], 'sum' => ['SUM', 'SUMIFS'], 'add' => ['SUM', 'SUMIFS'],
        'average' => ['AVERAGE', 'AVERAGEIFS'], 'mean' => ['AVERAGE', 'AVERAGEIFS'],
        'highest' => ['MAX', 'MAXIFS'], 'largest' => ['MAX', 'MAXIFS'], 'biggest' => ['MAX', 'MAXIFS'], 'maximum' => ['MAX', 'MAXIFS'],
        'lowest' => ['MIN', 'MINIFS'], 'smallest' => ['MIN', 'MINIFS'], 'minimum' => ['MIN', 'MINIFS'],
    ];

    private const COUNTING = ['count', 'how many', 'number of'];

    /**
     * @return array{formula?: string, group?: array{heading: string, keys: list<string>, formula: string}, heading?: string, says: string}
     */
    public function read(int $sheetTabId, string $sentence): array
    {
        $table = $this->tableOn($sheetTabId);
        if ($table === null) {
            return ['says' => 'This sheet has no headings to work from. Put headings in the top row of a table first.'];
        }

        $words = ' '.mb_strtolower(trim($sentence)).' ';
        [$plain, $conditional] = $this->measure($words);
        $counting = $plain === null && $this->isCounting($words);
        if ($plain === null && ! $counting) {
            return ['says' => 'Say what to work out, such as the total, average, highest, lowest or how many.'];
        }

        $byColumn = $this->groupColumn($words, $table);
        $named = $this->namedConditions($words, $table);
        $measured = $this->measuredColumn($words, $table, [$byColumn['col'] ?? null, ...array_column($named, 'col')]);
        if (! $counting && $measured === null) {
            return ['says' => 'Which column? This sheet has: '.implode(', ', array_column($table['columns'], 'heading')).'.'];
        }

        $filters = $named !== [] ? $named : $this->valuesInTheData($words, $table, [$measured['col'] ?? null, $byColumn['col'] ?? null]);
        $range = $measured === null ? null : $this->range($measured['col'], $table);

        if ($byColumn !== null) {
            $keys = $this->values($byColumn['col'], $table);
            if ($keys === []) {
                return ['says' => "{$byColumn['heading']} has nothing in it yet."];
            }
            if (count($keys) > self::MOST_GROUPS) {
                return ['says' => "{$byColumn['heading']} has more than ".self::MOST_GROUPS.' different values. A pivot table will read better.'];
            }

            return [
                'group' => [
                    'heading' => $byColumn['heading'],
                    'keys' => $keys,
                    'formula' => $counting
                        ? '=COUNTIFS('.$this->range($byColumn['col'], $table).',{key}'.$this->conditionText($filters, $table).')'
                        : "={$conditional}({$range},".$this->range($byColumn['col'], $table).',{key}'.$this->conditionText($filters, $table).')',
                ],
                'heading' => $measured['heading'] ?? 'How many',
                'says' => 'One line for each '.$byColumn['heading'].'. New values will need a new line.',
            ];
        }

        if ($filters !== []) {
            $tail = ltrim($this->conditionText($filters, $table), ',');

            return ['formula' => $counting ? "=COUNTIFS({$tail})" : "={$conditional}({$range},{$tail})", 'says' => ''];
        }

        if ($counting) {
            $column = $measured === null ? $table['columns'][0]['col'] : $measured['col'];

            return ['formula' => '=COUNTA('.$this->range($column, $table).')', 'says' => ''];
        }

        return ['formula' => "={$plain}({$range})", 'says' => ''];
    }

    /**
     * The first row with text in two or more cells, and how far down the data goes.
     *
     * @return array{sheet: int, top: int, last: int, columns: list<array{col: int, heading: string}>}|null
     */
    public function tableOn(int $sheetTabId): ?array
    {
        /** @var Collection<int, Collection<int, Cell>> $rows */
        $rows = Cell::where('sheet_tab_id', $sheetTabId)->where('row_index', '<', 50)->whereNull('formula')->whereNotNull('raw_value')->where('raw_value', '!=', '')
            ->orderBy('row_index')->orderBy('col_index')->get(['row_index', 'col_index', 'raw_value'])->groupBy('row_index');

        foreach ($rows as $row => $cells) {
            $words = $cells->reject(fn (Cell $cell) => is_numeric($cell->raw_value));
            if ($words->count() >= 2) {
                $last = (int) Cell::where('sheet_tab_id', $sheetTabId)->max('row_index');

                return [
                    'sheet' => $sheetTabId,
                    'top' => (int) $row,
                    'last' => max((int) $row + 1, min($last, (int) $row + self::MOST_ROWS_READ)),
                    'columns' => $words->map(fn (Cell $cell) => ['col' => (int) $cell->col_index, 'heading' => trim((string) $cell->raw_value)])->values()->all(),
                ];
            }
        }

        return null;
    }

    /**
     * @return array{0: string|null, 1: string|null}
     */
    private function measure(string $words): array
    {
        foreach (self::MEASURES as $word => $functions) {
            if (preg_match('/\b'.$word.'\b/', $words) === 1) {
                return $functions;
            }
        }

        return [null, null];
    }

    private function isCounting(string $words): bool
    {
        foreach (self::COUNTING as $word) {
            if (preg_match('/\b'.$word.'\b/', $words) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * "for each department", "by region", "per shift".
     *
     * @param  array{sheet: int, top: int, last: int, columns: list<array{col: int, heading: string}>}  $table
     * @return array{col: int, heading: string}|null
     */
    private function groupColumn(string $words, array $table): ?array
    {
        foreach ($table['columns'] as $column) {
            if (preg_match('/\b(?:for each|for every|each|by|per)\s+(?:the\s+)?'.$this->pattern($column['heading']).'\b/u', $words) === 1) {
                return $column;
            }
        }

        return null;
    }

    /**
     * @param  array{sheet: int, top: int, last: int, columns: list<array{col: int, heading: string}>}  $table
     * @param  list<int|null>  $skip  columns already spoken for
     * @return array{col: int, heading: string}|null
     */
    private function measuredColumn(string $words, array $table, array $skip): ?array
    {
        // Longest heading first, so "Unit price" wins over "Price".
        $columns = $table['columns'];
        usort($columns, fn (array $a, array $b) => mb_strlen($b['heading']) <=> mb_strlen($a['heading']));
        foreach ($columns as $column) {
            if (! in_array($column['col'], $skip, true) && preg_match('/\b'.$this->pattern($column['heading']).'\b/u', $words) === 1) {
                return $column;
            }
        }

        return null;
    }

    /**
     * "where status is open".
     *
     * @param  array{sheet: int, top: int, last: int, columns: list<array{col: int, heading: string}>}  $table
     * @return list<array{col: int, value: string}>
     */
    private function namedConditions(string $words, array $table): array
    {
        $filters = [];
        foreach ($table['columns'] as $column) {
            if (preg_match('/\b'.$this->pattern($column['heading']).'\s+(?:is|=|equals)\s+(.+?)(?:\s+and\s+|\s*$)/u', $words, $said) === 1) {
                $filters[] = ['col' => $column['col'], 'value' => trim($said[1], " \t\n\r\0\x0B\"'.?!")];
            }
        }

        return $filters;
    }

    /**
     * "for Rent": a value that sits in a column of the table.
     *
     * @param  array{sheet: int, top: int, last: int, columns: list<array{col: int, heading: string}>}  $table
     * @param  list<int|null>  $skip
     * @return list<array{col: int, value: string}>
     */
    private function valuesInTheData(string $words, array $table, array $skip): array
    {
        $filters = [];
        foreach ($table['columns'] as $column) {
            if (in_array($column['col'], $skip, true)) {
                continue;
            }
            foreach ($this->values($column['col'], $table) as $value) {
                if (preg_match('/\b(?:for|in|of)\s+'.preg_quote(mb_strtolower($value), '/').'\b/u', $words) === 1) {
                    $filters[] = ['col' => $column['col'], 'value' => $value];

                    break;
                }
            }
        }

        return $filters;
    }

    /**
     * @param  list<array{col: int, value: string}>  $filters
     * @param  array{sheet: int, top: int, last: int, columns: list<array{col: int, heading: string}>}  $table
     */
    private function conditionText(array $filters, array $table): string
    {
        return implode('', array_map(fn (array $filter) => ','.$this->range($filter['col'], $table).',"'.str_replace('"', '""', $filter['value']).'"', $filters));
    }

    /**
     * @param  array{sheet: int, top: int, last: int, columns: list<array{col: int, heading: string}>}  $table
     * @return list<string>
     */
    private function values(int $col, array $table): array
    {
        return Cell::where('sheet_tab_id', $table['sheet'])->where('col_index', $col)->whereBetween('row_index', [$table['top'] + 1, $table['last']])
            ->orderBy('row_index')->get(['computed_value', 'raw_value'])
            ->map(fn (Cell $cell) => trim((string) ($cell->computed_value ?? $cell->raw_value)))->filter(fn (string $value) => $value !== '')->unique()->values()->all();
    }

    /**
     * @param  array{sheet: int, top: int, last: int, columns: list<array{col: int, heading: string}>}  $table
     */
    private function range(int $col, array $table): string
    {
        $letter = NamedRange::colIndexToLetter($col);

        return '$'.$letter.'$'.($table['top'] + 2).':$'.$letter.'$'.($table['last'] + 1);
    }

    /** A heading in a sentence, singular or plural. */
    private function pattern(string $heading): string
    {
        $word = preg_quote(mb_strtolower($heading), '/');

        return str_ends_with($word, 's') ? substr($word, 0, -1).'s?' : "{$word}s?";
    }
}
