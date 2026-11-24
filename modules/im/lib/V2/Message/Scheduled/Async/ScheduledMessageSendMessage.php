<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Message\Scheduled\Async;

use Bitrix\Im\V2\Async\QueueId;
use Bitrix\Main\Messenger\Entity\AbstractMessage;
use Bitrix\Main\Messenger\Entity\ProcessingParam\DelayParam;
use Bitrix\Main\Type\DateTime;

class ScheduledMessageSendMessage extends AbstractMessage
{
	public function __construct(
		public readonly int $scheduledMessageId
	)
	{
	}

	public function sendToQueueAt(DateTime $sendAt): void
	{
		$this->send(QueueId::ScheduledMessageSend->value, [DelayParam::delayUntil($sendAt)]);
	}
}
