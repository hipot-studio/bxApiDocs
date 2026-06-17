<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Task\Action\Update\Async\Receiver;

use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Messenger\Entity\MessageInterface;
use Bitrix\Main\Messenger\Receiver\AbstractReceiver;
use Bitrix\Tasks\Internals\Registry\TaskRegistry;
use Bitrix\Tasks\V2\Internal\DI\Container;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Update\Async\Message;

class UpdateSendNotification extends AbstractReceiver
{
	protected function process(MessageInterface $message): void
	{
		if (!$message instanceof Message\UpdateSendNotification)
		{
			return;
		}

		$accessibleChangedFields = $message->unSerialiseDateTime(
			payload: $message->accessibleChangedFields,
			dateTimeKeys: ['DEADLINE', 'START_DATE_PLAN', 'END_DATE_PLAN'],
		);

		$task = TaskRegistry::getInstance()
			->drop($message->taskId)
			->getObject($message->taskId, true)
		;

		if ($task === null)
		{
			return;
		}

		$controller = Container::getInstance()->getNotificationController();

		Loc::loadMessages($_SERVER['DOCUMENT_ROOT'].BX_ROOT.'/modules/tasks/classes/general/tasknotifications.php');

		$controller->onTaskUpdated(
			task: $task,
			newFields: $accessibleChangedFields,
			previousFields: $message->previousFields,
			params: $message->config,
		);

		$controller->push();
	}
}
