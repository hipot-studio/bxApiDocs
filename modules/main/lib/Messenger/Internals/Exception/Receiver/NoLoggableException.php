<?php

declare(strict_types=1);

namespace Bitrix\Main\Messenger\Internals\Exception\Receiver;

use Bitrix\Main\Messenger\Internals\Exception\RuntimeException;

/**
 * Exceptions that should not be logged during message processing.
 *
 * When a message handler throws this exception, the receiver will skip writing it to the log.
 * This is useful for expected transient errors (e.g. an external service returned 5xx)
 * where the message should be retried (with ttl decrease) but the error does not need to be recorded.
 */
class NoLoggableException extends RuntimeException
{
}
