<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Async\Message;

use Bitrix\Main\Messenger\Entity\AbstractMessage;

/**
 * Carries only the identifiers of the letter. Everything the answer is decided by - the setting of the
 * mailbox with its period, the folder the letter lies in, the senders already answered - is read again
 * when the job is processed: any of it may change while the job waits, and a copy taken now would decide
 * the answer by a state that is already gone.
 */
class VacationAutoReplyMessage extends AbstractMessage
{
	public function __construct(
		public readonly int $mailboxId,
		public readonly int $messageId,
	)
	{
	}
}
