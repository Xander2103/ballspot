<?php

namespace App\Services;

/**
 * Raised by DailyHistoryClearService with a stage (backup_failed |
 * delete_failed) and a user-safe message that contains no paths, SQL or
 * exception text.
 */
class DailyHistoryClearException extends \RuntimeException
{
    public function __construct(
        public readonly string $stage,
        string $friendlyMessage,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($friendlyMessage, 0, $previous);
    }
}
