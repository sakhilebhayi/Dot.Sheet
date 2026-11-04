<?php

namespace App\Services;

use App\Models\Cell;
use App\Services\Formula\FormulaText;
use Illuminate\Support\Carbon;

/**
 * Working out what filling writes. A column or row of cells either continues as a series (1, 2, 3;
 * Jan, Feb; Q1, Q2; dates) or repeats. Formulas follow their cell, as they do when copied.
 */
class RangeFill
{
    private const MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

    private const DAYS = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

    /**
     * The edits that take a selection out to a larger area, leaving the selection itself alone.
     *
     * @param  array{r1: int, r2: int, c1: int, c2: int}  $area
     * @param  array{r1: int, r2: int, c1: int, c2: int}  $target  contains $area and grows along one axis
     * @return list<array{row: int, col: int, value: string, x: array<string, mixed>|null}>
     */
    public function plan(int $tabId, array $area, array $target): array
    {
        $cells = Cell::where('sheet_tab_id', $tabId)
            ->whereBetween('row_index', [$area['r1'], $area['r2']])
            ->whereBetween('col_index', [$area['c1'], $area['c2']])
            ->get()->keyBy(fn (Cell $cell) => $cell->row_index.':'.$cell->col_index);

        $down = $target['r1'] !== $area['r1'] || $target['r2'] !== $area['r2'];

        $edits = [];
        foreach (range($down ? $area['c1'] : $area['r1'], $down ? $area['c2'] : $area['r2']) as $across) {
            $start = $down ? $area['r1'] : $area['c1'];
            $end = $down ? $area['r2'] : $area['c2'];

            $line = [];
            for ($along = $start; $along <= $end; $along++) {
                $line[] = $cells[$down ? $along.':'.$across : $across.':'.$along] ?? null;
            }

            $continue = $this->series($line);
            $length = count($line);

            foreach (range($down ? $target['r1'] : $target['c1'], $down ? $target['r2'] : $target['c2']) as $along) {
                if ($along >= $start && $along <= $end) {
                    continue;
                }

                $step = $along - $start;
                $base = (($step % $length) + $length) % $length;
                $cell = $line[$base];
                $moved = $step - $base;

                $edits[] = [
                    'row' => $down ? $along : $across,
                    'col' => $down ? $across : $along,
                    'value' => $this->valueAt($cell, $continue, $step, $down ? $moved : 0, $down ? 0 : $moved),
                    'x' => ($cell->formatting ?? null) ?: null,
                ];
            }
        }

        return $edits;
    }

    /**
     * @param  (callable(int): ?string)|null  $continue
     */
    private function valueAt(?Cell $cell, ?callable $continue, int $step, int $rows, int $cols): string
    {
        if ($cell === null) {
            return '';
        }
        if ($cell->spill_row !== null) {
            return (string) $cell->computed_value;
        }
        if ($cell->formula !== null) {
            return FormulaText::moved($cell->formula, $rows, $cols);
        }

        return ($continue === null ? null : $continue($step)) ?? (string) $cell->raw_value;
    }

    /**
     * How this line carries on, as a function of the distance from its first cell, or null when it repeats.
     *
     * @param  list<Cell|null>  $line
     * @return (callable(int): ?string)|null
     */
    private function series(array $line): ?callable
    {
        $values = [];
        foreach ($line as $cell) {
            if ($cell === null || $cell->formula !== null || $cell->spill_row !== null || (string) $cell->raw_value === '') {
                return null;
            }
            $values[] = (string) $cell->raw_value;
        }

        return $this->numbers($values) ?? $this->names($values, self::MONTHS) ?? $this->names($values, self::DAYS)
            ?? $this->dates($values) ?? $this->counted($values);
    }

    /**
     * @param  list<float|int>  $numbers
     */
    private function even(array $numbers): ?float
    {
        $step = $numbers[1] - $numbers[0];
        foreach ($numbers as $i => $number) {
            if (abs($number - ($numbers[0] + $i * $step)) > 1e-9) {
                return null;
            }
        }

        return (float) $step;
    }

    /**
     * Two or more evenly spaced numbers carry on. One number alone repeats, as it does in a spreadsheet.
     *
     * @param  list<string>  $values
     */
    private function numbers(array $values): ?callable
    {
        if (count($values) < 2 || array_filter($values, fn (string $v) => ! is_numeric($v)) !== []) {
            return null;
        }

        $numbers = array_map('floatval', $values);
        $step = $this->even($numbers);

        return $step === null ? null : fn (int $at) => (string) round($numbers[0] + $at * $step, 10);
    }

    /**
     * Names of months or days, in full or short, in the case they were typed.
     *
     * @param  list<string>  $values
     * @param  list<string>  $names
     */
    private function names(array $values, array $names): ?callable
    {
        $short = strlen($values[0]) === 3;
        $positions = [];
        foreach ($values as $value) {
            $found = array_search(strtolower($value), array_map(fn (string $name) => strtolower($short ? substr($name, 0, 3) : $name), $names), true);
            if ($found === false) {
                return null;
            }
            $positions[] = $found;
        }

        $count = count($names);
        $step = count($positions) > 1 ? (($positions[1] - $positions[0]) % $count + $count) % $count : 1;
        foreach ($positions as $i => $position) {
            if ($position !== ($positions[0] + $i * $step) % $count) {
                return null;
            }
        }

        $first = $values[0];

        return function (int $at) use ($positions, $step, $count, $names, $short, $first) {
            $name = $names[((($positions[0] + $at * $step) % $count) + $count) % $count];
            $name = $short ? substr($name, 0, 3) : $name;

            if ($first === strtoupper($first)) {
                return strtoupper($name);
            }

            return $first === strtolower($first) ? strtolower($name) : $name;
        };
    }

    /**
     * Dates written year first: one date counts by days.
     *
     * @param  list<string>  $values
     */
    private function dates(array $values): ?callable
    {
        $days = [];
        foreach ($values as $value) {
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) || ! checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4))) {
                return null;
            }
            $days[] = (int) (Carbon::parse($value, 'UTC')->timestamp / 86400);
        }

        $step = count($days) > 1 ? $this->even($days) : 1.0;

        return $step === null ? null : fn (int $at) => Carbon::createFromTimestampUTC((int) (($days[0] + $at * $step) * 86400))->format('Y-m-d');
    }

    /**
     * Text ending in a number, such as Q1 or Unit 007: the number counts on, keeping its width.
     *
     * @param  list<string>  $values
     */
    private function counted(array $values): ?callable
    {
        $counts = [];
        $prefix = null;
        foreach ($values as $value) {
            if (! preg_match('/^(.*?\D)(\d{1,15})$/su', $value, $m) || ($prefix !== null && $m[1] !== $prefix)) {
                return null;
            }
            $prefix = $m[1];
            $counts[] = (int) $m[2];
        }

        preg_match('/(\d+)$/', $values[0], $digits);
        $width = strlen($digits[1]);
        $padded = $digits[1][0] === '0';
        $step = count($counts) > 1 ? $this->even($counts) : 1.0;

        if ($step === null) {
            return null;
        }

        return function (int $at) use ($counts, $step, $prefix, $width, $padded): ?string {
            $number = $counts[0] + (int) ($at * $step);
            if ($number < 0) {
                return null;
            }

            return $prefix.($padded ? str_pad((string) $number, $width, '0', STR_PAD_LEFT) : $number);
        };
    }
}
