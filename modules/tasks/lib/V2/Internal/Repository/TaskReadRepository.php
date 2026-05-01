<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\LoaderException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\SystemException;
use Bitrix\Main\Type\Collection;
use Bitrix\Tasks\Internals\TaskTable;
use Bitrix\Tasks\Provider\Exception\InvalidSelectException;
use Bitrix\Tasks\Provider\Query\TaskQueryBuilder;
use Bitrix\Tasks\Provider\TaskQuery;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Entity\TagCollection;
use Bitrix\Tasks\V2\Internal\Integration\CRM\Access\Service\CrmAccessService;
use Bitrix\Tasks\V2\Internal\Integration\CRM\Entity\CrmItemCollection;
use Bitrix\Tasks\V2\Internal\Integration\CRM\Repository\CrmItemRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Integration\Mail\Repository\EmailRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Integration\Rest\Service\PlacementService;
use Bitrix\Tasks\V2\Internal\Repository\Mapper\TaskMapper;
use Bitrix\Tasks\V2\Internal\Repository\Task\Filter;
use Bitrix\Tasks\V2\Internal\Repository\Task\ListSelect;
use Bitrix\Tasks\V2\Internal\Repository\Task\Order;
use Bitrix\Tasks\V2\Internal\Repository\Task\Select;
use Bitrix\Tasks\V2\Internal\Service\Task\ChecksumService;
use Bitrix\Tasks\V2\Internal\Service\TaskLegacyFeatureService;
use Bitrix\Tasks\V2\Public\Provider\TaskElapsedTimeProvider;

class TaskReadRepository implements TaskReadRepositoryInterface
{
	const DEFAULT_LIMIT = 50;

	public function __construct(
		private readonly ChecksumService $checksumService,
		private readonly GroupRepositoryInterface $groupRepository,
		private readonly FlowRepositoryInterface $flowRepository,
		private readonly StageRepositoryInterface $stageRepository,
		private readonly UserRepositoryInterface $userRepository,
		private readonly CheckListRepository $checkListRepository,
		private readonly ChatRepositoryInterface $chatRepository,
		private readonly TaskParameterRepositoryInterface $taskParameterRepository,
		private readonly CrmItemRepositoryInterface $crmItemRepository,
		private readonly TaskUserOptionRepositoryInterface $userOptionRepository,
		private readonly SubTaskRepositoryInterface $subTaskRepository,
		private readonly RelatedTaskRepositoryInterface $relatedTaskRepository,
		private readonly ReminderReadRepositoryInterface $remindersReadRepository,
		private readonly TaskTagRepositoryInterface $taskTagRepository,
		private readonly GanttLinkRepositoryInterface $ganttLinkRepository,
		private readonly PlacementService $placementService,
		private readonly TaskResultRepositoryInterface $taskResultRepository,
		private readonly EmailRepositoryInterface $emailRepository,
		private readonly TimerRepositoryInterface $timerRepository,
		private readonly TaskScenarioRepositoryInterface $scenarioRepository,
		private readonly TaskMapper $taskMapper,
		private readonly TaskElapsedTimeProvider $elapsedTimeProvider,
		private readonly TaskLegacyFeatureService $taskLegacyFeatureService,
		private readonly TaskMemberRepositoryInterface $taskMemberRepository,
		private readonly CrmAccessService $crmAccessService
	)
	{
	}

	/**
	 * @param Pagination|null $pagination
	 * @param Order|null $order
	 * @param Filter|null $filter
	 * @return array
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 * @throws LoaderException
	 * @throws InvalidSelectException
	 */
	protected function getTaskIds(
		?Pagination $pagination,
		?Order $order,
		?Filter $filter,
	): array
	{
		$builtQuery = $this->buildListQuery(
			$pagination,
			$order,
			$filter,
			new ListSelect(['id']),
			['ID'],
		);

		$filteredTasks = $builtQuery->fetchAll();

		return array_map(
			static fn (string $id) => (int)$id,
			array_column($filteredTasks, 'ID'),
		);
	}

	public function getList(
		?Pagination $pagination = null,
		?ListSelect $select = null,
		?Order $order = null,
		?Filter $filter = null,
	): Entity\TaskCollection
	{
		return $this->getListByIds(
			$this->getTaskIds($pagination, $order, $filter),
			$select,
		);
	}

	/**
	 * @throws ObjectPropertyException
	 * @throws ArgumentException
	 * @throws SystemException
	 */
	public function getCount(?Filter $filter = null): int
	{
		$result = $this->buildListQuery(filter: $filter)
			->addSelect(Query::expr()->countDistinct('ID'), 'CNT')
			->exec()
			->fetch()
		;

		return (int)$result['CNT'];
	}

	/**
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function getById(int $id, ?Select $select = null): ?Entity\Task
	{
		$selectFields = [
			'*',
		];

		$select ??= new Select();

		if ($select->flow)
		{
			$selectFields[] = 'FLOW_TASK.FLOW_ID';
		}

		if ($select->members)
		{
			$selectFields[] = 'MEMBER_LIST';
		}

		if ($select->favorite)
		{
			$selectFields[] = 'FAVORITE_TASK';
		}

		if ($select->userFields)
		{
			$selectFields[] = 'UF_*';
		}

		$task = TaskTable::query()
			->setSelect($selectFields)
			->where('ID', $id)
			->fetchObject()
		;

		if ($task === null)
		{
			return null;
		}

		$tags = null;
		if ($select->tags)
		{
			$tags = $this->taskTagRepository->getById($id);
		}

		$group = null;
		if ($select->group && $task->getGroupId() > 0)
		{
			$group = $this->groupRepository->getById($task->getGroupId());
		}

		$flow = null;
		if ($select->flow && $task->getFlowTask()?->getFlowId() > 0)
		{
			$flow = $this->flowRepository->getById($task->getFlowTask()->getFlowId());
		}

		$stage = null;
		if ($select->stage && $task->getGroupId() > 0)
		{
			$stageId = $task->getStageId();
			if ($stageId <= 0)
			{
				$stageId = $this->stageRepository->getFirstIdByGroupId($task->getGroupId());
			}

			if ($stageId > 0)
			{
				$stage = $this->stageRepository->getById($stageId);
			}
		}

		$members = null;
		if ($select->members)
		{
			$memberIds = array_merge(
				$task->getMemberList()->getUserIdList(),
				[
					$task->getCreatedBy(),
					$task->getResponsibleId(),
					$task->getClosedBy(),
					$task->getStatusChangedBy(),
					$task->getChangedBy(),
				]
			);

			Collection::normalizeArrayValuesByInt($memberIds, false);

			$members = $this->userRepository->getByIds($memberIds);
		}

		$checkListIds = null;
		if ($select->checkLists)
		{
			$checkListIds = $this->checkListRepository->getIdsByEntity($id, Entity\CheckList\Type::Task);
		}

		$chat = $this->chatRepository->getByTaskId($id);

		$crmItemIds = null;
		if ($select->crm)
		{
			$crmItemIds = $this->crmItemRepository->getIdsByTaskId($id);
		}

		$containsSubTasks = false;
		if ($select->subTasks)
		{
			$containsSubTasks = $this->subTaskRepository->containsSubTasks($id);
		}

		$containsRelatedTasks = false;
		if ($select->relatedTasks)
		{
			$containsRelatedTasks = $this->relatedTaskRepository->containsRelatedTasks($id);
		}

		$numberOfReminders = 0;
		if ($select->reminders)
		{
			$userId = (int)CurrentUser::get()->getId();
			$numberOfReminders = $this->remindersReadRepository->getNumberOfReminders($id, $userId);
		}

		$containsGanttLinks = false;
		if ($select->gantt)
		{
			$containsGanttLinks = $this->ganttLinkRepository->containsLinks($id);
		}

		$containsPlacements = false;
		if ($select->placements)
		{
			$containsPlacements = $this->placementService->existsTaskCardPlacement();
		}

		$containsCommentFiles = false;
		if ($select->containsCommentFiles)
		{
			$containsCommentFiles = $this->taskLegacyFeatureService->hasForumCommentFiles($id);
		}

		$containsResults = null;
		if ($select->results)
		{
			$containsResults = $this->taskResultRepository->containsResults($id);
		}

		$timers = $this->timerRepository->getRunningTimersByTaskId($id);
		$timeSpent = $this->elapsedTimeProvider->getTimeSpentOnTask($task->getId());
		$numberOfElapsedTimes = $this->elapsedTimeProvider->getNumberOfElapsedTimes($task->getId());

		$aggregates = [
			'containsCheckList' => !empty($checkListIds),
			'containsSubTasks' => $containsSubTasks,
			'containsRelatedTasks' => $containsRelatedTasks,
			'containsGanttLinks' => $containsGanttLinks,
			'containsPlacements' => $containsPlacements,
			'containsCommentFiles' => $containsCommentFiles,
			'containsResults' => $containsResults,
			'numberOfReminders' => $numberOfReminders,
			'timers' => $timers,
			'timeSpent' => $timeSpent,
			'numberOfElapsedTimes' => $numberOfElapsedTimes,
		];

		$userOptions = null;
		if ($select->options)
		{
			$userOptions = $this->userOptionRepository->get($id);
		}

		$taskParameters = null;
		if ($select->parameters)
		{
			$taskParameters = [
				'matchesSubTasksTime' => $this->taskParameterRepository->matchesSubTasksTime($id),
				'autocompleteSubTasks' => $this->taskParameterRepository->autocompleteSubTasks($id),
				'allowsChangeDatePlan' => $this->taskParameterRepository->allowsChangeDatePlan($id),
				'requireResult' => $this->taskParameterRepository->isResultRequired($id),
				'maxDeadlineChangeDate' => $this->taskParameterRepository->maxDeadlineChangeDate($id),
				'maxDeadlineChanges' => $this->taskParameterRepository->maxDeadlineChanges($id),
				'requireDeadlineChangeReason' => $this->taskParameterRepository->requireDeadlineChangeReason($id),
			];
		}

		$fileIds = $task->get(Entity\UF\UserField::TASK_ATTACHMENTS);
		if (empty($fileIds))
		{
			$fileIds = null;
		}

		$checksum = $this->checksumService->calculateChecksum((string)$task->getDescription());

		$email = null;
		if ($select->email)
		{
			$email = $this->emailRepository->getByTaskId($id);
		}

		$scenarios = null;
		if ($select->scenarios)
		{
			$scenarios = $this->scenarioRepository->getById($id);
		}

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
			userOptions: $userOptions,
			tags: $tags,
			fileIds: $fileIds,
			checksum: $checksum,
			email: $email,
			scenarios: $scenarios,
		);
	}

	public function getAttachmentIds(int $taskId): array
	{
		$row =
			TaskTable::query()
				->setSelect(['ID', Entity\UF\UserField::TASK_ATTACHMENTS])
				->where('ID', $taskId)
				->fetch()
		;

		if (!is_array($row))
		{
			return [];
		}

		$value = $row[Entity\UF\UserField::TASK_ATTACHMENTS] ?? null;

		if (!is_array($value))
		{
			return [];
		}

		return $value;
	}

	/**
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 * @throws ArgumentException
	 */

	protected function getListByIds(
		array $taskIds,
		?ListSelect $select = null
	): Entity\TaskCollection
	{
		if (empty($taskIds))
		{
			return new Entity\TaskCollection();
		}

		// fetching tasks by ids
		$builtQuery = $this->buildListQuery(
			filter: new Filter(
				filter: (new ConditionTree())->whereIn('id', $taskIds),
				skipAccessCheck: true,
			),
			select: $select,
		);

		$filteredTasks = $builtQuery->fetchAll();

		// order according to taskIds order
		$tasks = [];
		foreach ($filteredTasks as $taskData)
		{
			$tasks[$taskData['ID']] = $taskData;
		}

		$orderedTasks = [];
		foreach ($taskIds as $taskId)
		{
			$orderedTasks[] = $tasks[$taskId];
		}

		return $this->prepareListItemCollection(
			array_filter($orderedTasks),
			$select
		);
	}

	/**
	 * @throws ArgumentException
	 * @var array[] $tasks
	 */
	protected function prepareListItemCollection(array $tasks, ListSelect $select): Entity\TaskCollection
	{
		$taskIds = array_map('intval', array_column($tasks, 'ID'));
		$groupIds = array_unique(array_column($tasks, 'COMPUTE_GROUP_ID'));
		$flowIds = array_unique(array_column($tasks, 'FLOW_ID'));

		// collect related data
		$crmItemCollection = new CrmItemCollection();
		if ($select->hasCrmFields())
		{
			$currentUserId = (int)CurrentUser::get()->getId();
			$crmItemsIds = array_unique(array_merge(...array_filter(array_column($tasks, 'UF_CRM_TASK'))));
			$crmItemsIds = $this->crmAccessService->filterCrmItemsWithAccess($crmItemsIds, $currentUserId);

			$crmItemCollection = $this->crmItemRepository->getByIds($crmItemsIds);
		}

		$tagCollection = $select->has('tags')
			? $this->taskTagRepository->getByIds($taskIds)
			: new TagCollection();

		$groupCollection = ($select->has('group') || $select->has('groupId') || $select->has('groupName'))
			? $this->groupRepository->getByIds($groupIds)
			: new Entity\GroupCollection();

		$flowCollection = ($select->has('flow') || $select->has('flowId'))
			? $this->flowRepository->getByIds($flowIds)
			: new Entity\FlowCollection();

		$ganttLinksCollection = $select->has('links')
			? $this->ganttLinkRepository->getLinksByTaskIds($taskIds)
			: new Entity\Task\GanttLinkCollection();

		$taskMembers = $this->taskMemberRepository->getByTaskIds($taskIds);
		$userIds = array_unique($taskMembers->map(fn (Entity\TaskMember $taskMember) => $taskMember->userId));
		$taskUsers = $this->userRepository->getByIds($userIds);

		$taskCollection = new Entity\TaskCollection();
		foreach ($tasks as $taskData)
		{
			$taskId = (int)$taskData['ID'];

			$task = $this->taskMapper->mapFromArray(
				taskData: $taskData,
				taskMembers: $taskMembers->filter(fn (Entity\TaskMember $item) => $item->taskId === $taskId),
				members: $taskUsers,
				crmItems: $crmItemCollection->findAllByIds($taskData['UF_CRM_TASK'] ?? []),
				tags: $tagCollection->filter(fn (Entity\Tag $tag) => $tag->task?->id === $taskId),
				groups: $groupCollection,
				flows: $flowCollection,
				links: $ganttLinksCollection->filter(fn (Entity\Task\GanttLink $link) => $link->taskId === $taskId),
			);

			$taskCollection->add($task);
		}

		return $taskCollection;
	}

	private function buildListQuery(
		?Pagination $pagination = null,
		?Order $order = null,
		?Filter $filter = null,
		?ListSelect $select = null,
		?array $groupBy = null,
	): Query
	{
		$taskQuery = (new TaskQuery($filter->userId ?? 0))
			->setLimit($pagination?->limit ?? self::DEFAULT_LIMIT)
			->setOffset($pagination?->offset ?? 0)
			->setWhere($filter?->prepareArrayFilter() ?? [])
			->setOrder($order?->prepareOrder() ?? [])
			->setSelect($select?->prepareSelect() ?? [])
			->setGroupBy($groupBy ?? [])
		;

		$taskQuery->addSelect('ID');

		if ($filter->skipAccessCheck) {
			$taskQuery->skipAccessCheck();
		}

		// using old TaskQueryBuilder
		return TaskQueryBuilder::build($taskQuery);
	}
}
