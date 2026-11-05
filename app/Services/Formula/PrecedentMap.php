<?php

namespace App\Services\Formula;

use Illuminate\Support\Facades\DB;

/**
 * Which formulas read an area of a sheet, answered from memory. Asking the database once per cell is quick for an edit
 * that touches a few formulas and hopeless for one that touches thousands, so past a few areas the recalculator loads a
 * sheet's dependencies once and asks here. Holds plain integers per column, so 128 MB is enough for a full sheet.
 */
class PrecedentMap
{
    /** @var array<int, true> */
    private array $loaded = [];

    /** @var array<int, array<int, array<int, list<int>>>> tab => col => row => reader numbers */
    private array $cells = [];

    /** @var array<int, array<int, array{from: list<int>, to: list<int>, reach: list<int>, reader: list<int>}>> tab => col => ranges by first row */
    private array $ranges = [];

    /** @var list<string> reader number => "tab:row:col" */
    private array $readers = [];

    /** @var array<string, int> */
    private array $numbers = [];

    /**
     * @return list<string> the formulas that read this area, each as "tab:row:col", repeated if read twice over
     */
    public function readers(int $tab, int $r1, int $r2, int $c1, int $c2): array
    {
        $this->load($tab);

        $found = [];
        foreach ($this->cells[$tab] ?? [] as $col => $rows) {
            if ($col < $c1 || $col > $c2) {
                continue;
            }
            if ($r1 === $r2) {
                foreach ($rows[$r1] ?? [] as $reader) {
                    $found[] = $reader;
                }

                continue;
            }
            foreach ($rows as $row => $readers) {
                if ($row >= $r1 && $row <= $r2) {
                    array_push($found, ...$readers);
                }
            }
        }

        foreach ($this->ranges[$tab] ?? [] as $col => $list) {
            if ($col < $c1 || $col > $c2) {
                continue;
            }
            // Sorted by first row, with the furthest any range so far reaches: nothing further down can overlap.
            for ($i = $this->lastStartingBy($list['from'], $r2); $i >= 0 && $list['reach'][$i] >= $r1; $i--) {
                if ($list['to'][$i] >= $r1) {
                    $found[] = $list['reader'][$i];
                }
            }
        }

        return array_map(fn (int $reader) => $this->readers[$reader], array_values(array_unique($found)));
    }

    private function load(int $tab): void
    {
        if (isset($this->loaded[$tab])) {
            return;
        }
        $this->loaded[$tab] = true;

        $ranges = [];
        $rows = DB::table('cell_precedents')->where('precedent_tab_id', $tab)->select('sheet_tab_id', 'row_index', 'col_index', 'row_from', 'row_to', 'col_from', 'col_to');
        foreach ($rows->cursor() as $row) {
            $this->add($tab, $row->sheet_tab_id.':'.$row->row_index.':'.$row->col_index, (int) $row->row_from, (int) $row->row_to, (int) $row->col_from, (int) $row->col_to, $ranges);
        }

        // An answer spilled over cells depends on them too: it lands on them, or something there blocks it.
        $spills = DB::table('cells')->where('sheet_tab_id', $tab)->whereNotNull('spill_rows')->select('row_index', 'col_index', 'spill_rows', 'spill_cols');
        foreach ($spills->cursor() as $cell) {
            $this->add($tab, $tab.':'.$cell->row_index.':'.$cell->col_index, (int) $cell->row_index, $cell->row_index + $cell->spill_rows - 1, (int) $cell->col_index, $cell->col_index + $cell->spill_cols - 1, $ranges);
        }

        foreach ($ranges as $col => $list) {
            array_multisort($list['from'], SORT_ASC, SORT_NUMERIC, $list['to'], $list['reader']);
            $reach = [];
            $furthest = -1;
            foreach ($list['to'] as $i => $to) {
                $reach[$i] = $furthest = max($furthest, $to);
            }
            $this->ranges[$tab][$col] = ['from' => $list['from'], 'to' => $list['to'], 'reach' => $reach, 'reader' => $list['reader']];
        }
    }

    /** @param  array<int, array<string, list<int>>>  $ranges */
    private function add(int $tab, string $reader, int $r1, int $r2, int $c1, int $c2, array &$ranges): void
    {
        $number = $this->numbers[$reader] ??= array_push($this->readers, $reader) - 1;

        if ($r1 === $r2 && $c1 === $c2) {
            $this->cells[$tab][$c1][$r1][] = $number;

            return;
        }

        for ($col = $c1; $col <= $c2; $col++) {
            $ranges[$col]['from'][] = $r1;
            $ranges[$col]['to'][] = $r2;
            $ranges[$col]['reader'][] = $number;
        }
    }

    /** @param  list<int>  $from  sorted */
    private function lastStartingBy(array $from, int $row): int
    {
        [$low, $high] = [0, count($from) - 1];
        $last = -1;
        while ($low <= $high) {
            $middle = ($low + $high) >> 1;
            if ($from[$middle] <= $row) {
                $last = $middle;
                $low = $middle + 1;
            } else {
                $high = $middle - 1;
            }
        }

        return $last;
    }
}
