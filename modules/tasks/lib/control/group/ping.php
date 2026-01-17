<?php

namespace Bitrix\Tasks\Control\Group;

use Bitrix\Tasks\Internals\Registry\TaskRegistry;
use Bitrix\Tasks\Internals\Notification\Controller;
use Bitrix\Tasks\V2\Internal\DI\Container;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Ping\PingActionInterface;

class Ping
{
	public function runBatch(int $userId, array $taskIds): void
	{
		$registry = TaskRegistry::getInstance();
		$registry->load($taskIds, true);

		foreach ($taskIds as $id)
		{
			$task = $registry->getObject($id, true);

			if (!$task)
			{
				continue;
			}

			$taskData = $task->toArray(true);

			Container::getInstance()->get(PingActionInterface::class)->execute($id, $userId, $taskData);

			$controller = new Controller();
			$controller->onTaskPingSend($task, $userId);
			$controller->push();
		}
	}
}
