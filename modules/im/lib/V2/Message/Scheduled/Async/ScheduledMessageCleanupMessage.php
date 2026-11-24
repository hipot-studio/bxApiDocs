<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Message\Scheduled\Async;

use Bitrix\Im\V2\Async\QueueId;
use Bitrix\Main\Messenger\Entity\AbstractMessage;

class ScheduledMessageCleanupMessage extends AbstractMessage
{
	public function __construct(
		public readonly int $scheduledMessageId
	)
	{
	}

	public function sendToQueue(): void
	{
		$this->send(QueueId::ScheduledMessageCleanup->value);
	}
}
