<?php

namespace App\Services;

use App\Models\Cell;
use Illuminate\Support\Facades\Cache;

class FormulaEvaluatorService
{
    protected $cellCache = [];

    protected $evaluationStack = [];

    protected $dependencyGraph = [];

    protected $cacheTtlSeconds = 300;

    public function __construct()
    {
        $this->cellCache = [];
    }

    /**
     * Evaluate a formula
     *
     * @param  string  $formula  The formula string (e.g., "=A1+B1")
     * @param  int  $spreadsheetId  The spreadsheet ID for context
     * @return array ['value' => result, 'error' => bool, 'errorMessage' => string]
     */
    public function evaluate($formula, $spreadsheetId)
    {
        try {
            if (! $formula || ! is_string($formula)) {
                return ['value' => 0, 'error' => false];
            }

            // Remove leading '='
            if (str_starts_with($formula, '=')) {
                $formula = substr($formula, 1);
            }

            // Cache cell data for this spreadsheet
            $this->cacheSpreadsheetCells($spreadsheetId);

            // Build and cache dependency graph for recalculation planning
            $this->dependencyGraph = $this->getDependencyGraph($spreadsheetId);

            // Evaluate the expression
            $result = $this->evaluateExpression($formula);

            return ['value' => $result, 'error' => false];
        } catch (\Exception $e) {
            return ['value' => 0, 'error' => true, 'errorMessage' => $e->getMessage()];
        }
    }

    /**
     * Cache all cells for a spreadsheet
     */
    protected function cacheSpreadsheetCells($spreadsheetId)
    {
        $cells = Cache::remember(
            "spreadsheet:{$spreadsheetId}:cell_values",
            $this->cacheTtlSeconds,
            fn () => Cell::where('spreadsheet_id', $spreadsheetId)
                ->get(['row_index', 'col_index', 'raw_value', 'computed_value'])
                ->toArray()
        );

        foreach ($cells as $cell) {
            $row = (int) ($cell['row_index'] ?? 0);
            $col = (int) ($cell['col_index'] ?? 0);
            $key = "{$row}_{$col}";
            $this->cellCache[$key] = [
                'raw_value' => $cell['raw_value'] ?? null,
                'computed_value' => $cell['computed_value'] ?? null,
            ];
        }
    }

    /**
     * Build and cache dependency graph for formula recalculation.
     * Returns map: dependencyCellKey => [dependentCellKey, ...]
     */
    protected function getDependencyGraph($spreadsheetId): array
    {
        return Cache::remember(
            "spreadsheet:{$spreadsheetId}:formula_dependency_graph",
            $this->cacheTtlSeconds,
            function () use ($spreadsheetId) {
                $graph = [];

                $formulaCells = Cell::where('spreadsheet_id', $spreadsheetId)
                    ->whereNotNull('formula')
                    ->get(['row_index', 'col_index', 'formula']);

                foreach ($formulaCells as $cell) {
                    $dependentKey = "{$cell->row_index}_{$cell->col_index}";
                    $refs = $this->extractFormulaReferences((string) $cell->formula);

                    foreach ($refs as $refKey) {
                        if (! isset($graph[$refKey])) {
                            $graph[$refKey] = [];
                        }
                        if (! in_array($dependentKey, $graph[$refKey], true)) {
                            $graph[$refKey][] = $dependentKey;
                        }
                    }
                }

                return $graph;
            }
        );
    }

    /**
     * Extract referenced cell keys (row_col) from formula text.
     */
    protected function extractFormulaReferences(string $formula): array
    {
        $refs = [];
        $expr = ltrim($formula, '=');

        // Single cell references: A1, BC23, etc.
        if (preg_match_all('/\b([A-Z]{1,3}\d+)\b/i', $expr, $matches)) {
            foreach ($matches[1] as $cellRef) {
                $refs[] = $this->cellRefToKey(strtoupper($cellRef));
            }
        }

        // Range references: A1:B5
        if (preg_match_all('/\b([A-Z]{1,3}\d+):([A-Z]{1,3}\d+)\b/i', $expr, $rangeMatches, PREG_SET_ORDER)) {
            foreach ($rangeMatches as $rangeMatch) {
                $refs = array_merge($refs, $this->expandRangeToKeys(strtoupper($rangeMatch[1]), strtoupper($rangeMatch[2])));
            }
        }

        return array_values(array_unique(array_filter($refs)));
    }

    protected function cellRefToKey(string $cellRef): ?string
    {
        if (! preg_match('/^([A-Z]{1,3})(\d+)$/', $cellRef, $m)) {
            return null;
        }

        $col = $this->colLetterToIndex($m[1]);
        $row = (int) $m[2] - 1;

        return "{$row}_{$col}";
    }

    /**
     * Expand a range into row_col keys with a safe upper bound.
     */
    protected function expandRangeToKeys(string $startRef, string $endRef): array
    {
        $startKey = $this->cellRefToKey($startRef);
        $endKey = $this->cellRefToKey($endRef);
        if (! $startKey || ! $endKey) {
            return [];
        }

        [$startRow, $startCol] = array_map('intval', explode('_', $startKey));
        [$endRow, $endCol] = array_map('intval', explode('_', $endKey));

        $minRow = min($startRow, $endRow);
        $maxRow = max($startRow, $endRow);
        $minCol = min($startCol, $endCol);
        $maxCol = max($startCol, $endCol);

        // Keep expansion bounded to avoid expensive graph builds on huge ranges.
        $maxCells = 5000;
        $keys = [];

        for ($r = $minRow; $r <= $maxRow; $r++) {
            for ($c = $minCol; $c <= $maxCol; $c++) {
                $keys[] = "{$r}_{$c}";
                if (count($keys) >= $maxCells) {
                    return $keys;
                }
            }
        }

        return $keys;
    }

    /**
     * Evaluate expression recursively
     */
    protected function evaluateExpression($expr)
    {
        $expr = trim($expr);

        // Handle simple values
        if (is_numeric($expr)) {
            return (float) $expr;
        }

        if (strtoupper($expr) === 'TRUE') {
            return 1;
        }

        if (strtoupper($expr) === 'FALSE') {
            return 0;
        }

        // Handle cell references
        if (preg_match('/^[A-Z]{1,3}\d+$/i', $expr)) {
            return $this->getCellValue($expr);
        }

        // Handle functions
        if (preg_match('/^([A-Z]+)\((.*)\)$/i', $expr, $matches)) {
            $funcName = strtoupper($matches[1]);
            $args = $this->parseFunctionArgs($matches[2]);

            return match ($funcName) {
                'SUM' => array_sum(array_map(fn ($a) => $this->evaluateExpression($a), $args)),
                'AVERAGE', 'AVG' => array_sum(array_map(fn ($a) => $this->evaluateExpression($a), $args)) / count($args),
                'COUNT' => count($args),
                'MAX' => max(array_map(fn ($a) => $this->evaluateExpression($a), $args)),
                'MIN' => min(array_map(fn ($a) => $this->evaluateExpression($a), $args)),
                'ROUND' => round($this->evaluateExpression($args[0]), $args[1] ?? 0),
                'ABS' => abs($this->evaluateExpression($args[0])),
                'IF' => $this->evaluateExpression($args[0]) ? $this->evaluateExpression($args[1]) : $this->evaluateExpression($args[2]),
                'CONCAT' => implode('', $args),
                'UPPER' => strtoupper(implode('', $args)),
                'LOWER' => strtolower(implode('', $args)),
                'LEN' => strlen(implode('', $args)),
                default => 0,
            };
        }

        // For complex expressions, return 0 (will be enhanced in future)
        return 0;
    }

    /**
     * Get cell value
     */
    protected function getCellValue($cellRef)
    {
        $cellRef = strtoupper($cellRef);
        preg_match('/^([A-Z]{1,3})(\d+)$/', $cellRef, $matches);

        if (! $matches) {
            return 0;
        }

        $col = $this->colLetterToIndex($matches[1]);
        $row = (int) $matches[2] - 1;
        $key = "{$row}_{$col}";

        if (isset($this->cellCache[$key])) {
            $value = $this->cellCache[$key]['computed_value'] ?? $this->cellCache[$key]['raw_value'];

            return is_numeric($value) ? (float) $value : 0;
        }

        return 0;
    }

    /**
     * Convert column letter to index
     */
    protected function colLetterToIndex($letter)
    {
        $index = 0;
        for ($i = 0; $i < strlen($letter); $i++) {
            $index = $index * 26 + (ord($letter[$i]) - 64);
        }

        return $index - 1;
    }

    /**
     * Parse function arguments
     */
    protected function parseFunctionArgs($argString)
    {
        $args = [];
        $current = '';
        $depth = 0;

        for ($i = 0; $i < strlen($argString); $i++) {
            $char = $argString[$i];

            if ($char === '(' || $char === '[') {
                $depth++;
                $current .= $char;
            } elseif ($char === ')' || $char === ']') {
                $depth--;
                $current .= $char;
            } elseif ($char === ',' && $depth === 0) {
                $args[] = trim($current);
                $current = '';
            } else {
                $current .= $char;
            }
        }

        if ($current) {
            $args[] = trim($current);
        }

        return $args;
    }
}
