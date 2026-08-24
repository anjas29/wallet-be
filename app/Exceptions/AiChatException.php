<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Mirrors ReceiptScanException: carries the HTTP status the controller should surface.
 *
 * Note the two failure windows for a streamed chat — before the SSE headers are flushed this
 * maps onto the normal ApiResponse error envelope; after, the controller can only emit an
 * `error` event, and $status is used for logging rather than the response code.
 */
class AiChatException extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 502)
    {
        parent::__construct($message);
    }
}
