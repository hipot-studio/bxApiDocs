<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Grid\History\Service;

use Bitrix\Main\Type\Collection;
use Bitrix\Tasks\Provider\Query\TaskQuery;
use Bitrix\Tasks\Provider\TaskList;
use Bitrix\Tasks\Slider\Path\TaskPathMaker;

class RelatedTaskService
{
	public function __construct(
		private readonly TaskList $taskList,
	)
	{

	}

	public function fillRelatedTasks(array $tasks, array $taskIds, int $userId): ?array
	{
		Collection::normalizeArrayValuesByInt($taskIds, false);

		if (empty($taskIds) || $userId <= 0)
		{
			return null;
		}

		$preparedTasks = [];
		foreach ($taskIds as $taskId)
		{
			if (!isset($tasks[$taskId]))
			{
				continue;
			}

			$relatedTask = $tasks[$taskId];
			$groupId = (int)($relatedTask['GROUP_ID'] ?? 0);

			$preparedTasks[] = [
				'title' => (string)($relatedTask['TITLE'] ?? ''),
				'link' => TaskPathMaker::getPath([
					'user_id' => $userId,
					'group_id' => $groupId,
					'action' => 'view',
					'task_id' => $taskId,
				]),
			];
		}

		return $preparedTasks;
	}

	public function getRelatedTasks(array $taskIds, int $userId): array
	{
		if (empty($taskIds))
		{
			return [];
		}

		$query =
			(new TaskQuery($userId))
				->setSelect([
					'ID',
					'TITLE',
					'GROUP_ID'
				])
				->setWhere([
					'ID' => $taskIds,
				])
		;

		return $this->taskList->getList($query);
	}
}
