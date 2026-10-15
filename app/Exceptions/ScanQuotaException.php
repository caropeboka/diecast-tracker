<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The AI provider's rate limit or (free) quota was hit. Callers may wait and retry.
 */
class ScanQuotaException extends RuntimeException {}
