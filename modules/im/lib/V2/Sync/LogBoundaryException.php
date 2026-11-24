<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Sync;

use Bitrix\Main\SystemException;

/**
 * Thrown when the log retention boundary (log_incomplete_before) cannot be
 * read, is in an unknown state, or cannot be raised. Never swallowed into
 * "no gap" - see LogBoundary.
 */
class LogBoundaryException extends SystemException
{
}
