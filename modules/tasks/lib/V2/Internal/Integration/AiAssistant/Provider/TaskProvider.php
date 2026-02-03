<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Provider;

use Bitrix\Tasks\Provider\Exception\TaskListException;
use Bitrix\Tasks\Provider\TaskList;
use Bitrix\Tasks\V2\Internal\Access\Service\TaskAccessService;
use Bitrix\Tasks\V2\Internal\Entity\CheckList;
use Bitrix\Tasks\V2\Internal\Entity\CheckList\CheckListItem;
use Bitrix\Tasks\V2\Internal\Entity\CheckList\Type;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Exception\AccessDeniedException;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Exception\InvalidIdentifierException;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Provider\Mapper\TaskResponseMapper;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Provider\Task\QueryBuilder;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Dto\Task\GetTaskByIdDto;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Dto\Task\SearchTasksDto;
use Bitrix\Tasks\V2\Internal\Repository\CheckListRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Repository\TaskRepositoryInterface;

class TaskProvider
{
	public function __construct(
		private readonly TaskAccessService $accessService,
		private readonly TaskResponseMapper $taskResponseMapper,
		private readonly TaskRepositoryInterface $taskRepository,
		private readonly TaskList $taskProvider,
		private readonly CheckListRepositoryInterface $checkListRepository,
		private readonly QueryBuilder $queryBuilder,
	)
	{
	}

	/**
	 * @throws AccessDeniedException
	 * @throws InvalidIdentifierException
	 */
	public function getById(GetTaskByIdDto $dto, int $userId): ?array
	{
		if ($dto->taskId <= 0)
		{
			throw new InvalidIdentifierException();
		}

		if (!$this->accessService->canRead($userId, $dto->taskId))
		{
			throw new AccessDeniedException();
		}

		$task = $this->taskRepository->getById($dto->taskId);
		if ($task === null)
		{
			return null;
		}

		$checkList = $this->checkListRepository->getByEntity((int)$task->getId(), Type::Task);

		return $this->taskResponseMapper->mapFromEntity($task, $checkList, $userId);
	}

	/**
	 * @throws TaskListException
	 */
	public function getList(SearchTasksDto $dto, int $userId): array
	{
		$query = $this->queryBuilder->build($dto, $userId);

		$tasks = $this->taskProvider->getList($query);
		if (empty($tasks))
		{
			return [];
		}

		$taskIds = array_column($tasks, 'ID');

		$checkListsByTaskId = $this->getCheckListsByTaskIds($taskIds);

		$taskResponses = [];

		foreach ($tasks as $task)
		{
			$taskId = (int)($task['ID'] ?? 0);

			$checkList = $checkListsByTaskId[$taskId] ?? new CheckList();

			$taskResponses[] = $this->taskResponseMapper->mapFromArray($task, $checkList, $userId);
		}

		return $taskResponses;
	}

	/**
	 * @param int[] $taskIds
	 *
	 * @return array<int, CheckList>
	 */
	private function getCheckListsByTaskIds(array $taskIds): array
	{
		if (empty($taskIds))
		{
			return [];
		}

		$checkLists = $this->checkListRepository->getByEntities($taskIds, Type::Task);

		$checkListsByTaskId = [];

		/** @var CheckListItem $checkListItem */
		foreach ($checkLists as $checkListItem)
		{
			$taskId = (int)$checkListItem->entityId;

			if (!isset($checkListsByTaskId[$taskId]))
			{
				$checkListsByTaskId[$taskId] = new CheckList();
			}

			$checkListsByTaskId[$taskId]->add($checkListItem);
		}

		return $checkListsByTaskId;
	}
}
