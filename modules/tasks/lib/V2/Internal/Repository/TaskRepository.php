<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository;

use Bitrix\Main\Application;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\Type\Collection;
use Bitrix\Main\Type\DateTime;
use Bitrix\Tasks\Control\Exception\TaskAddException;
use Bitrix\Tasks\Control\Exception\TaskNotExistsException;
use Bitrix\Tasks\Control\Exception\TaskNotFoundException;
use Bitrix\Tasks\Control\Exception\TaskStopDeleteException;
use Bitrix\Tasks\Control\Exception\TaskUpdateException;
use Bitrix\Tasks\Control\Exception\WrongTaskIdException;
use Bitrix\Tasks\Internals\Counter\CounterDictionary;
use Bitrix\Tasks\Internals\Registry\TaskRegistry;
use Bitrix\Tasks\Internals\TaskTable;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Integration\CRM\Repository\CrmItemRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Integration\Rest\Service\PlacementService;
use Bitrix\Tasks\V2\Internal\Repository\Trait\ApplicationErrorTrait;
use Bitrix\Tasks\V2\Internal\Repository\Mapper\Task\OrmTaskMapper;
use Bitrix\Tasks\V2\Internal\Repository\Mapper\TaskMapper;
use Bitrix\Tasks\V2\Internal\Service\Task\ChecksumService;

class TaskRepository implements TaskRepositoryInterface
{
	use ApplicationErrorTrait;

	public function __construct(
		private readonly ChecksumService $checksumService,
		private readonly GroupRepositoryInterface $groupRepository,
		private readonly FlowRepositoryInterface $flowRepository,
		private readonly StageRepositoryInterface $stageRepository,
		private readonly UserRepositoryInterface $userRepository,
		private readonly CheckListRepository $checkListRepository,
		private readonly ChatRepositoryInterface $chatRepository,
		private readonly TaskParameterRepositoryInterface $taskParameterRepository,
		private readonly TaskTagRepositoryInterface $taskTagRepository,
		private readonly TaskMapper $taskMapper,
		private readonly OrmTaskMapper $ormTaskMapper,
		private readonly SubTaskRepositoryInterface $subTaskRepository,
		private readonly RelatedTaskRepositoryInterface $relatedTaskRepository,
		private readonly GanttLinkRepositoryInterface $ganttLinkRepository,
		private readonly PlacementService $placementService,
		private readonly CrmItemRepositoryInterface $crmItemRepository,
		private readonly TaskScenarioRepositoryInterface $scenarioRepository,
	)
	{
	}

	public function getById(int $id): ?Entity\Task
	{
		$selectFields = [
			'*',
			'MEMBER_LIST',
			'FAVORITE_TASK',
			'UF_*',
		];

		$task =
			TaskTable::query()
				->setSelect($selectFields)
				->where('ID', $id)
				->fetchObject()
			;

		if ($task === null)
		{
			return null;
		}

		$tags = $this->taskTagRepository->getById($id);

		$group = null;
		if ($task->getGroupId() > 0)
		{
			$group = $this->groupRepository->getById($task->getGroupId());
		}

		$flow = null;
		if ($task->getFlowTask()?->getId() > 0)
		{
			$flow = $this->flowRepository->getById($task->getFlowTask()->getId());
		}

		$stage = null;
		if ($task->getStageId() > 0)
		{
			$stage = $this->stageRepository->getById($task->getStageId());
		}

		$memberIds = array_merge(
			(array)$task->getMemberList()?->getUserIdList(),
			[$task->getCreatedBy(), $task->getResponsibleId(), $task->getClosedBy(), $task->getStatusChangedBy(), $task->getChangedBy()]
		);

		Collection::normalizeArrayValuesByInt($memberIds, false);

		$members = $this->userRepository->getByIds($memberIds);

		$checkListIds = $this->checkListRepository->getIdsByEntity($id, Entity\CheckList\Type::Task);
		if (empty($checkListIds))
		{
			$checkListIds = null;
		}

		$containsSubTasks = $this->subTaskRepository->containsSubTasks($id);
		$containsRelatedTasks = $this->relatedTaskRepository->containsRelatedTasks($id);
		$containsGanttLinks = $this->ganttLinkRepository->containsLinks($id);
		$containsPlacements = $this->placementService->existsTaskCardPlacement();

		$chat = $this->chatRepository->getByTaskId($id);

		$aggregates = [
			'containsCheckList' => !empty($checkListIds),
			'containsSubTasks' => $containsSubTasks,
			'containsRelatedTasks' => $containsRelatedTasks,
			'containsGanttLinks' => $containsGanttLinks,
			'containsPlacements' => $containsPlacements,
		];

		$taskParameters = [
			'matchesSubTasksTime' => $this->taskParameterRepository->matchesSubTasksTime($id),
			'allowsChangeDatePlan' => $this->taskParameterRepository->allowsChangeDatePlan($id),
			'requireResult' => $this->taskParameterRepository->isResultRequired($id),
			'maxDeadlineChangeDate' => $this->taskParameterRepository->maxDeadlineChangeDate($id),
			'maxDeadlineChanges' => $this->taskParameterRepository->maxDeadlineChanges($id),
			'requireDeadlineChangeReason' => $this->taskParameterRepository->requireDeadlineChangeReason($id),
		];

		$crmItemIds = $task->get(Entity\UF\UserField::TASK_CRM);
		if (empty($crmItemIds))
		{
			$crmItemIds = null;
		}

		$fileIds = $task->get(Entity\UF\UserField::TASK_ATTACHMENTS);
		if (empty($fileIds))
		{
			$fileIds = null;
		}

		$checksum = $this->checksumService->calculateChecksum((string)$task->getDescription());

		$scenarios = $this->scenarioRepository->getById($id);

		return $this->taskMapper->mapToEntity(
			taskObject: $task,
			group: $group,
			flow: $flow,
			stage: $stage,
			members: $members,
			aggregates: $aggregates,
			chat: $chat,
			checkListIds: $checkListIds,
			crmItemIds: $crmItemIds,
			taskParameters: $taskParameters,
			tags: $tags,
			fileIds: $fileIds,
			checksum: $checksum,
			scenarios: $scenarios,
		);
	}

	public function save(Entity\Task $entity): int
	{
		if ($entity->getId())
		{
			return $this->update($entity);
		}

		return $this->add($entity);
	}

	/**
	 * @throws TaskNotExistsException
	 * @throws WrongTaskIdException
	 * @throws TaskStopDeleteException
	 */
	public function delete(int $id, bool $safe = true): void
	{
		if (!$safe)
		{
			TaskTable::delete($id);
		}
		else
		{
			$sql = 'DELETE FROM b_tasks WHERE ID = ' . $id;

			Application::getConnection()->query($sql);
		}

		// low level cache
		TaskRegistry::getInstance()->drop($id);
	}

	public function isExists(int $id): bool
	{
		if ($id <= 0)
		{
			return false;
		}

		$result = TaskTable::query()
			->setSelect([new ExpressionField('CNT', 'COUNT(1)')])
			->where('ID', $id)
			->setLimit(1)
			->fetch();

		return is_array($result) && (int)$result['CNT'] > 0;
	}

	public function invalidate(int $taskId): void
	{
		return;
	}

	/**
	 * @throws WrongTaskIdException
	 * @throws TaskNotFoundException
	 * @throws TaskUpdateException
	 * @throws TaskNotExistsException
	 */
	private function update(Entity\Task $task): int
	{
		$currentTask = $this->getById($task->getId());

		if ($currentTask === null)
		{
			$message = Loc::getMessage('TASKS_UNKNOWN_UPDATE_ERROR');

			throw new TaskNotExistsException($message);
		}

		$fields = $this->ormTaskMapper->mapFromEntity($task);

		$result = TaskTable::update($task->id, $fields);

		if (!$result->isSuccess())
		{
			$messages = $result->getErrorMessages();
			$message = Loc::getMessage('TASKS_UNKNOWN_ADD_ERROR');
			if (!empty($messages))
			{
				$message = array_shift($messages);
			}

			throw new TaskUpdateException($message);
		}

		TaskRegistry::getInstance()->drop($task->getId());

		return $result->getId();
	}

	private function add(Entity\Task $task): int
	{
		$fields = $this->ormTaskMapper->mapFromEntity($task);
		$result = TaskTable::add($fields);

		if (!$result->isSuccess())
		{
			$messages = $result->getErrorMessages();
			$message = Loc::getMessage('TASKS_UNKNOWN_ADD_ERROR');
			if (!empty($messages))
			{
				$message = array_shift($messages);
			}

			throw new TaskAddException($message);
		}

		return $result->getId();
	}

	public function updateLastActivityDate(int $taskId, int $activityTs): void
	{
		$result = TaskTable::update($taskId, ['ACTIVITY_DATE' => DateTime::createFromTimestamp($activityTs)]);

		if (!$result->isSuccess())
		{
			$messages = $result->getErrorMessages();
			$message = Loc::getMessage('TASKS_UNKNOWN_ADD_ERROR');
			if (!empty($messages))
			{
				$message = array_shift($messages);
			}

			throw new TaskUpdateException($message);
		}
	}

	public function findCreatorIdsByTaskIds(array $taskIds): array
	{
		$result = TaskTable::query()
			->setSelect(['ID', 'CREATED_BY'])
			->whereIn('ID', $taskIds)
			->exec();

		return array_column($result->fetchAll(), 'CREATED_BY', 'ID');
	}

	public function countRecentTaskIdsWithChatIds(int $userId): int
	{
		$count = TaskTable::query()
			->setDistinct(true)
			->where('MEMBER_LIST.USER_ID', $userId)
			->whereNotNull('CHAT_TASK.CHAT_ID')
			->whereNull('CLOSED_DATE')
			->queryCountTotal();
		return (int)$count;
	}

	public function findRecentTaskIdsWithChatIdsOrderedByActivityDate(int $userId, int $limit): array
	{
		$result = TaskTable::query()
			->setDistinct(true)
			->setSelect(['ID', 'CHAT_TASK.CHAT_ID'])
			->where('MEMBER_LIST.USER_ID', $userId)
			->whereNull('CLOSED_DATE')
			->addOrder('ACTIVITY_DATE', 'DESC')
			->setLimit($limit)
			->exec();

		return $result->fetchAll();
	}

	public function findTasksIdsWithChatIdsAndActiveCountersByUserIdAndGroupId(int $userId, ?int $groupId = null): array
	{
		$result = TaskTable::query()
			->setSelect(['ID', 'TASK_CHAT_ID' => 'CHAT_TASK.CHAT_ID'])
			->where('GROUP_ID', $groupId)
			->where('COUNTERS.USER_ID', $userId)
			->whereIn('COUNTERS.TYPE', [
				CounterDictionary::COUNTER_MY_NEW_COMMENTS,
				CounterDictionary::COUNTER_MY_MUTED_NEW_COMMENTS,
				CounterDictionary::COUNTER_ACCOMPLICES_NEW_COMMENTS,
				CounterDictionary::COUNTER_ACCOMPLICES_MUTED_NEW_COMMENTS,
				CounterDictionary::COUNTER_AUDITOR_NEW_COMMENTS,
				CounterDictionary::COUNTER_AUDITOR_MUTED_NEW_COMMENTS,
				CounterDictionary::COUNTER_ORIGINATOR_NEW_COMMENTS,
				CounterDictionary::COUNTER_ORIGINATOR_MUTED_NEW_COMMENTS,
			])
			->exec()->fetchAll();

		return $result;
	}
}
