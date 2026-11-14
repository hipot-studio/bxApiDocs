<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\Tasks\Service\Transcription;

/**
 * Outcome of one extracted task. "Created or not" is no longer enough: a task can also be held
 * back by the duplicate check, and the caller must not report a terminal status for it - the
 * verdict or the initiator decides later.
 */
enum TaskCreationOutcome
{
	case Created;
	case Deferred;
	case Failed;
}
