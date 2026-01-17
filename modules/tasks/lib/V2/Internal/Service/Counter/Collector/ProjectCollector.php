<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Counter\Collector;

use Bitrix\Tasks\Internals\Counter;
use Bitrix\Tasks\Internals\Counter\CounterDictionary;
use Bitrix\Tasks\V2\Internal\DI\Container;
use Bitrix\Tasks\V2\Internal\Integration\Im\Service\ImMessageCounterServiceDelegate;

class ProjectCollector extends Counter\Collector\ProjectCollector
{
	/** @param array $groupIds Deprecated, list of group IDs will be retrieved from Tasks. */
	protected function recountComments(array $groupIds = [], array $taskIds = [], array $userIds = []): array
	{
		$chatIds = Container::getInstance()->getChatRepository()->findChatIdsByTaskIds($taskIds);
		$groupIds = Container::getInstance()->getGroupRepository()->getGroupIdsByTaskIds($taskIds);
		$counterService = new ImMessageCounterServiceDelegate();
		$counters = [];

		foreach ($chatIds as $taskId => $chatId)
		{
			$rows = $counterService->getByChatForEachUsers($chatId, $userIds);
			foreach ($rows as $userId => $count)
			{
				if ($count <= 0)
				{
					continue;
				}

				$counters[] = [
					'USER_ID' => (int)$userId,
					'TASK_ID' => (int)$taskId,
					'GROUP_ID' => array_key_exists($taskId, $groupIds) ? (int)$groupIds[$taskId] : 0,
					'TYPE' => CounterDictionary::COUNTER_GROUP_COMMENTS,
					'VALUE' => (int)$count,
				];
			}
		}

		return $counters;
	}
}
