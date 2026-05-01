<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository\Mapper;

use Bitrix\Tasks\Internals\TaskObject;
use Bitrix\Tasks\Internals\UserOption\Option;
use Bitrix\Tasks\MemberTable;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Integration\CRM\Entity\CrmItemCollection;
use Bitrix\Tasks\V2\Internal\Integration\Im;
use Bitrix\Tasks\V2\Internal\Integration\Mail\Entity\Email;
use Bitrix\Tasks\V2\Internal\Service\Task\ChecksumService;

class TaskMapper
{
	use Entity\Trait\MapTypeTrait;

	public function __construct(
		private readonly TaskStatusMapper $taskStatusMapper,
		private readonly PriorityMapper $taskPriorityMapper,
		private readonly TaskMarkMapper $taskMarkMapper,
		private readonly UserFieldMapper $userFieldMapper,
		private readonly ChecksumService $checksumService,
	)
	{

	}

	public function mapFromArray(
		array $taskData,
		?Entity\TaskMemberCollection $taskMembers = null,
		?Entity\UserCollection $members = null,
		?CrmItemCollection $crmItems = null,
		?Entity\TagCollection $tags = null,
		?Entity\GroupCollection $groups = null,
		?Entity\FlowCollection $flows = null,
		?Entity\Task\GanttLinkCollection $links = null,
	): Entity\Task
	{
		$mapBool = static function (?string $string): ?bool {
			return $string === null ? null : ($string === 'Y');
		};

		$mapUser = static function (?string $id) use ($members) : ?Entity\User {
			return $id === null ? null : $members?->findOneById((int)$id);
		};

		$accomplicesIds = $taskMembers
			->filter(fn (Entity\TaskMember $taskMember) => $taskMember->type === MemberTable::MEMBER_TYPE_ACCOMPLICE)
			->map(fn (Entity\TaskMember $taskMember) => $taskMember->userId)
		;

		$auditorsIds = $taskMembers
			->filter(fn (Entity\TaskMember $taskMember) => $taskMember->type === MemberTable::MEMBER_TYPE_AUDITOR)
			->map(fn (Entity\TaskMember $taskMember) => $taskMember->userId)
		;

		return new Entity\Task(
			id: static::mapInteger($taskData, 'ID'),
			title: static::mapString($taskData, 'TITLE'),
			description: static::mapString($taskData, 'DESCRIPTION'),
			descriptionChecksum: isset($taskData['DESCRIPTION']) ? $this->checksumService->calculateChecksum($taskData['DESCRIPTION']) : null,
			creator: $mapUser($taskData['CREATED_BY'] ?? null),
			createdTs: static::mapDateTime($taskData, 'CREATED_DATE')?->getTimestamp(),
			responsible: $mapUser($taskData['RESPONSIBLE_ID'] ?? null),
			deadlineTs: static::mapDateTime($taskData, 'DEADLINE')?->getTimestamp(),
			needsControl: $mapBool($taskData['TASK_CONTROL'] ?? null),
			startPlanTs: static::mapDateTime($taskData, 'START_DATE_PLAN')?->getTimestamp(),
			endPlanTs: static::mapDateTime($taskData, 'END_DATE_PLAN')?->getTimestamp(),
			parentId: static::mapInteger($taskData, 'PARENT_ID'),
			group: isset($taskData['COMPUTE_GROUP_ID']) ? $groups->findOneById((int)$taskData['COMPUTE_GROUP_ID']) : null,
			flow: isset($taskData['FLOW_ID']) ? $flows->findOneById((int)$taskData['FLOW_ID']) : null,
			priority: isset($taskData['PRIORITY']) ? $this->taskPriorityMapper->mapToEnum((int)$taskData['PRIORITY']) : null,
			status: isset($taskData['REAL_STATUS']) ? $this->taskStatusMapper->mapToEnum((int)$taskData['REAL_STATUS']) : null,
			statusChangedTs: static::mapDateTime($taskData, 'COMPUTE_STATUS_CHANGED_DATE')?->getTimestamp(),
			accomplices: $members->findAllByIds($accomplicesIds),
			auditors: $members->findAllByIds($auditorsIds),
			containsGanttLinks: $links->count() > 0,
			timeSpent: static::mapInteger($taskData, 'TIME_SPENT_IN_LOGS'),
			chatId: static::mapInteger($taskData, 'CHAT_ID'),
			plannedDuration: static::mapInteger($taskData, 'COMPUTE_DURATION_PLAN'),
			actualDuration: static::mapInteger($taskData, 'DURATION_FACT'),
			durationType: isset($taskData['COMPUTE_DURATION_TYPE']) ? Entity\Task\Duration::tryFrom($taskData['COMPUTE_DURATION_TYPE']) : null,
			startedTs: static::mapDateTime($taskData, 'DATE_START')?->getTimestamp(),
			estimatedTime: static::mapInteger($taskData, 'TIME_ESTIMATE'),
			replicate: $mapBool($taskData['REPLICATE'] ?? null),
			changedTs: static::mapDateTime($taskData, 'CHANGED_DATE')?->getTimestamp(),
			changedBy: $mapUser($taskData['CHANGED_BY'] ?? null),
			statusChangedBy: $mapUser($taskData['STATUS_CHANGED_BY'] ?? null),
			closedBy: $mapUser($taskData['CLOSED_BY'] ?? null),
			closedTs: static::mapDateTime($taskData, 'CLOSED_DATE')?->getTimestamp(),
			activityTs: static::mapDateTime($taskData, 'ACTIVITY_DATE')?->getTimestamp(),
			guid: static::mapString($taskData, 'GUID'),
			xmlId: static::mapString($taskData, 'XML_ID'),
			exchangeId: static::mapInteger($taskData, 'EXCHANGE_ID'),
			exchangeModified: static::mapString($taskData, 'EXCHANGE_MODIFIED'),
			outlookVersion: static::mapInteger($taskData, 'OUTLOOK_VERSION'),
			mark: isset($taskData['MARK']) ? $this->taskMarkMapper->mapToEnum($taskData['MARK']) : null,
			allowsChangeDeadline: $mapBool($taskData['ALLOW_CHANGE_DEADLINE'] ?? null),
			allowsTimeTracking: $mapBool($taskData['ALLOW_TIME_TRACKING'] ?? null),
			matchesWorkTime: $mapBool($taskData['MATCH_WORK_TIME'] ?? null),
			addInReport: $mapBool($taskData['ADD_IN_REPORT'] ?? null),
			isMultitask: $mapBool($taskData['MULTITASK'] ?? null),
			siteId: static::mapString($taskData, 'SITE_ID'),
			deadlineCount: static::mapInteger($taskData, 'DEADLINE_COUNT'),
			isZombie: $mapBool($taskData['IS_ZOMBIE'] ?? null),
			declineReason: static::mapString($taskData, 'DECLINE_REASON'),
			forumTopicId: static::mapInteger($taskData, 'FORUM_TOPIC_ID'),
			tags: $tags,
			crmItemIds: $crmItems?->getIdList(),
			crmItems: $crmItems,
			sprintId: static::mapInteger($taskData, 'SPRINT_ID'),
			backlogId: static::mapInteger($taskData, 'BACKLOG_ID'),
			stageId: static::mapInteger($taskData, 'STAGE_ID'),
			forumId: static::mapInteger($taskData, 'FORUM_ID'),
			deadlineOrigTs: static::mapDateTime($taskData, 'DEADLINE_ORIG')?->getTimestamp(),
			viewedDateTs: static::mapDateTime($taskData, 'VIEWED_DATE')?->getTimestamp(),
			stagesId: static::mapInteger($taskData, 'STAGES_ID'),
			notViewed: $mapBool($taskData['NOT_VIEWED'] ?? null),
			isMuted: $mapBool($taskData['IS_MUTED'] ?? null),
			isRegular: $mapBool($taskData['IS_REGULAR'] ?? null),
			isPinned: $mapBool($taskData['IS_PINNED'] ?? null),
			forkByTemplateId: static::mapInteger($taskData, 'FORK_BY_TEMPLATE_ID'),
			commentsCount: static::mapInteger($taskData, 'COMMENTS_COUNT'),
			serviceCommentsCount: static::mapInteger($taskData, 'SERVICE_COMMENTS_COUNT'),
			durationPlanSeconds: static::mapInteger($taskData, 'DURATION_PLAN_SECONDS'),
			durationTypeAll: static::mapString($taskData, 'DURATION_TYPE_ALL'),
			isPinnedInGroup: static::mapBool($taskData, 'IS_PINNED_IN_GROUP'),
			links: $links,
		);
	}

	public function mapToEntity(
		TaskObject $taskObject,
		?Entity\Group $group = null,
		?Entity\Flow $flow = null,
		?Entity\Stage $stage = null,
		?Entity\UserCollection $members = null,
		array $aggregates = [],
		?Im\Entity\Chat $chat = null,
		?array $checkListIds = null,
		?array $crmItemIds = null,
		?array $taskParameters = null,
		?Entity\Task\UserOptionCollection $userOptions = null,
		?Entity\TagCollection $tags = null,
		?array $fileIds = null,
		?string $checksum = null,
		?Email $email = null,
		?Entity\Task\ScenarioCollection $scenarios = null,
	): Entity\Task
	{
		return new Entity\Task(
			id: $taskObject->getId(),
			title: $taskObject->getTitle(),
			description: $taskObject->getDescription(),
			descriptionChecksum: $checksum,
			creator: $members?->findOneById($taskObject->getCreatedBy()),
			createdTs: $taskObject->getCreatedDate()?->getTimestamp(),
			responsible: $members?->findOneById($taskObject->getResponsibleId()),
			deadlineTs: $taskObject->getDeadline()?->getTimestamp(),
			needsControl: $taskObject->getTaskControl(),
			startPlanTs: $taskObject->getStartDatePlan()?->getTimestamp(),
			endPlanTs: $taskObject->getEndDatePlan()?->getTimestamp(),
			fileIds: $fileIds,
			parentId: $taskObject->getParentId(),
			checklist: $checkListIds,
			group: $group,
			stage: $stage,
			flow: $flow,
			priority: $this->taskPriorityMapper->mapToEnum((int)$taskObject->getPriority()),
			status: $this->taskStatusMapper->mapToEnum((int)$taskObject->getStatus()),
			statusChangedTs: $taskObject->getStatusChangedDate()?->getTimestamp(),
			accomplices: $members?->findAllByIds((array)$taskObject->getMemberList()?->getAccompliceIds()),
			auditors: $members?->findAllByIds((array)$taskObject->getMemberList()?->getAuditorIds()),
			parent: $taskObject->getParentId() ? new Entity\Task(id: $taskObject->getParentId()) : null,
			containsChecklist: $aggregates['containsCheckList'] ?? null,
			containsSubTasks: $aggregates['containsSubTasks'] ?? null,
			containsRelatedTasks: $aggregates['containsRelatedTasks'] ?? null,
			containsGanttLinks: $aggregates['containsGanttLinks'] ?? null,
			containsPlacements: $aggregates['containsPlacements'] ?? null,
			containsCommentFiles: $aggregates['containsCommentFiles'] ?? null,
			numberOfReminders: $aggregates['numberOfReminders'] ?? null,
			timers: $aggregates['timers'] ?? null,
			timeSpent: $aggregates['timeSpent'] ?? null,
			chatId: $chat?->getId(),
			chat: $chat,
			plannedDuration: $taskObject->getDurationPlan(),
			actualDuration: $taskObject->getDurationFact(),
			durationType: Entity\Task\Duration::tryFrom($taskObject->getDurationType()),
			startedTs: $taskObject->getDateStart()?->getTimestamp(),
			estimatedTime: $taskObject->getTimeEstimate(),
			replicate: $taskObject->getReplicate(),
			changedTs: $taskObject->getChangedDate()?->getTimestamp(),
			changedBy: $members?->findOneById($taskObject->getChangedBy()),
			statusChangedBy: $members?->findOneById($taskObject->getStatusChangedBy()),
			closedBy: $members?->findOneById($taskObject->getClosedBy()),
			closedTs: $taskObject->getClosedDate()?->getTimestamp(),
			activityTs: $taskObject->getActivityDate()?->getTimestamp(),
			guid: $taskObject->getGuid(),
			xmlId: $taskObject->getXmlId(),
			exchangeId: $taskObject->getExchangeId(),
			exchangeModified: $taskObject->getExchangeModified(),
			outlookVersion: $taskObject->getOutlookVersion(),
			mark: $this->taskMarkMapper->mapToEnum((string)$taskObject->getMark()),
			allowsChangeDeadline: $taskObject->getAllowChangeDeadline(),
			allowsTimeTracking: $taskObject->getAllowTimeTracking(),
			matchesWorkTime: $taskObject->getMatchWorkTime(),
			isMultitask: $taskObject->getMultitask(),
			siteId: $taskObject->getSiteId(),
			forkedByTemplate: $taskObject->getForkedByTemplateId() > 0 ? new Entity\Template(id: $taskObject->getForkedByTemplateId()) : null,
			forumTopicId: $taskObject->getForumTopicId(),
			tags: $tags,
			userFields: $this->userFieldMapper->mapToCollection($taskObject->collectValues()),
			crmItemIds: $crmItemIds,
			numberOfElapsedTimes: $aggregates['numberOfElapsedTimes'] ?? null,
			requireResult: $taskParameters['requireResult'] ?? null,
			matchesSubTasksTime: $taskParameters['matchesSubTasksTime'] ?? null,
			autocompleteSubTasks: $taskParameters['autocompleteSubTasks'] ?? null,
			allowsChangeDatePlan: $taskParameters['allowsChangeDatePlan'] ?? null,
			inFavorite: $taskObject->getFavoriteTask()?->getUserIdList(),
			inPin: $userOptions?->filter(fn (Entity\Task\UserOption $option): bool => $option->code === Option::PINNED)->getUserIdList(),
			inGroupPin: $userOptions?->filter(fn (Entity\Task\UserOption $option): bool => $option->code === Option::PINNED_IN_GROUP)->getUserIdList(),
			inMute: $userOptions?->filter(fn (Entity\Task\UserOption $option): bool => $option->code === Option::MUTED)->getUserIdList(),
			containsResults: $aggregates['containsResults'] ?? null,
			email: $email,
			maxDeadlineChangeDate: $taskParameters['maxDeadlineChangeDate'] ?? null,
			maxDeadlineChanges: $taskParameters['maxDeadlineChanges'] ?? null,
			requireDeadlineChangeReason: $taskParameters['requireDeadlineChangeReason'] ?? null,
			scenarios: $scenarios,
		);
	}
}
