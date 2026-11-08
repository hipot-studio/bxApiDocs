<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Async\Receiver;

use Bitrix\Mail\Helper\Config\Feature;
use Bitrix\Mail\Integration\Im\Notification;
use Bitrix\Mail\Internal\Async\Message\ScheduledMessageFailedMessage;
use Bitrix\Main\Loader;
use Bitrix\Main\Messenger\Entity\MessageInterface;
use Bitrix\Main\Messenger\Internals\Exception\Receiver\RecoverableMessageException;
use Bitrix\Main\Messenger\Internals\Exception\Receiver\UnprocessableMessageException;
use Bitrix\Main\Messenger\Receiver\AbstractReceiver;

class ScheduledMessageFailedReceiver extends AbstractReceiver
{
	protected function process(MessageInterface $message): void
	{
		if (!$message instanceof ScheduledMessageFailedMessage)
		{
			throw new UnprocessableMessageException($message);
		}

		if (!$this->isAvailable())
		{
			throw new RecoverableMessageException('Scheduled message notifications are temporarily unavailable.');
		}

		$this->notify($message);
	}

	protected function isAvailable(): bool
	{
		return Feature::isScheduledMessageAvailable() && Loader::includeModule('im');
	}

	protected function notify(ScheduledMessageFailedMessage $message): void
	{
		Notification::notifyUserAboutScheduledMessageFailed(
			$message->authorId,
			$message->scheduledMessageId,
			$message->subject,
			$message->errorCode,
			$message->viewUrl,
		);
	}
}
