<?php

namespace App\Services;

use RuntimeException;

/**
 * Thrown when a sheet is larger than an Excel file can be built from within the host's memory
 * limit, by stored cells or by the rows x columns grid the writer has to lay out (one cell at
 * CV60001 is one stored cell but a six million cell grid). The CSV export has no such limit.
 */
class ExportTooLargeException extends RuntimeException
{
    public static function forCells(int $cellCount, int $limit): self
    {
        return new self(sprintf(
            'This sheet has %s cells and an Excel file can hold at most %s here. Export it as CSV instead: it has no size limit.',
            number_format($cellCount),
            number_format($limit),
        ));
    }

    public static function forGrid(int $rows, int $cols, int $limit): self
    {
        return new self(sprintf(
            'This sheet reaches row %s and column %s, which is a grid of %s cells, and an Excel file can hold at most %s here. Export it as CSV instead: it has no size limit.',
            number_format($rows),
            number_format($cols),
            number_format($rows * $cols),
            number_format($limit),
        ));
    }
}
