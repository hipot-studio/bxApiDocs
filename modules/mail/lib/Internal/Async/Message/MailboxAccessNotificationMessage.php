<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Async\Message;

use Bitrix\Main\Messenger\Entity\AbstractMessage;

class MailboxAccessNotificationMessage extends AbstractMessage
{
	public function __construct(
		public readonly int $mailboxId,
		public readonly string $mailboxEmail,
		public readonly array $previousAccessCodes,
		public readonly array $currentAccessCodes,
		public readonly int $editorUserId,
		public readonly int $mailboxOwnerId = 0,
	)
	{
	}
}
