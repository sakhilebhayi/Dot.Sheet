<?php

namespace App\Services\Formula;

/**
 * What a formula does, in plain words, worked out from the formula alone and so without AI. It reads
 * the common functions closely and says what any other one does in general. A formula too tangled to
 * read gets the plain answer that it is, rather than a wrong one.
 */
class FormulaExplainer
{
    /** Comparisons first, because they bind last. Each level is tried in turn, outside brackets and quotes. */
    private const OPERATORS = [
        ['>=' => 'is at least', '<=' => 'is at most', '<>' => 'is not', '>' => 'is more than', '<' => 'is less than', '=' => 'is'],
        ['&' => 'joined to'],
        ['+' => 'plus', '-' => 'minus'],
        ['*' => 'times', '/' => 'divided by'],
        ['^' => 'to the power of'],
    ];

    private const MAX_DEPTH = 8;

    public function explain(string $formula): string
    {
        $inner = ltrim(trim($formula), '=');
        if ($inner === '') {
            return 'This formula is empty.';
        }

        $said = $this->said($inner, 0);
        if ($said === null) {
            return 'This formula is too involved to put in a sentence. The threads show what it reads.';
        }

        return ucfirst($said).'.';
    }

    private function said(string $text, int $depth): ?string
    {
        $text = trim($text);
        if ($depth > self::MAX_DEPTH || $text === '') {
            return null;
        }

        while ($this->wrapped($text)) {
            $text = trim(substr($text, 1, -1));
        }

        foreach (self::OPERATORS as $level) {
            $found = $this->split($text, $level);
            if ($found !== null) {
                [$left, $word, $right] = $found;
                $a = $this->said($left, $depth + 1);
                $b = $this->said($right, $depth + 1);

                return $a === null || $b === null ? null : "{$a} {$word} {$b}";
            }
        }

        if (preg_match('/^([A-Za-z_][A-Za-z0-9_.]*)\((.*)\)$/s', $text, $call) === 1 && $this->closes($call[2])) {
            return $this->call(strtoupper($call[1]), $this->arguments($call[2]), $depth);
        }

        return $this->plain($text);
    }

    /** A value as someone would say it. */
    private function plain(string $text): ?string
    {
        if (preg_match('/^"(.*)"$/s', $text, $quoted) === 1) {
            return $quoted[1] === '' ? 'nothing' : '“'.str_replace('""', '"', $quoted[1]).'”';
        }
        if (is_numeric($text)) {
            return $text;
        }
        if (in_array(strtoupper($text), ['TRUE', 'FALSE'], true)) {
            return strtolower($text);
        }

        if (preg_match('/^(?:\'((?:[^\']|\'\')+)\'|([A-Za-z_][A-Za-z0-9_.]*))!(.+)$/', $text, $sheet) === 1) {
            $name = $sheet[1] !== '' ? str_replace("''", "'", $sheet[1]) : $sheet[2];
            $here = $this->plain($sheet[3]);

            return $here === null ? null : "{$here} on {$name}";
        }

        $clean = str_replace('$', '', $text);
        if (preg_match('/^[A-Za-z]{1,3}\d+:[A-Za-z]{1,3}\d+$/', $clean) === 1) {
            return 'cells '.str_replace(':', ' to ', strtoupper($clean));
        }
        if (preg_match('/^[A-Za-z]{1,3}:[A-Za-z]{1,3}$/', $clean) === 1) {
            return 'columns '.str_replace(':', ' to ', strtoupper($clean));
        }
        if (preg_match('/^[A-Za-z]{1,3}\d+$/', $clean) === 1) {
            return strtoupper($clean);
        }

        return preg_match('/^[A-Za-z_][A-Za-z0-9_.]*$/', $text) === 1 ? $text : null;
    }

    /**
     * @param  list<string>  $args
     */
    private function call(string $name, array $args, int $depth): ?string
    {
        $said = [];
        foreach ($args as $arg) {
            $piece = $this->said($arg, $depth + 1);
            if ($piece === null) {
                return null;
            }
            $said[] = $piece;
        }
        $all = $this->listed($said);
        [$a, $b, $c, $d] = array_pad($said, 4, null);

        return match ($name) {
            'SUM' => "the total of {$all}",
            'AVERAGE' => "the average of {$all}",
            'MIN' => "the lowest of {$all}",
            'MAX' => "the highest of {$all}",
            'COUNT' => "how many numbers there are in {$all}",
            'COUNTA' => "how many cells are filled in {$all}",
            'COUNTBLANK' => "how many cells are empty in {$all}",
            'PRODUCT' => "{$all} multiplied together",
            'ABS' => "{$a} without its sign",
            'SQRT' => "the square root of {$a}",
            'ROUND' => $this->rounded($a, $b, 'rounded'),
            'ROUNDUP' => $this->rounded($a, $b, 'rounded up'),
            'ROUNDDOWN' => $this->rounded($a, $b, 'rounded down'),
            'IF' => $this->conditional($a, $b, $c),
            'IFERROR' => "{$a}, or {$b} if that gives an error",
            'IFNA' => "{$a}, or {$b} if nothing is found",
            'AND' => "whether all of these hold: {$all}",
            'OR' => "whether any of these holds: {$all}",
            'NOT' => "the opposite of {$a}",
            'ISBLANK' => "whether {$a} is empty",
            'TODAY' => "today's date",
            'NOW' => 'the date and time now',
            'SUMIF' => $c === null ? "the total of the cells in {$a} that match {$b}" : "the total of {$c} where {$a} matches {$b}",
            'COUNTIF' => "how many cells in {$a} match {$b}",
            'AVERAGEIF' => $c === null ? "the average of the cells in {$a} that match {$b}" : "the average of {$c} where {$a} matches {$b}",
            'SUMIFS' => 'the total of '.$a.' where '.$this->conditions(array_slice($said, 1)),
            'AVERAGEIFS' => 'the average of '.$a.' where '.$this->conditions(array_slice($said, 1)),
            'MAXIFS' => 'the highest of '.$a.' where '.$this->conditions(array_slice($said, 1)),
            'MINIFS' => 'the lowest of '.$a.' where '.$this->conditions(array_slice($said, 1)),
            'COUNTIFS' => 'how many rows have '.$this->conditions($said),
            'VLOOKUP' => "the value in column {$c} of {$b}, on the row where the first column matches {$a}",
            'HLOOKUP' => "the value in row {$c} of {$b}, in the column where the first row matches {$a}",
            'XLOOKUP' => "the value from {$c} on the row where {$b} matches {$a}".($d === null ? '' : ", or {$d} if there is no match"),
            'INDEX' => $c === null ? "item {$b} of {$a}" : "the value at row {$b}, column {$c} of {$a}",
            'MATCH' => "where {$a} sits in {$b}",
            'CONCAT', 'CONCATENATE', 'TEXTJOIN' => "{$all} joined together",
            'LEFT' => "the first {$b} characters of {$a}",
            'RIGHT' => "the last {$b} characters of {$a}",
            'LEN' => "how many characters {$a} has",
            'UPPER' => "{$a} in capitals",
            'LOWER' => "{$a} in small letters",
            'TRIM' => "{$a} with spare spaces removed",
            'UNIQUE' => "each different value in {$a}, once",
            'SORT' => "{$a} in order",
            'FILTER' => "the rows of {$a} where {$b}",
            default => $this->general($name, $all),
        };
    }

    private function general(string $name, string $all): string
    {
        $known = FunctionCatalogue::find($name);
        if ($known === null) {
            return $all === '' ? "{$name}, which this product does not know" : "{$name} of {$all}, which this product does not know";
        }

        return lcfirst(rtrim($known['summary'], '.')).($all === '' ? '' : " ({$name} of {$all})");
    }

    private function rounded(?string $value, ?string $digits, string $how): string
    {
        return $digits === '0' ? "{$value} {$how} to a whole number" : "{$value} {$how} to {$digits} decimal places";
    }

    private function conditional(?string $test, ?string $yes, ?string $no): string
    {
        return $no === null ? "if {$test}, {$yes}" : "if {$test}, {$yes}, otherwise {$no}";
    }

    /**
     * @param  list<string>  $pairs  a range, what it must match, and so on
     */
    private function conditions(array $pairs): string
    {
        $parts = [];
        foreach (array_chunk($pairs, 2) as $pair) {
            $parts[] = $pair[0].' matches '.($pair[1] ?? '');
        }

        return implode(' and ', $parts);
    }

    /**
     * @param  list<string>  $items
     */
    private function listed(array $items): string
    {
        if (count($items) < 2) {
            return $items[0] ?? '';
        }
        $last = array_pop($items);

        return implode(', ', $items)." and {$last}";
    }

    /** True when one pair of brackets holds the whole of the text. */
    private function wrapped(string $text): bool
    {
        if (! str_starts_with($text, '(') || ! str_ends_with($text, ')')) {
            return false;
        }

        return $this->closes(substr($text, 1, -1));
    }

    /** True when the brackets in the text balance without ever going below zero. */
    private function closes(string $text): bool
    {
        $depth = 0;
        foreach ($this->outside($text) as [$char]) {
            $depth += $char === '(' ? 1 : ($char === ')' ? -1 : 0);
            if ($depth < 0) {
                return false;
            }
        }

        return $depth === 0;
    }

    /**
     * The last operator of a level outside brackets and quotes, so a chain reads left to right.
     *
     * @param  array<string, string>  $level
     * @return array{0: string, 1: string, 2: string}|null
     */
    private function split(string $text, array $level): ?array
    {
        $found = null;
        $depth = 0;
        foreach ($this->outside($text) as [$char, $at]) {
            $depth += $char === '(' ? 1 : ($char === ')' ? -1 : 0);
            if ($depth !== 0 || $at === 0) {
                continue;
            }
            foreach ($level as $operator => $word) {
                if (substr($text, $at, strlen($operator)) !== $operator) {
                    continue;
                }
                // A sign after another operator, or a part of <= and friends, is not this operator.
                $before = substr(rtrim(substr($text, 0, $at)), -1);
                if (in_array($operator, ['+', '-'], true) && in_array($before, ['', '(', ',', '*', '/', '^', '=', '<', '>', '+', '-', '&'], true)) {
                    continue;
                }
                if ($operator === '=' && in_array($before, ['<', '>'], true)) {
                    continue;
                }
                if (in_array($operator, ['<', '>'], true) && in_array(substr($text, $at + 1, 1), ['=', '>'], true)) {
                    continue;
                }
                if ($operator === '>' && $before === '<') {
                    continue;
                }
                $found = [substr($text, 0, $at), $word, substr($text, $at + strlen($operator))];
                break;
            }
        }

        return $found;
    }

    /**
     * @return list<string>
     */
    private function arguments(string $text): array
    {
        if (trim($text) === '') {
            return [];
        }

        $parts = [];
        $start = 0;
        $depth = 0;
        foreach ($this->outside($text) as [$char, $at]) {
            $depth += $char === '(' ? 1 : ($char === ')' ? -1 : 0);
            if ($depth === 0 && ($char === ',' || $char === ';')) {
                $parts[] = substr($text, $start, $at - $start);
                $start = $at + 1;
            }
        }
        $parts[] = substr($text, $start);

        return $parts;
    }

    /**
     * Each character outside quotes, and where it is.
     *
     * @return list<array{0: string, 1: int}>
     */
    private function outside(string $text): array
    {
        $chars = [];
        $quote = null;
        foreach (str_split($text) as $at => $char) {
            if ($quote !== null) {
                $quote = $char === $quote ? null : $quote;

                continue;
            }
            if ($char === '"' || $char === "'") {
                $quote = $char;

                continue;
            }
            $chars[] = [$char, $at];
        }

        return $chars;
    }
}
