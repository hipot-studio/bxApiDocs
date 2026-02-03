<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\Tasks\Service;

use Bitrix\Im\V2\Integration\AI\TaskCreation\Status;
use Bitrix\Im\V2\Link\Task\TaskType;
use Bitrix\Im\V2\Message;
use Bitrix\Im\V2\Pull\Event\AutoTaskStatus;
use Bitrix\Im\V2\Service\Locator;
use Bitrix\Main\Application;
use Bitrix\Main\Loader;
use Bitrix\Tasks\Internals\Registry\TaskRegistry;

class ChatTaskLinkService
{
	public function linkFromMessage(
		int $taskId,
		Message $message,
		int $userId,
		TaskType $taskType = TaskType::Task,
	): void
	{
		$chatId = $message->getChatId();
		$messageId = $message->getMessageId();

		if ($taskId <= 0 || $chatId <= 0 || $messageId <= 0)
		{
			return;
		}

		if (!Loader::includeModule('tasks'))
		{
			return;
		}

		(new AutoTaskStatus($message, Status::TaskCreationCompleted, true))->send();

		Application::getInstance()->addBackgroundJob(
			static function() use ($chatId, $messageId, $taskId, $userId, $taskType)
			{
				$task = TaskRegistry::getInstance()->drop($taskId)->getObject($taskId, true);
				if ($task === null)
				{
					return;
				}

				Locator::getMessenger()
					->withContextUser($userId)
					->registerTask($chatId, $messageId, $task, $taskType)
				;
			},
		);
	}
}
