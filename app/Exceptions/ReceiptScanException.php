<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a receipt image can't be scanned (missing categories, upstream
 * Gemini failure, unparseable response). Caught in the controller and rendered
 * via the standard error envelope with the given status.
 */
class ReceiptScanException extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 502)
    {
        parent::__construct($message);
    }
}
