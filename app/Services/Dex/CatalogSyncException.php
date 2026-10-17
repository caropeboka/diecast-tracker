<?php

namespace App\Services\Dex;

use RuntimeException;

/**
 * A sync problem worth telling the user about (wiki down, year missing). Anything else is a bug and
 * should surface as one — not be shown as a friendly message.
 */
class CatalogSyncException extends RuntimeException {}
