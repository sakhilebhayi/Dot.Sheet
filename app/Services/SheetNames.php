<?php

namespace App\Services;

use App\Models\NamedRange;
use App\Services\Formula\FunctionCatalogue;
use App\Services\Formula\Recalculator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Names for cells on one sheet, in both editors: Revenue for A1:A40, so a formula can say =SUM(Revenue).
 * Function names and anything that reads as a cell are refused, because either would be ambiguous in a
 * formula. See FormulaEvaluatorService::substituteNamedRanges() for how a name is expanded.
 */
class SheetNames
{
    private const RESERVED_WORDS = ['AVG', 'CONCAT', 'TRUE', 'FALSE'];

    /**
     * @return list<array{id: int, name: string, reference: string, row: int, col: int, row2: int, col2: int}>
     */
    public function of(int $spreadsheetId, int $tabId): array
    {
        return NamedRange::where('spreadsheet_id', $spreadsheetId)->where('sheet_tab_id', $tabId)->orderBy('name')->get()
            ->map(fn (NamedRange $range) => [
                'id' => $range->id,
                'name' => $range->name,
                'reference' => $range->toReferenceText(),
                'row' => $range->start_row,
                'col' => $range->start_col,
                'row2' => $range->end_row,
                'col2' => $range->end_col,
            ])->all();
    }

    /**
     * @throws NameRefused
     */
    public function add(int $spreadsheetId, int $tabId, string $name, string $range): NamedRange
    {
        $name = trim($name);

        if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/', $name)) {
            throw new NameRefused('Name must start with a letter or underscore and contain only letters, numbers, and underscores.');
        }

        if (preg_match('/^[A-Z]{1,3}\d+$/', strtoupper($name)) || $this->isReserved($name)) {
            throw new NameRefused("\"{$name}\" looks like a cell reference or function name and can't be used.");
        }

        if (NamedRange::where('sheet_tab_id', $tabId)->whereRaw('LOWER(name) = ?', [strtolower($name)])->exists()) {
            throw new NameRefused("A named range called \"{$name}\" already exists on this sheet.");
        }

        $area = RangeText::parse($range);
        if ($area === null) {
            throw new NameRefused('Range must look like A1 or A1:B10.');
        }

        $named = NamedRange::create([
            'spreadsheet_id' => $spreadsheetId,
            'sheet_tab_id' => $tabId,
            'name' => $name,
            'start_row' => $area['r1'],
            'start_col' => $area['c1'],
            'end_row' => $area['r2'],
            'end_col' => $area['c2'],
        ]);
        $this->changed($spreadsheetId, $tabId);

        return $named;
    }

    public function remove(NamedRange $range): void
    {
        $range->delete();
        $this->changed((int) $range->spreadsheet_id, (int) $range->sheet_tab_id);
    }

    /** How many formulas on its sheet use a name. */
    public function usedBy(NamedRange $range): int
    {
        return DB::table('cells')->where('sheet_tab_id', $range->sheet_tab_id)->whereNotNull('formula')->where('formula', 'like', "%{$range->name}%")
            ->pluck('formula')
            ->filter(fn (string $formula) => preg_match('/(?<![A-Za-z0-9_.])'.preg_quote($range->name, '/').'(?![A-Za-z0-9_(])/i', $formula) === 1)
            ->count();
    }

    private function isReserved(string $name): bool
    {
        $upper = strtoupper($name);

        return in_array($upper, self::RESERVED_WORDS, true) || in_array($upper, array_column(FunctionCatalogue::all(), 'name'), true);
    }

    private function changed(int $spreadsheetId, int $tabId): void
    {
        Cache::forget("spreadsheet:{$spreadsheetId}:tab:{$tabId}:named_ranges");
        Cache::forget("spreadsheet:{$spreadsheetId}:tab:{$tabId}:formula_dependency_graph");

        // A formula using the name now reads a different range, or none: work the sheet out again.
        app(Recalculator::class)->markStale($spreadsheetId, $tabId);
    }
}
