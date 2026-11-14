<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\Tasks\Service\Transcription;

use Bitrix\Im\V2\Message;
use Bitrix\Im\V2\MessageCollection;

/**
 * Creation of a task the duplicate check held back.
 *
 * The check and the handler need each other, so the check depends on this contract instead of the
 * handler: the cycle is gone from the type graph, and the creation stays replaceable in tests.
 */
interface DeferredTaskCreator
{
	/**
	 * The verdict has already had its say, so the check is not asked again and the terminal status
	 * is reported once for the whole batch by whoever resolves it.
	 */
	public function createDeferred(
		array $taskData,
		Message $message,
		string $transcribedText,
		int $initiatorId,
		?MessageCollection $messagesToForward = null,
		bool $builtWithoutVerdict = false,
		bool $isPartial = false,
	): TaskCreationOutcome;
}
