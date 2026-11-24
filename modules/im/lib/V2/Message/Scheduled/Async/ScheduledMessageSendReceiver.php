<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Message\Scheduled\Async;

use Bitrix\Im\V2\Message\Scheduled\ScheduledMessageService;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Messenger\Internals\Exception\Receiver\UnprocessableMessageException;
use Bitrix\Main\Messenger\Receiver\AbstractReceiver;
use Bitrix\Main\Messenger\Entity\MessageInterface;

class ScheduledMessageSendReceiver extends AbstractReceiver
{
	protected function process(MessageInterface $message): void
	{
		if (!$message instanceof ScheduledMessageSendMessage)
		{
			throw new UnprocessableMessageException($message);
		}

		ServiceLocator::getInstance()
			->get(ScheduledMessageService::class)
			->sendByScheduler($message->scheduledMessageId)
		;
	}
}
