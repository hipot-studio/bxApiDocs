<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository\Mapper;

use Bitrix\Tasks\Internals\TaskObject;
use Bitrix\Tasks\Internals\UserOption\Option;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Entity\Task\Scenario;
use Bitrix\Tasks\V2\Internal\Integration\Im;
use Bitrix\Tasks\V2\Internal\Integration\Mail\Entity\Email;

class TaskMapper
{
	public function __construct(
		private readonly TaskStatusMapper $taskStatusMapper,
		private readonly PriorityMapper $taskPriorityMapper,
		private readonly TaskMarkMapper $taskMarkMapper,
		private readonly UserFieldMapper $userFieldMapper,
	)
	{

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
