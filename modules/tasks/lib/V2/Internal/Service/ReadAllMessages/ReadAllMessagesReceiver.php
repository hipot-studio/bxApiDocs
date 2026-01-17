<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\ReadAllMessages;

use Bitrix\Main\Messenger\Entity\MessageInterface;
use Bitrix\Main\Messenger\Receiver\AbstractReceiver;
use Bitrix\Tasks\V2\Internal\DI\Container;

class ReadAllMessagesReceiver extends AbstractReceiver
{
	protected function process(MessageInterface $message): void
	{
		if (!$message instanceof ReadAllMessagesMessage)
		{
			return;
		}

		Container::getInstance()->get(ReadAllMessagesService::class)->execute($message->userId, $message->chatIds);
	}
}
