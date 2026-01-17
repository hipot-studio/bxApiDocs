<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Task;

use Bitrix\Main\DB\DuplicateEntryException;
use Bitrix\Tasks\Scrum\Service\SprintService;
use Bitrix\Tasks\V2\Internal\Entity\StageCollection;
use Bitrix\Tasks\V2\Internal\Repository\GroupRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Repository\StageRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Repository\TaskStageRepositoryInterface;

class TaskStageService
{
	private readonly SprintService $sprintService;
	public function __construct(
		private readonly TaskStageRepositoryInterface $taskStageRepository,
		private readonly GroupRepositoryInterface $groupRepository,
		private readonly StageRepositoryInterface $stageRepository,
	)
	{
		// for now DI cannot resolve default values in constructor
		$this->sprintService = new SprintService();
	}

	public function getStagesByGroupId(int $groupId): StageCollection
	{
		$isScrum = $this->groupRepository->getType($groupId) === 'scrum';
		if ($isScrum)
		{
			return $this->sprintService->getActiveSprintStages($groupId);
		}

		return $this->stageRepository->getByGroupId($groupId);
	}

	public function move(int $id, int $stageId): void
	{
		try
		{
			$this->taskStageRepository->update($id, $stageId);
		}
		catch (DuplicateEntryException)
		{
			$this->taskStageRepository->deleteById($id);
		}
	}

	public function upsert(int $taskId, int $stageId): int
	{
		return $this->taskStageRepository->upsert($taskId, $stageId);
	}

	public function clearRelations(array $relationIds): void
	{
		if (empty($relationIds))
		{
			return;
		}

		$this->taskStageRepository->deleteById(...$relationIds);
	}

	public function clearStage(int $stageId): void
	{
		$this->taskStageRepository->deleteByStageId($stageId);
	}
}
