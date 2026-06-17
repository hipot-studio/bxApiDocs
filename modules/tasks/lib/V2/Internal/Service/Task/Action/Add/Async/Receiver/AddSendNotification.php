<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Task\Action\Add\Async\Receiver;

use Bitrix\Main\Messenger\Entity\MessageInterface;
use Bitrix\Main\Messenger\Receiver\AbstractReceiver;
use Bitrix\Tasks\Internals\Registry\TaskRegistry;
use Bitrix\Tasks\V2\Internal\DI\Container;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Add\Async\Message;

class AddSendNotification extends AbstractReceiver
{
	protected function process(MessageInterface $message): void
	{
		if (!$message instanceof Message\AddSendNotification)
		{
			return;
		}

		$task = TaskRegistry::getInstance()
			->drop($message->taskId)
			->getObject($message->taskId, true)
		;

		if ($task === null)
		{
			return;
		}

		$controller = Container::getInstance()->getNotificationController();
		$controller->onTaskCreated(
			task: $task,
			params: $message->config,
		);
		$controller->push();
	}
}
