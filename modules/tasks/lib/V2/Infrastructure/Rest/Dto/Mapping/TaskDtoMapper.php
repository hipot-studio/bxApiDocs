<?php

namespace Bitrix\Tasks\V2\Infrastructure\Rest\Dto\Mapping;

use Bitrix\Main\Type\Date;
use Bitrix\Main\Type\DateTime;
use Bitrix\Rest\V3\Dto\DtoCollection;
use Bitrix\Rest\V3\Interaction\Request\Request;
use Bitrix\Tasks\V2\Infrastructure\Rest\Dto\ElapsedTimeDto;
use Bitrix\Tasks\V2\Infrastructure\Rest\Dto\EmailDto;
use Bitrix\Tasks\V2\Infrastructure\Rest\Dto\FlowDto;
use Bitrix\Tasks\V2\Infrastructure\Rest\Dto\GroupDto;
use Bitrix\Tasks\V2\Infrastructure\Rest\Dto\ReminderDto;
use Bitrix\Tasks\V2\Infrastructure\Rest\Dto\SourceDto;
use Bitrix\Tasks\V2\Infrastructure\Rest\Dto\StageDto;
use Bitrix\Tasks\V2\Infrastructure\Rest\Dto\TagDto;
use Bitrix\Tasks\V2\Infrastructure\Rest\Dto\Task\ChatDto;
use Bitrix\Tasks\V2\Infrastructure\Rest\Dto\TaskDto;
use Bitrix\Tasks\V2\Infrastructure\Rest\Dto\TemplateDto;
use Bitrix\Tasks\V2\Infrastructure\Rest\Dto\UserDto;
use Bitrix\Tasks\V2\Infrastructure\Rest\Dto\UserFieldDto;
use Bitrix\Tasks\V2\Internal\Entity\Task;

class TaskDtoMapper
{
	public function mapByTaskAndRequest(?Task $task, ?Request $request = null): ?TaskDto
	{
		if (!$task)
		{
			return null;
		}
		$dto = new TaskDto();

		$select = $request?->select?->getList(true) ?? [];

		$dto->id = $task->id ?? null;
		if (empty($select) || in_array('title', $select, true))
		{
			$dto->title = $task->title ?? null;
		}
		if (empty($select) || in_array('description', $select, true))
		{
			$dto->description = $task->description ?? null;
		}
		if ($request?->getRelation('creator') !== null)
		{
			$dto->creator = UserDto::fromEntity($task->creator ?? null, $request?->getRelation('creator')?->getRequest());
		}
		if ($request?->getRelation('responsible') !== null)
		{
			$dto->responsible = UserDto::fromEntity($task->responsible ?? null, $request?->getRelation('responsible')?->getRequest());
		}
		if (empty($select) || in_array('deadline', $select, true))
		{
			$dto->deadline = $task->deadlineTs ? DateTime::createFromTimestamp($task->deadlineTs) : null;
		}
		if (empty($select) || in_array('needsControl', $select, true))
		{
			$dto->needsControl = $task->needsControl ?? null;
		}
		if (empty($select) || in_array('startPlan', $select, true))
		{
			$dto->startPlan = $task->startPlanTs ? DateTime::createFromTimestamp($task->startPlanTs) : null;
		}
		if (empty($select) || in_array('endPlan', $select, true))
		{
			$dto->endPlan = $task->endPlanTs ? DateTime::createFromTimestamp($task->endPlanTs) : null;
		}
		if (empty($select) || in_array('fileIds', $select, true))
		{
			$dto->fileIds = $task->fileIds ?? null;
		}
		if (empty($select) || in_array('parentId', $select, true))
		{
			$dto->parentId = $task->parentId !== 0 ? $task->parentId : null;
		}
		if (empty($select) || in_array('checklist', $select, true))
		{
			$dto->checklist = $task->checklist ?? [];
		}
		if ($request?->getRelation('group') !== null)
		{
			$dto->group = GroupDto::fromEntity($task->group ?? null, $request?->getRelation('group')?->getRequest());
		}
		if ($request?->getRelation('stage') !== null)
		{
			$dto->stage = StageDto::fromEntity($task->stage ?? null, $request?->getRelation('stage')?->getRequest());
		}
		if ($request?->getRelation('flow') !== null)
		{
			$dto->flow = isset($task->flow) ? FlowDto::fromEntity($task->flow, $request?->getRelation('flow')?->getRequest()) : null;
		}
		if (empty($select) || in_array('epicId', $select, true))
		{
			$dto->epicId = $task->epicId ?? null;
		}
		if (empty($select) || in_array('storyPoints', $select, true))
		{
			$dto->storyPoints = $task->storyPoints ?? null;
		}
		if (empty($select) || in_array('priority', $select, true))
		{
			$dto->priority = $task->priority?->value ?? null;
		}
		if (empty($select) || in_array('status', $select, true))
		{
			$dto->status = $task->status?->value ?? null;
		}
		if (empty($select) || in_array('statusChanged', $select, true))
		{
			$dto->statusChanged = $task->statusChangedTs ? DateTime::createFromTimestamp($task->statusChangedTs) : null;
		}
		if ($request?->getRelation('accomplices') !== null)
		{
			$dto->accomplices = $this->fillCollection(UserFieldDto::class, $task->accomplices?->getIterator(), $request?->getRelation('accomplices')?->getRequest());
		}
		if ($request?->getRelation('auditors') !== null)
		{
			$dto->auditors = $this->fillCollection(UserDto::class, $task->auditors?->getIterator(), $request?->getRelation('auditors')?->getRequest());
		}
		if ($request?->getRelation('parent') !== null)
		{
			$dto->parent = $task->parent && $task->parent->id !== 0 ? $this->mapByTaskAndRequest($task->parent ?? null, $request?->getRelation('parent')?->getRequest()) : null;
		}
		if (empty($select) || in_array('containsChecklist', $select, true))
		{
			$dto->containsChecklist = $task->containsChecklist ?? null;
		}
		if (empty($select) || in_array('containsSubTasks', $select, true))
		{
			$dto->containsSubTasks = $task->containsSubTasks ?? null;
		}
		if (empty($select) || in_array('containsRelatedTasks', $select, true))
		{
			$dto->containsRelatedTasks = $task->containsRelatedTasks ?? null;
		}
		if (empty($select) || in_array('containsGanttLinks', $select, true))
		{
			$dto->containsGanttLinks = $task->containsGanttLinks ?? null;
		}
		if (empty($select) || in_array('containsPlacements', $select, true))
		{
			$dto->containsPlacements = $task->containsPlacements ?? null;
		}
		if (empty($select) || in_array('containsResults', $select, true))
		{
			$dto->containsResults = $task->containsResults ?? null;
		}
		if (empty($select) || in_array('numberOfReminders', $select, true))
		{
			$dto->numberOfReminders = $task->numberOfReminders ?? null;
		}
		if (empty($select) || in_array('chatId', $select, true))
		{
			$dto->chatId = $task->chatId ?? null;
		}
		if ($request?->getRelation('chat') !== null)
		{
			$dto->chat = ChatDto::fromEntity($task->chat ?? null, $request?->getRelation('chat')?->getRequest());
		}
		if (empty($select) || in_array('plannedDuration', $select, true))
		{
			$dto->plannedDuration = $task->plannedDuration ?? null;
		}
		if (empty($select) || in_array('actualDuration', $select, true))
		{
			$dto->actualDuration = $task->actualDuration ?? null;
		}
		if (empty($select) || in_array('durationType', $select, true))
		{
			$dto->durationType = $task->durationType?->value ?? null;
		}
		if (empty($select) || in_array('started', $select, true))
		{
			$dto->started = $task->startedTs ? DateTime::createFromTimestamp($task->startedTs) : null;
		}
		if (empty($select) || in_array('estimatedTime', $select, true))
		{
			$dto->estimatedTime = $task->estimatedTime ?? null;
		}
		if (empty($select) || in_array('replicate', $select, true))
		{
			$dto->replicate = $task->replicate ?? null;
		}
		if (empty($select) || in_array('changed', $select, true))
		{
			$dto->changed = $task->changedTs ? DateTime::createFromTimestamp($task->changedTs) : null;
		}
		if ($request?->getRelation('changedBy') !== null)
		{
			$dto->changedBy = UserDto::fromEntity($task->changedBy ?? null, $request?->getRelation('changedBy')?->getRequest());
		}
		if ($request?->getRelation('statusChangedBy') !== null)
		{
			$dto->statusChangedBy = UserDto::fromEntity($task->statusChangedBy ?? null, $request?->getRelation('statusChangedBy')?->getRequest());
		}
		if ($request?->getRelation('closedBy') !== null)
		{
			$dto->closedBy = UserDto::fromEntity($task->closedBy ?? null, $request?->getRelation('closedBy')?->getRequest());
		}
		if (empty($select) || in_array('closed', $select, true))
		{
			$dto->closed = $task->closedTs ? DateTime::createFromTimestamp($task->closedTs) : null;
		}
		if (empty($select) || in_array('activity', $select, true))
		{
			$dto->activity = $task->activityTs ? DateTime::createFromTimestamp($task->activityTs) : null;
		}
		if (empty($select) || in_array('guid', $select, true))
		{
			$dto->guid = $task->guid ?? null;
		}
		if (empty($select) || in_array('xmlId', $select, true))
		{
			$dto->xmlId = !empty($task->xmlId) ? $task->xmlId : null;
		}
		if (empty($select) || in_array('exchangeId', $select, true))
		{
			$dto->exchangeId = !empty($task->exchangeId) ? $task->exchangeId : null;
		}
		if (empty($select) || in_array('exchangeModified', $select, true))
		{
			$dto->exchangeModified = !empty($task->exchangeModified) ? $task->exchangeModified : null;
		}
		if (empty($select) || in_array('outlookVersion', $select, true))
		{
			$dto->outlookVersion = $task->outlookVersion ?? null;
		}
		if (empty($select) || in_array('mark', $select, true))
		{
			$dto->mark = $task->mark?->value ?? null;
		}
		if (empty($select) || in_array('allowsChangeDeadline', $select, true))
		{
			$dto->allowsChangeDeadline = $task->allowsChangeDeadline ?? null;
		}
		if (empty($select) || in_array('allowsTimeTracking', $select, true))
		{
			$dto->allowsTimeTracking = $task->allowsTimeTracking ?? null;
		}
		if (empty($select) || in_array('matchesWorkTime', $select, true))
		{
			$dto->matchesWorkTime = $task->matchesWorkTime ?? null;
		}
		if (empty($select) || in_array('addInReport', $select, true))
		{
			$dto->addInReport = $task->addInReport ?? null;
		}
		if (empty($select) || in_array('isMultitask', $select, true))
		{
			$dto->isMultitask = $task->isMultitask ?? false;
		}
		if (empty($select) || in_array('siteId', $select, true))
		{
			$dto->siteId = $task->siteId ?? null;
		}
		if ($request?->getRelation('forkedByTemplate') !== null)
		{
			$dto->forkedByTemplate = isset($task->forkedByTemplate) ? TemplateDto::fromEntity($task->forkedByTemplate, $request?->getRelation('forkedByTemplate')?->getRequest()) : null;
		}
		if (empty($select) || in_array('deadlineCount', $select, true))
		{
			$dto->deadlineCount = $task->deadlineCount ?? null;
		}
		if (empty($select) || in_array('declineReason', $select, true))
		{
			$dto->declineReason = $task->declineReason ?? null;
		}
		if (empty($select) || in_array('forumTopicId', $select, true))
		{
			$dto->forumTopicId = $task->forumTopicId !== 0 ? $task->forumTopicId : null;
		}
		if ($request?->getRelation('tags') !== null)
		{
			$tagDtoCollection = new DtoCollection(TagDto::class);
			if ($task->tags !== null)
			{
				foreach ($task->tags->getIterator() as $tagEntity)
				{
					$tagDto = TagDto::fromEntity($tagEntity, $request?->getRelation('tags')?->getRequest());
					$tagDtoCollection->add($tagDto);
				}
			}
			$dto->tags = $tagDtoCollection;
		}
		if (empty($select) || in_array('link', $select, true))
		{
			$dto->link = $task->link ?? null;
		}
		if ($request?->getRelation('userFields') !== null)
		{
			$userFieldsCollection = new DtoCollection(UserFieldDto::class);
			if ($task->userFields !== null)
			{
				foreach ($task->userFields->getIterator() as $userFieldEntity)
				{
					$userFieldDto = UserFieldDto::fromEntity($userFieldEntity, $request?->getRelation('userFields')?->getRequest());
					$userFieldsCollection->add($userFieldDto);
				}
			}
			$dto->userFields = $userFieldsCollection;
		}
		if (empty($select) || in_array('rights', $select, true))
		{
			$dto->rights = $task->rights ?? [];
		}
		if (empty($select) || in_array('archiveLink', $select, true))
		{
			$dto->archiveLink = $task->archiveLink ?? null;
		}
		if (empty($select) || in_array('crmItemIds', $select, true))
		{
			$dto->crmItemIds = $task->crmItemIds ?? [];
		}
		if ($request?->getRelation('reminders') !== null)
		{
			$dto->reminders = isset($task->reminders) ? array_map(fn($r) => ReminderDto::fromEntity($r, $request?->getRelation('reminders')?->getRequest()), $task->reminders->getIterator() ?? []) : null;
		}
		if ($request?->getRelation('elapsedTime') !== null)
		{
			$dto->elapsedTime = isset($task->elapsedTime) ? ElapsedTimeDto::fromEntity($task->elapsedTime, $request?->getRelation('elapsedTime')?->getRequest()) : null;
		}
		if ($request?->getRelation('source') !== null)
		{
			$dto->source = isset($task->source) ? SourceDto::fromEntity($task->source, $request?->getRelation('source')?->getRequest()) : null;
		}
		if ($request?->getRelation('email') !== null)
		{
			$dto->email = isset($task->email) ? EmailDto::fromEntity($task->email, $request?->getRelation('email')?->getRequest()) : null;
		}
		if (empty($select) || in_array('dependsOn', $select, true))
		{
			$dto->dependsOn = $task->dependsOn ?? [];
		}
		if (empty($select) || in_array('requireResult', $select, true))
		{
			$dto->requireResult = $task->requireResult ?? null;
		}
		if (empty($select) || in_array('matchesSubTasksTime', $select, true))
		{
			$dto->matchesSubTasksTime = $task->matchesSubTasksTime ?? null;
		}
		if (empty($select) || in_array('autocompleteSubTasks', $select, true))
		{
			$dto->autocompleteSubTasks = $task->autocompleteSubTasks ?? null;
		}
		if (empty($select) || in_array('allowsChangeDatePlan', $select, true))
		{
			$dto->allowsChangeDatePlan = $task->allowsChangeDatePlan ?? null;
		}
		if (empty($select) || in_array('maxDeadlineChangeDate', $select, true))
		{
			$dto->maxDeadlineChangeDate = $task->maxDeadlineChangeDate ?? null;
		}
		if (empty($select) || in_array('maxDeadlineChanges', $select, true))
		{
			$dto->maxDeadlineChanges = $task->maxDeadlineChanges ?? null;
		}
		if (empty($select) || in_array('requireDeadlineChangeReason', $select, true))
		{
			$dto->requireDeadlineChangeReason = $task->requireDeadlineChangeReason ?? null;
		}
		if (empty($select) || in_array('inFavorite', $select, true))
		{
			$dto->inFavorite = $task->inFavorite ?? [];
		}
		if (empty($select) || in_array('inPin', $select, true))
		{
			$dto->inPin = $task->inPin ?? [];
		}
		if (empty($select) || in_array('inGroupPin', $select, true))
		{
			$dto->inGroupPin = $task->inGroupPin ?? [];
		}
		if (empty($select) || in_array('inMute', $select, true))
		{
			$dto->inMute = $task->inMute ?? [];
		}
		if (empty($select) || in_array('scenarios', $select, true))
		{
			$dto->scenarios = $task->scenarios?->toArray() ?? [];
		}

		return $dto;
	}

	public function getTaskByDto(TaskDto $dto): Task
	{
		$dtoArray = $dto->toArray(true);

		$fieldMap = [
			'deadline' => 'deadlineTs',
			'startPlan' => 'startPlanTs',
			'endPlan' => 'endPlanTs',
			'statusChanged' => 'statusChangedTs',
			'started' => 'startedTs',
			'changed' => 'changedTs',
			'closed' => 'closedTs',
			'activity' => 'activityTs',
		];

		$data = [];
		foreach ($dtoArray as $key => $value)
		{
			$taskKey = $fieldMap[$key] ?? $key;
			if ($value instanceof Date)
			{
				$data[$taskKey] = $value->getTimestamp();
			}
			else
			{
				$data[$taskKey] = $value;
			}
		}

		return Task::mapFromArray($data);
	}

	private function fillCollection(string $dtoClass, \ArrayIterator|array|null $entities, ?Request $request): DtoCollection
	{
		$dtoCollection = new DtoCollection($dtoClass);
		if (empty($entities))
		{
			return $dtoCollection;
		}
		foreach ($entities as $entity)
		{
			$entityDto = $this->mapByTaskAndRequest($entity, $request);
			$dtoCollection->add($entityDto);
		}

		return $dtoCollection;
	}
}
