<?php

namespace App\Services\Integrations;

use RuntimeException;

/** Dot.Files did not take the call. The message says why, in words for the person waiting. */
class FilesRefused extends RuntimeException
{
    public function __construct(string $message, public readonly ?int $status = null)
    {
        parent::__construct($message);
    }
}
