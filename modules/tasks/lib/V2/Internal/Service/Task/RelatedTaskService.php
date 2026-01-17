<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Task;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\Type\Collection;
use Bitrix\Tasks\V2\Internal\Entity\HistoryLog;
use Bitrix\Tasks\V2\Internal\Entity\Task;
use Bitrix\Tasks\V2\Internal\Entity\User;
use Bitrix\Tasks\V2\Internal\Repository\RelatedTaskRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Update\Config\UpdateConfig;
use Bitrix\Tasks\V2\Internal\Service\UpdateTaskService;

class RelatedTaskService
{
	public function __construct(
		private readonly RelatedTaskRepositoryInterface $relatedTaskRepository,
		private readonly UpdateTaskService $updateService,
		private readonly HistoryService $historyService,
	)
	{
	}

	public function add(int $taskId, array $relatedTaskIds, int $userId): Task
	{
		Collection::normalizeArrayValuesByInt($relatedTaskIds, false);

		if (in_array($taskId, $relatedTaskIds, true))
		{
			foreach ($relatedTaskIds as $i => $relatedTaskId)
			{
				if ($relatedTaskId === $taskId)
				{
					unset($relatedTaskIds[$i]);
				}
			}
		}

		if (empty($relatedTaskIds))
		{
			return new Task(id: $taskId);
		}

		$previous = $this->relatedTaskRepository->getRelatedTaskIds($taskId);

		$this->relatedTaskRepository->save($taskId, $relatedTaskIds);

		$new = array_unique(array_merge($previous, $relatedTaskIds));

		$this->updateHistory(
			taskId: $taskId,
			userId: $userId,
			previous: $previous,
			new: $new,
		);

		return $this->updateChanges(
			taskId: $taskId,
			userId: $userId,
		)->cloneWith(['dependsOn' => $new]);
	}

	public function delete(int $taskId, array $relatedTaskIds, int $userId): Task
	{
		Collection::normalizeArrayValuesByInt($relatedTaskIds, false);
		if (empty($relatedTaskIds))
		{
			throw new ArgumentException('Empty related task IDs array provided.', 'relatedTaskIds');
		}

		$previous = $this->relatedTaskRepository->getRelatedTaskIds($taskId);

		$this->relatedTaskRepository->deleteByRelatedTaskIds($taskId, $relatedTaskIds);

		$new =  array_unique(array_diff($previous, $relatedTaskIds));

		$this->updateHistory(
			taskId: $taskId,
			userId: $userId,
			previous: $previous,
			new: $new,
		);

		return $this->updateChanges(
			taskId: $taskId,
			userId: $userId,
		)->cloneWith(['dependsOn' => $new]);
	}

	public function set(int $taskId, array $relatedTaskIds, int $userId): Task
	{
		Collection::normalizeArrayValuesByInt($relatedTaskIds, false);

		if (in_array($taskId, $relatedTaskIds, true))
		{
			foreach ($relatedTaskIds as $i => $relatedTaskId)
			{
				if ($relatedTaskId === $taskId)
				{
					unset($relatedTaskIds[$i]);
				}
			}
		}

		if (empty($relatedTaskIds))
		{
			return new Task(id: $taskId);
		}

		$previous = $this->relatedTaskRepository->getRelatedTaskIds($taskId);

		$this->relatedTaskRepository->deleteByTaskId($taskId);

		$this->relatedTaskRepository->save($taskId, $relatedTaskIds);

		$new = array_unique($relatedTaskIds);

		$this->updateHistory(
			taskId: $taskId,
			userId: $userId,
			previous: $previous,
			new: $new,
		);

		return $this->updateChanges(
			taskId: $taskId,
			userId: $userId,
		)->cloneWith(['dependsOn' => $new]);
	}

	private function updateHistory(int $taskId, int $userId, array $previous, array $new): void
	{
		$log = new HistoryLog(
			createdDateTs: time(),
			userId: $userId,
			taskId: $taskId,
			field: 'DEPENDS_ON',
			fromValue: implode(',', $previous),
			toValue: implode(',',  $new),
		);

		$this->historyService->add($log);
	}

	private function updateChanges(int $taskId, int $userId): Task
	{
		$task = new Task(
			id: $taskId,
			changedTs: time(),
			changedBy: new User(id: $userId),
		);

		return $this->updateService->update(
			task: $task,
			config: new UpdateConfig($userId),
		);
	}
}
