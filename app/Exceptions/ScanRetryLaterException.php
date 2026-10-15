<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The AI provider can't take the request right now (rate limit, free quota used up, or overloaded).
 * Nothing is wrong with the photo; callers may wait and retry.
 */
class ScanRetryLaterException extends RuntimeException
{
    public function __construct(string $message, public readonly int $retryAfter = 60)
    {
        parent::__construct($message);
    }
}
