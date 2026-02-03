<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository;

use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Type\Collection;
use Bitrix\Tasks\Internals\TaskTable;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Integration\CRM\Repository\CrmItemRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Integration\Mail\Repository\EmailRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Integration\Rest\Service\PlacementService;
use Bitrix\Tasks\V2\Internal\Repository\Mapper\TaskMapper;
use Bitrix\Tasks\V2\Internal\Repository\Task\Select;
use Bitrix\Tasks\V2\Internal\Service\Task\ChecksumService;
use Bitrix\Tasks\V2\Internal\Service\TaskLegacyFeatureService;
use Bitrix\Tasks\V2\Public\Provider\TaskElapsedTimeProvider;

class TaskReadRepository implements TaskReadRepositoryInterface
{
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
	)
	{
	}

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
				[$task->getCreatedBy(), $task->getResponsibleId(), $task->getClosedBy(), $task->getStatusChangedBy(), $task->getChangedBy()]
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
}
