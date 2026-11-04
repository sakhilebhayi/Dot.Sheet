<?php

namespace App\Services\Formula;

use App\Models\Cell;
use App\Models\NamedRange;
use App\Models\SheetTab;
use Illuminate\Support\Facades\DB;

/**
 * Why a cell shows an error, and a repair when one is certain. A repair is only offered when it changes
 * the formula into something that can be checked by reading it; the person presses it, and undo takes it
 * back. Nothing here changes a cell.
 */
class FormulaDoctor
{
    private const MOST_NAMED = 4;

    /** How far off a misspelt name may be and still be offered as what was meant. */
    private const CLOSE_ENOUGH = 2;

    /**
     * @return array{title: string, plain: string, repair: array{formula: string, why: string}|null}|null null when there is no error
     */
    public function diagnose(Cell $cell): ?array
    {
        $shown = (string) $cell->computed_value;
        if (! str_starts_with($shown, '#') || $cell->formula === null) {
            return null;
        }
        $formula = (string) $cell->formula;

        return match (true) {
            str_starts_with($shown, '#DIV/0!') => $this->dividedByZero($cell, $formula),
            str_starts_with($shown, '#NAME?') => $this->unknownName($cell, $formula),
            str_starts_with($shown, '#REF!') => $this->brokenReference($cell, $formula),
            str_starts_with($shown, '#VALUE!') => $this->wrongKind($cell),
            str_starts_with($shown, '#N/A') => $this->notFound($formula),
            str_starts_with($shown, '#SPILL!') => $this->blocked(),
            str_starts_with($shown, '#NUM!') => ['title' => 'A number out of range', 'plain' => 'The sum asks for something numbers cannot do, such as the square root of a negative, or the answer is too large.', 'repair' => null],
            str_starts_with($shown, '#NULL!') => ['title' => 'Ranges that do not meet', 'plain' => 'A space between two ranges means where they overlap, and these do not. Use a comma to list them.', 'repair' => null],
            default => ['title' => 'This formula has a problem', 'plain' => 'It could not be worked out. Check its brackets and what it reads.', 'repair' => null],
        };
    }

    /**
     * @return array{title: string, plain: string, repair: array{formula: string, why: string}|null}
     */
    private function dividedByZero(Cell $cell, string $formula): array
    {
        $empty = array_map(fn (array $read) => $read['name'], array_filter($this->readCells($cell), fn (array $read) => in_array((string) $read['value'], ['', '0'], true)));
        $why = $empty === [] ? 'It divides by zero or by an empty cell.' : 'It divides by zero or by an empty cell, and '.$this->listed($empty).(count($empty) === 1 ? ' is' : ' are').' empty or zero.';

        return ['title' => 'Divided by zero', 'plain' => $why, 'repair' => $this->wrapped($formula, 'Show nothing until there is something to divide by.')];
    }

    /**
     * @return array{title: string, plain: string, repair: array{formula: string, why: string}|null}
     */
    private function unknownName(Cell $cell, string $formula): array
    {
        $text = (string) preg_replace('/"[^"]*"/', '""', $formula);
        $known = array_merge(array_column(FunctionCatalogue::all(), 'name'));

        preg_match_all('/([A-Za-z_][A-Za-z0-9_.]*)\s*\(/', $text, $calls);
        foreach ($calls[1] as $function) {
            if (FunctionCatalogue::find($function) !== null) {
                continue;
            }
            $closest = $this->closest(strtoupper($function), $known);
            if ($closest === null) {
                return ['title' => 'A function this product does not know', 'plain' => "There is no function called {$function}.", 'repair' => null];
            }

            return [
                'title' => 'A misspelt function',
                'plain' => "There is no function called {$function}. Did you mean {$closest}?",
                'repair' => ['formula' => (string) preg_replace('/\b'.preg_quote($function, '/').'(\s*\()/i', $closest.'$1', $formula), 'why' => "Change {$function} to {$closest}."],
            ];
        }

        $names = NamedRange::where('spreadsheet_id', $cell->spreadsheet_id)->pluck('name')->all();
        preg_match_all('/(?<![A-Za-z0-9_.!\'$:])([A-Za-z_][A-Za-z0-9_.]*)(?![A-Za-z0-9_.!(:])/', $text, $words);
        foreach ($words[1] as $word) {
            if (preg_match('/^[A-Za-z]{1,3}\d+$/', $word) === 1 || in_array(strtoupper($word), ['TRUE', 'FALSE'], true) || in_array($word, $names, true)) {
                continue;
            }
            $closest = $this->closest($word, $names);
            if ($closest === null) {
                return ['title' => 'A name that is not defined', 'plain' => "Nothing here is called {$word}. Name a range that, or put text in quotes.", 'repair' => null];
            }

            return [
                'title' => 'A misspelt name',
                'plain' => "Nothing here is called {$word}. Did you mean {$closest}?",
                'repair' => ['formula' => (string) preg_replace('/\b'.preg_quote($word, '/').'\b/', $closest, $formula), 'why' => "Change {$word} to {$closest}."],
            ];
        }

        return ['title' => 'A name it does not know', 'plain' => 'A word in this formula is not a function, a name or a cell.', 'repair' => null];
    }

    /**
     * @return array{title: string, plain: string, repair: array{formula: string, why: string}|null}
     */
    private function brokenReference(Cell $cell, string $formula): array
    {
        $sheets = SheetTab::where('spreadsheet_id', $cell->spreadsheet_id)->pluck('name')->all();

        preg_match_all('/(?:\'((?:[^\']|\'\')+)\'|([A-Za-z_][A-Za-z0-9_.]*))!/', $formula, $named, PREG_SET_ORDER);
        foreach ($named as $found) {
            $sheet = ($found[1] ?? '') !== '' ? str_replace("''", "'", $found[1]) : $found[2];
            if (in_array($sheet, $sheets, true) || $sheet === '#REF') {
                continue;
            }
            $closest = $this->closest($sheet, $sheets);
            if ($closest === null) {
                return ['title' => 'A sheet that is not here', 'plain' => "This reads from a sheet called {$sheet}, and there is no sheet by that name.", 'repair' => null];
            }

            return [
                'title' => 'A sheet that is not here',
                'plain' => "This reads from a sheet called {$sheet}. Did you mean {$closest}?",
                'repair' => ['formula' => str_replace($found[0], $this->sheetReference($closest).'!', $formula), 'why' => "Read from {$closest} instead."],
            ];
        }

        return ['title' => 'A cell that is gone', 'plain' => 'This points at a cell that was deleted, or off the edge of the sheet. Point it at the cell it should read.', 'repair' => null];
    }

    /**
     * @return array{title: string, plain: string, repair: null}
     */
    private function wrongKind(Cell $cell): array
    {
        $words = array_map(fn (array $read) => $read['name'], array_filter($this->readCells($cell), fn (array $read) => ! is_numeric($read['value']) && (string) $read['value'] !== ''));
        $plain = $words === [] ? 'It needed a number and was given something else.' : 'It needed a number, and '.$this->listed($words).(count($words) === 1 ? ' holds' : ' hold').' text.';

        return ['title' => 'Text where a number should be', 'plain' => $plain, 'repair' => null];
    }

    /**
     * @return array{title: string, plain: string, repair: array{formula: string, why: string}|null}
     */
    private function notFound(string $formula): array
    {
        return ['title' => 'Nothing found', 'plain' => 'A lookup could not find what it was looking for. Check the spelling, and that the value is in the first column it searches.', 'repair' => $this->wrapped($formula, 'Show nothing when there is no match.')];
    }

    /**
     * @return array{title: string, plain: string, repair: null}
     */
    private function blocked(): array
    {
        return ['title' => 'No room for the answer', 'plain' => 'This answer needs more than one cell, and something is in the way. Clear the cells below or beside it.', 'repair' => null];
    }

    /**
     * @return array{formula: string, why: string}|null
     */
    private function wrapped(string $formula, string $why): ?array
    {
        return str_starts_with(strtoupper($formula), '=IFERROR(') ? null : ['formula' => '=IFERROR('.substr($formula, 1).',"")', 'why' => $why];
    }

    private function sheetReference(string $name): string
    {
        return preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) === 1 ? $name : "'".str_replace("'", "''", $name)."'";
    }

    /**
     * The cells a formula reads, on any sheet, kept to a few.
     *
     * @return list<array{name: string, value: string|null}>
     */
    private function readCells(Cell $cell): array
    {
        $reads = DB::table('cell_precedents')->where('sheet_tab_id', $cell->sheet_tab_id)->where('row_index', $cell->row_index)->where('col_index', $cell->col_index)->get();

        $found = [];
        foreach ($reads as $read) {
            for ($row = $read->row_from; $row <= min($read->row_to, $read->row_from + 50); $row++) {
                for ($col = $read->col_from; $col <= min($read->col_to, $read->col_from + 20); $col++) {
                    $held = DB::table('cells')->where('sheet_tab_id', $read->precedent_tab_id)->where('row_index', $row)->where('col_index', $col)->first(['computed_value', 'raw_value']);
                    $found[] = ['name' => NamedRange::colIndexToLetter($col).($row + 1), 'value' => $held->computed_value ?? $held->raw_value ?? null];
                }
            }
        }

        return $found;
    }

    /**
     * @param  list<string>  $names
     */
    private function listed(array $names): string
    {
        $names = array_values(array_unique($names));
        $more = count($names) - self::MOST_NAMED;
        $shown = array_slice($names, 0, self::MOST_NAMED);
        if ($more > 0) {
            return implode(', ', $shown)." and {$more} more";
        }
        if (count($shown) === 1) {
            return $shown[0];
        }
        $last = array_pop($shown);

        return implode(', ', $shown)." and {$last}";
    }

    /**
     * @param  list<string>  $among
     */
    private function closest(string $word, array $among): ?string
    {
        $best = null;
        $distance = self::CLOSE_ENOUGH + 1;
        foreach ($among as $candidate) {
            $apart = levenshtein(strtoupper($word), strtoupper($candidate));
            if ($apart < $distance) {
                $distance = $apart;
                $best = $candidate;
            }
        }

        return $best;
    }
}
