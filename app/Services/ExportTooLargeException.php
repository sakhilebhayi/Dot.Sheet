<?php

namespace App\Services;

use RuntimeException;

/**
 * Thrown when a sheet has more cells than an Excel file can be built from within the host's
 * memory limit. The CSV export has no such limit.
 */
class ExportTooLargeException extends RuntimeException
{
    public function __construct(public readonly int $cellCount, public readonly int $limit)
    {
        parent::__construct(sprintf(
            'This sheet has %s cells and an Excel file can hold at most %s here. Export it as CSV instead: it has no size limit.',
            number_format($cellCount),
            number_format($limit),
        ));
    }
}
