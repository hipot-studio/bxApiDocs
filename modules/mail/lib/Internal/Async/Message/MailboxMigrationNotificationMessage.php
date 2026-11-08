<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Async\Message;

use Bitrix\Main\Messenger\Entity\AbstractMessage;

final class MailboxMigrationNotificationMessage extends AbstractMessage
{
	public function __construct(
		public readonly int $mailboxId,
		public readonly string $status,
		/** @var int[] */
		public readonly array $userIds = [],
		public readonly ?string $operationId = null,
	)
	{
	}
}
