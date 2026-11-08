<?php

namespace Bitrix\Crm\Timeline\Tasks;

use Bitrix\Crm\Activity\Provider\Tasks\Task;
use Bitrix\Crm\Activity\Provider\Tasks\Comment;
use Bitrix\Crm\Activity\Provider\Tasks\TaskActivityStatus;
use Bitrix\Crm\Activity\Provider\Tasks\TaskActivityState;
use Bitrix\Crm\ActivityBindingTable;
use Bitrix\Crm\ActivityTable;
use Bitrix\Crm\EO_Activity;
use Bitrix\Crm\Integration\Tasks\TaskCounter;
use Bitrix\Crm\Integration\Tasks\TaskObject;
use Bitrix\Crm\Integration\Tasks\TaskSearchIndex;
use Bitrix\Crm\Item;
use Bitrix\Crm\ItemIdentifier;
use Bitrix\Crm\Search\SearchEnvironment;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Timeline\ActivityController;
use Bitrix\Crm\Timeline\FactoryBasedController;
use Bitrix\Crm\Integration\Tasks\Service\TriggerService;
use Bitrix\Crm\Timeline\TimelineEntry;
use Bitrix\Crm\Timeline\TimelineEntry\Facade;
use Bitrix\Crm\Timeline\TimelineType;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;
use Bitrix\Tasks\Integration\CRM\Timeline\Bindings;
use Bitrix\Tasks\V2\Public\Entity\TaskState;
use CCrmOwnerType;

final class Controller extends FactoryBasedController
{
	private const MAX_RETURN_TO_WORK_ATTEMPTS = 3;

	private static ?self $instance = null;
	private ActivityController $activityController;
	private Task $taskActivityProvider;
	private Comment $commentActivityProvider;
	private TaskActivityStatus $taskActivityStatus;
	private TriggerService $triggerService;

	protected function getTrackedFieldNames(): array
	{
		return [];
	}

	protected function __construct()
	{
		parent::__construct();
		$this->activityController = ActivityController::getInstance();
		$this->taskActivityProvider = new Task();
		$this->commentActivityProvider = new Comment();
		$this->taskActivityStatus = new TaskActivityStatus();
		$this->triggerService = new TriggerService();
	}

	public function prepareSearchContent(array $params): string
	{
		$typeId = (int)$params['TYPE_ID'];
		$sourceId = (int)$params['SOURCE_ID'];
		$taskId = (int)($typeId === TimelineType::TASK) * $sourceId;
		if ($taskId <= 0)
		{
			return '';
		}

		return SearchEnvironment::prepareToken(TaskSearchIndex::getTaskSearchIndex($taskId));
	}

	public static function getInstance(): self
	{
		if (is_null(self::$instance))
		{
			self::$instance = new self();
		}

		return self::$instance;
	}

	public function onTaskCommentDeleted(Bindings $bindings, array $timelineParams): void
	{
		$this->refreshCommentActivity($bindings, $timelineParams);
	}

	public function onTaskDeadLineChanged(Bindings $bindings, array $timelineParams): void
	{
		[$bindings, $timelineParams] = $this->prepareParams($bindings, $timelineParams);
		if ($bindings->isEmpty())
		{
			return;
		}
		$this->handleTaskEvent(CategoryType::DEADLINE_CHANGED, $bindings, $timelineParams);
	}

	public function onTaskAdded(Bindings $bindings, array $timelineParams): void
	{
		$this->handleTaskEvent(CategoryType::TASK_ADDED, $bindings, $timelineParams);
	}

	public function onTaskDescriptionChanged(Bindings $bindings, array $timelineParams): void
	{
		[$bindings, $timelineParams] = $this->prepareParams($bindings, $timelineParams);
		if ($bindings->isEmpty())
		{
			return;
		}

		$this->handleTaskEvent(CategoryType::DESCRIPTION_CHANGED, $bindings, $timelineParams);
	}

	public function onTaskPriorityChanged(Bindings $bindings, array $timelineParams): void
	{
		[$bindings, $timelineParams] = $this->prepareParams($bindings, $timelineParams);
		if ($bindings->isEmpty())
		{
			return;
		}

		$this->handleTaskEvent(CategoryType::PRIORITY_CHANGED, $bindings, $timelineParams);
	}

	public function onTaskDisapproved(Bindings $bindings, array $timelineParams): void
	{
		$taskId = $timelineParams['TASK_ID'] ?? null;
		if (is_null($taskId))
		{
			return;
		}

		[$bindings, $timelineParams] = $this->prepareParams($bindings, $timelineParams);
		if ($bindings->isEmpty())
		{
			return;
		}

		$activity = $this->taskActivityProvider->find($taskId);
		if (is_null($activity))
		{
			return;
		}

		$this->handleTaskEvent(CategoryType::DISAPPROVED, $bindings, $timelineParams);
		$completedActivityEntryId = $this->taskActivityProvider->deleteLogEntry($activity->getId(), $taskId);
		if ($completedActivityEntryId > 0)
		{
			foreach ($bindings as $identifier)
			{
				$this->sendPullEventOnDelete($identifier, $completedActivityEntryId);
			}
		}
	}

	public function onTaskResponsibleChanged(Bindings $bindings, array $timelineParams): void
	{
		[$bindings, $timelineParams] = $this->prepareParams($bindings, $timelineParams);
		if ($bindings->isEmpty())
		{
			return;
		}

		$this->handleTaskEvent(CategoryType::RESPONSIBLE_CHANGED, $bindings, $timelineParams);
	}

	public function onTaskAccompliceAdded(Bindings $bindings, array $timelineParams): void
	{
		[$bindings, $timelineParams] = $this->prepareParams($bindings, $timelineParams);
		if ($bindings->isEmpty())
		{
			return;
		}

		$this->handleTaskEvent(CategoryType::ACCOMPLICE_ADDED, $bindings, $timelineParams);
	}

	public function onTaskAuditorAdded(Bindings $bindings, array $timelineParams): void
	{
		[$bindings, $timelineParams] = $this->prepareParams($bindings, $timelineParams);
		if ($bindings->isEmpty())
		{
			return;
		}

		$this->handleTaskEvent(CategoryType::AUDITOR_ADDED, $bindings, $timelineParams);
	}

	public function onTaskGroupChanged(Bindings $bindings, array $timelineParams): void
	{
		[$bindings, $timelineParams] = $this->prepareParams($bindings, $timelineParams);
		if ($bindings->isEmpty())
		{
			return;
		}

		$this->handleTaskEvent(CategoryType::GROUP_CHANGED, $bindings, $timelineParams);
	}

	public function onTaskExpired(Bindings $bindings, array $timelineParams): Result
	{
		$result = new Result();
		$taskId = (int)($timelineParams['TASK_ID'] ?? 0);

		$syncResult = $this->taskActivityProvider->syncExpiredStatusWithTask($taskId);
		$result->addErrors($syncResult->getErrors());
		if ($this->isTaskOrActivityMissing($syncResult))
		{
			return $result;
		}

		[$bindings, $timelineParams] = $this->prepareParams($bindings, $timelineParams);
		$this->sendPullEventsOnDelete($bindings, $syncResult->getDeletedCompletionEntryIds());
		if ($bindings->isEmpty())
		{
			return $result;
		}

		return $result->addErrors(
			$this->handleTaskEvent(CategoryType::EXPIRED, $bindings, $timelineParams)->getErrors(),
		);
	}

	public function onTaskResultAdded(Bindings $bindings, array $timelineParams): void
	{
		[$bindings, $timelineParams] = $this->prepareParams($bindings, $timelineParams);
		if ($bindings->isEmpty())
		{
			return;
		}

		$this->handleTaskEvent(CategoryType::RESULT_ADDED, $bindings, $timelineParams);
	}

	public function onTaskStatusChanged(Bindings $bindings, array $timelineParams): Result
	{
		$result = new Result();
		$taskId = (int)($timelineParams['TASK_ID'] ?? 0);
		if ($taskId <= 0)
		{
			return $result;
		}

		$syncResult = $this->taskActivityProvider->syncStateWithTask($taskId);
		$result->addErrors($syncResult->getErrors());
		if ($this->isTaskOrActivityMissing($syncResult))
		{
			return $result;
		}

		[$bindings, $timelineParams] = $this->prepareParams($bindings, $timelineParams);
		$this->sendPullEventsOnDelete($bindings, $syncResult->getDeletedCompletionEntryIds());
		if ($bindings->isEmpty())
		{
			return $result;
		}

		$result->addErrors(
			$this->handleTaskEvent(CategoryType::STATUS_CHANGED, $bindings, $timelineParams)->getErrors(),
		);
		if ($result->isSuccess() && $this->isTaskReturnedToWork($timelineParams))
		{
			$result->addErrors(
				$this->returnTaskActivityToWork(
					$bindings,
					$timelineParams,
					$syncResult->getInitialActivityState(),
				)->getErrors(),
			);
		}

		$status = (int)($timelineParams['TASK_CURRENT_STATUS'] ?? 0);
		if ($status > 0)
		{
			$this->triggerService->executeTriggers($bindings, $taskId, $status);
		}

		return $result;
	}

	public function onTaskChecklistAdded(Bindings $bindings, array $timelineParams): void
	{
		[$bindings, $timelineParams] = $this->prepareParams($bindings, $timelineParams);
		if ($bindings->isEmpty())
		{
			return;
		}

		$this->handleTaskEvent(CategoryType::CHECKLIST_ADDED, $bindings, $timelineParams);
	}

	public function onTaskViewed(Bindings $bindings, array $timelineParams): void
	{
		[$bindings, $timelineParams] = $this->prepareParams($bindings, $timelineParams);
		if ($bindings->isEmpty())
		{
			return;
		}

		$this->handleTaskEvent(CategoryType::VIEWED, $bindings, $timelineParams);
	}

	public function onTaskPingSent(Bindings $bindings, array $timelineParams): void
	{
		[$bindings, $timelineParams] = $this->prepareParams($bindings, $timelineParams);
		if ($bindings->isEmpty())
		{
			return;
		}

		$this->handleTaskEvent(CategoryType::PING_SENT, $bindings, $timelineParams);
	}

	public function onTaskCommentAdded(Bindings $bindings, array $timelineParams): void
	{
		$bindings = $this->filterBindings($bindings, $timelineParams);
		if ($bindings->isEmpty())
		{
			return;
		}

		$this->handleCommentActivity($bindings, CategoryType::COMMENT_ADD, $timelineParams);
	}

	public function onTaskRenew(Bindings $bindings, array $timelineParams): Result
	{
		$result = new Result();
		$taskId = $timelineParams['TASK_ID'] ?? null;
		if (is_null($taskId))
		{
			return $result;
		}
		$bindings = $this->filterBindings($bindings, $timelineParams);
		if ($bindings->isEmpty())
		{
			return $result;
		}

		return $result->addErrors(
			$this->returnTaskActivityToWork($bindings, $timelineParams)->getErrors(),
		);
	}

	/**
	 * Undoing a completion goes by the state of the activity, not by the event that led here: a return to work
	 * publishes a status change and a renew, both of them arrive at this point independently, and either has to
	 * be able to finish what the other one left undone.
	 */
	private function returnTaskActivityToWork(
		Bindings $bindings,
		array $timelineParams,
		?TaskActivityState $initialActivityState = null,
	): Result
	{
		$result = new Result();
		$taskId = (int)($timelineParams['TASK_ID'] ?? 0);
		$activity = $this->taskActivityProvider->find($taskId, true);
		if ($activity === null)
		{
			return $result;
		}

		// the completion record is the only marker of a completion to undo: without it nothing was overwritten
		$completedActivityEntryIds = (
			$initialActivityState !== null
			&& $initialActivityState->activityId === $activity->getId()
			&& $initialActivityState->completedEntryIds !== null
		)
			? $initialActivityState->completedEntryIds
			: $this->taskActivityProvider->getCompletedActivityEntryIds($activity->getId(), $taskId)
		;
		if ($completedActivityEntryIds === [])
		{
			return $result;
		}

		$taskState = $this->taskActivityProvider->getCurrentTaskStateSnapshot($taskId);
		if ($taskState === null || !$this->isActiveTaskState($taskState->status))
		{
			return $result;
		}

		if ($initialActivityState === null || $initialActivityState->activityId !== $activity->getId())
		{
			$activitySettings = $activity->getSettings();
			$activityEndTime = $activity->getEndTime();
			$initialActivityState = new TaskActivityState(
				activityId: $activity->getId(),
				completed: $activity->getCompleted(),
				status: is_array($activitySettings) ? ($activitySettings['ACTIVITY_STATUS'] ?? null) : null,
				endTime: $activityEndTime === null ? null : clone $activityEndTime,
				completedEntryIds: $completedActivityEntryIds,
			);
		}

		for ($attempt = 1; $attempt <= self::MAX_RETURN_TO_WORK_ATTEMPTS; $attempt++)
		{
			if ($this->isActiveTaskState($taskState->status))
			{
				$taskStateBeforeWrite = $taskState;
				$entryIdsToDelete = $this->taskActivityProvider->getCompletedActivityEntryIds(
					$activity->getId(),
					$taskId,
				);
				$stateResult = $this->taskActivityProvider->restoreState(
					$activity,
					false,
					$this->taskActivityStatus->onStatusChange(
						$taskStateBeforeWrite->status,
						$taskStateBeforeWrite->isExpired,
					),
					$this->getTaskActivityEndTime($taskStateBeforeWrite),
				);
				if (!$stateResult->isSuccess())
				{
					return $result->addErrors($stateResult->getErrors());
				}

				$activity = $this->taskActivityProvider->find($taskId, true);
				$taskState = $this->taskActivityProvider->getCurrentTaskStateSnapshot($taskId);
				if ($activity === null || $taskState === null)
				{
					return $result;
				}

				if (
					!$activity->getCompleted()
					&& $taskStateBeforeWrite->isEqualTo($taskState)
				)
				{
					$this->deleteCompletedActivityEntries($bindings, $entryIdsToDelete);

					return $result;
				}

				continue;
			}

			$syncResult = $this->taskActivityProvider->syncStateWithTask($taskId);
			if (!$syncResult->isSuccess())
			{
				return $result->addErrors($syncResult->getErrors());
			}

			$activity = $this->taskActivityProvider->find($taskId, true);
			$taskState = $this->taskActivityProvider->getCurrentTaskStateSnapshot($taskId);
			if ($activity === null || $taskState === null)
			{
				return $result;
			}

			if ($this->isActiveTaskState($taskState->status))
			{
				continue;
			}

			if (
				$this->taskActivityStatus->onStatusChange($taskState->status, $taskState->isExpired) === ''
			)
			{
				$restoreResult = $this->restoreCompletionWithoutReplacingEntries(
					$bindings,
					$taskId,
					$activity->getId(),
					$completedActivityEntryIds,
					$initialActivityState->status,
					$initialActivityState->endTime,
				);
				if (!$restoreResult->isSuccess())
				{
					return $result->addErrors($restoreResult->getErrors());
				}

				$taskState = $this->taskActivityProvider->getCurrentTaskStateSnapshot($taskId);
				if (
					$taskState !== null
					&& $this->taskActivityStatus->onStatusChange($taskState->status, $taskState->isExpired) === ''
				)
				{
					return $result;
				}

				continue;
			}

			if (
				!$activity->getCompleted()
				|| !$this->taskActivityStatus->isCompletedTaskState($taskState->status)
			)
			{
				continue;
			}

			$this->deleteCompletedActivityEntries($bindings, $completedActivityEntryIds);

			return $result;
		}

		return $result->addError(new Error(
			'Task state kept changing while its activity was being returned to work.',
			Task::ERROR_TASK_STATE_CHANGED,
			['taskId' => $taskId],
		));
	}

	private function getTaskActivityEndTime(TaskState $taskState): ?DateTime
	{
		$endTimeTs = $taskState->endPlanTs ?? $taskState->deadlineTs;

		return $endTimeTs === null ? null : DateTime::createFromTimestamp($endTimeTs);
	}

	/**
	 * @param int[] $preservedEntryIds
	 */
	private function restoreCompletionWithoutReplacingEntries(
		Bindings $bindings,
		int $taskId,
		int $activityId,
		array $preservedEntryIds,
		?string $preservedStatus,
		?DateTime $preservedEndTime,
	): Result
	{
		$result = new Result();
		$activity = $this->taskActivityProvider->find($taskId, true);
		if ($activity === null)
		{
			return $result->addError(new Error(
				'Task activity was not found.',
				Task::ERROR_ACTIVITY_NOT_FOUND,
				['taskId' => $taskId],
			));
		}

		$stateResult = $this->taskActivityProvider->restoreState(
			$activity,
			true,
			$preservedStatus,
			$preservedEndTime,
		);
		if (!$stateResult->isSuccess())
		{
			return $result->addErrors($stateResult->getErrors());
		}

		$currentEntryIds = $this->taskActivityProvider->getCompletedActivityEntryIds($activityId, $taskId);
		$technicalEntryIds = array_values(array_diff($currentEntryIds, $preservedEntryIds));
		$this->deleteCompletedActivityEntries($bindings, $technicalEntryIds);

		return $result;
	}

	/**
	 * @param int[] $entryIds
	 */
	private function deleteCompletedActivityEntries(Bindings $bindings, array $entryIds): void
	{
		foreach ($entryIds as $entryId)
		{
			TimelineEntry::delete($entryId);
			foreach ($bindings as $identifier)
			{
				$this->sendPullEventOnDelete($identifier, $entryId);
			}
		}
	}

	/**
	 * @param int[] $entryIds
	 */
	private function sendPullEventsOnDelete(Bindings $bindings, array $entryIds): void
	{
		foreach ($entryIds as $entryId)
		{
			foreach ($bindings as $identifier)
			{
				$this->sendPullEventOnDelete($identifier, $entryId);
			}
		}
	}

	/**
	 * A state without a projection (deferred, declined) postpones the cleanup instead of doing it: it keeps
	 * the completion effects and publishes no renew event, so they surface again on the next step of the
	 * chain. Every arrival into work has to look for them, not only the one right after a completed state.
	 */
	private function isTaskReturnedToWork(array $timelineParams): bool
	{
		$previousStatus = (int)($timelineParams['TASK_PREVIOUS_STATUS'] ?? 0);
		$currentStatus = (int)($timelineParams['TASK_CURRENT_STATUS'] ?? 0);

		return !$this->isActiveTaskState($previousStatus)
			&& $this->isActiveTaskState($currentStatus);
	}

	public function onTaskDeleted(Bindings $bindings, array $timelineParams): void
	{
		$taskId = $timelineParams['TASK_ID'] ?? null;
		if (is_null($taskId))
		{
			return;
		}

		$taskActivity = $this->taskActivityProvider->find($taskId);
		if (is_null($taskActivity))
		{
			return;
		}

		$this->taskActivityProvider->delete($taskActivity->getId());

		foreach ($bindings as $identifier)
		{
			$this->commentActivityProvider->deleteByItem($taskId, $identifier);
		}
	}

	public function onTaskBindingsUpdated(Bindings $bindings, array $timelineParams): void
	{
		$taskId = $timelineParams['TASK_ID'] ?? null;
		if (is_null($taskId))
		{
			return;
		}

		$oldActivity = $this->getOldTaskActivity($taskId);
		if (!is_null($oldActivity))
		{
			return;
		}

		$this->handleTaskEvent(CategoryType::BINDINGS_UPDATED, $bindings, $timelineParams);
	}

	public function OnTaskDatePlanUpdated(Bindings $bindings, array $timelineParams): void
	{
		$bindings = $this->filterBindings($bindings, $timelineParams);
		if ($bindings->isEmpty())
		{
			return;
		}

		$this->handleTaskEvent(CategoryType::DATE_PLAN__UPDATED, $bindings, $timelineParams);
	}

	public function onTaskTitleUpdated(Bindings $bindings, array $timelineParams): void
	{
		[$bindings, $timelineParams] = $this->prepareParams($bindings, $timelineParams);
		if ($bindings->isEmpty())
		{
			return;
		}

		$this->handleTaskEvent(CategoryType::TITLE_UPDATED, $bindings, $timelineParams);
	}
	public function onTaskFilesUpdated(Bindings $bindings, array $timelineParams): void
	{
		$taskId = $timelineParams['TASK_ID'] ?? null;
		if (is_null($taskId))
		{
			return;
		}

		$bindings = $this->filterBindings($bindings, $timelineParams);
		if ($bindings->isEmpty())
		{
			return;
		}

		$activity = $this->taskActivityProvider->find($taskId);
		$this->taskActivityProvider->updateFiles($activity, $timelineParams);
	}

	public function onTaskCompleted(Bindings $bindings, array $timelineParams): void
	{
		$taskId = $timelineParams['TASK_ID'] ?? null;
		if (is_null($taskId))
		{
			return;
		}

		$bindings = $this->filterBindings($bindings, $timelineParams);

		if ($bindings->isEmpty())
		{
			return;
		}

		$activity = $this->taskActivityProvider->find($taskId);
		if (is_null($activity))
		{
			return;
		}

		$closedDate = TaskObject::getObject($taskId)->getClosedDate();
		$this->taskActivityProvider->setEndTime($activity, $closedDate);
		$this->taskActivityProvider->complete($activity);
	}

	public function refreshTaskActivity(Bindings $bindings, array $timelineParams): void
	{
		$taskId = $timelineParams['TASK_ID'] ?? null;
		if (is_null($taskId))
		{
			return;
		}

		if ($bindings->isEmpty())
		{
			return;
		}

		$activity = $this->taskActivityProvider->find($taskId, true);
		if (!is_null($activity))
		{
			$activity = $activity->collectValues();
			foreach ($bindings as $identifier)
			{
				if (!Container::getInstance()->getTimelinePusher()->isDetailsPageChannelActive($identifier))
				{
					continue;
				}

				$responsibleId = $this->getAssignedByEntity($identifier);
				$activity = $this->activityController->prepareScheduledData($activity, $identifier);
				$this->activityController->sendPreparedPullEventOnUpdateScheduled($identifier, $activity, $responsibleId);
			}
		}
	}

	public function refreshCommentActivity(Bindings $bindings, array $timelineParams): void
	{
		$taskId = $timelineParams['TASK_ID'] ?? null;
		if (is_null($taskId))
		{
			return;
		}

		$bindings = $this->filterBindings($bindings, $timelineParams);
		if ($bindings->isEmpty())
		{
			return;
		}

		$taskActivity = $this->taskActivityProvider->find($taskId);
		foreach ($bindings as $identifier)
		{
			$activity = $this->commentActivityProvider->find($taskId, $identifier);
			if (!is_null($activity))
			{
				$unreadCommentsCount = TaskCounter::getCommentsCount($taskId, $activity->getResponsibleId());
				if ($unreadCommentsCount === 0)
				{
					$associatedTimelineEntry = $this->commentActivityProvider->getAssociatedTimelineEntry($taskActivity);
					TimelineEntry::delete($associatedTimelineEntry->getId());
					$this->commentActivityProvider->delete($activity->getId());
					$this->sendPullEventOnDelete($identifier, $associatedTimelineEntry->getId());
				}
				else
				{
					$this->commentActivityProvider->refresh($activity, new Bindings($identifier), $timelineParams);
				}
			}
		}
	}

	public function onTaskAllCommentViewed(Bindings $bindings, array $timelineParams): void
	{
		$timelineParams = $this->filterParams($timelineParams);
		if ($bindings->isEmpty())
		{
			return;
		}
		$taskId = $timelineParams['TASK_ID'];

		foreach ($bindings as $identifier)
		{
			$responsibleId = $this->getAssignedByEntity($identifier);
			if (is_null($responsibleId))
			{
				return;
			}
			$authorId = $timelineParams['AUTHOR_ID'];

			if ($responsibleId === $authorId)
			{
				$unreadCommentsCount = TaskCounter::getCommentsCount($taskId, $responsibleId);
				if ($unreadCommentsCount === 0)
				{
					$activity = $this->commentActivityProvider->find($taskId, $identifier);
					if (!is_null($activity))
					{
						$this->commentActivityProvider->delete($activity->getId());
						$timelineParams['SKIP_BINDINGS_UPDATE'] = true;
						$this->handleTaskEvent(CategoryType::ALL_COMMENT_VIEWED, new Bindings(...[$identifier]), $timelineParams);
					}
				}
			}
		}
	}

	protected function handleTaskEvent(int $typeCategoryId, Bindings $bindings, array $timelineParams): Result
	{
		$result = new Result();
		if ($typeCategoryId === CategoryType::TASK_ADDED)
		{
			$this->handleTaskActivityOnNewTask($bindings, $typeCategoryId, $timelineParams);

			return $result;
		}
		$this->handleTaskTimeline($typeCategoryId, $timelineParams, $bindings);

		return $this->handleTaskActivity($typeCategoryId, $timelineParams, $bindings);
	}

	private function handleTaskTimeline(int $typeCategoryId, array $timelineParams, Bindings $bindings): void
	{
		if (isset($timelineParams['IGNORE_IN_LOGS']) && $timelineParams['IGNORE_IN_LOGS'] === true)
		{
			return;
		}

		$timelineEntry = $this->getTimelineEntryFacade()->create(
			Facade::TASK,
			[
				'TYPE_CATEGORY_ID' => $typeCategoryId,
				'AUTHOR_ID' => $timelineParams['AUTHOR_ID'] ?? null,
				'SETTINGS' => $timelineParams,
				'BINDINGS' => $bindings,
			],
		);

		if ($timelineEntry === 0)
		{
			return;
		}

		foreach ($bindings as $identifier)
		{
			$this->sendPullEventOnAdd($identifier, $timelineEntry);
		}
	}

	private function handleTaskActivity(int $typeCategoryId, array $params, Bindings $bindings): Result
	{
		$result = new Result();
		$taskId = $params['TASK_ID'] ?? null;
		if(is_null($taskId))
		{
			return $result;
		}

		$desiredStatus = null;
		switch ($typeCategoryId)
		{
			case CategoryType::DEADLINE_CHANGED:
				$this->taskActivityProvider->updateDeadline($taskId, $params);
				$desiredStatus = TaskActivityStatus::STATUS_DEADLINE_CHANGED;
				break;

			case CategoryType::VIEWED:
				$desiredStatus = TaskActivityStatus::STATUS_VIEWED;
				break;

			case CategoryType::STATUS_CHANGED:
				break;

			case CategoryType::RESULT_ADDED:
				$desiredStatus = TaskActivityStatus::STATUS_RESULT_ADDED;
				break;

			case CategoryType::EXPIRED:
				break;

			case CategoryType::DESCRIPTION_CHANGED:
				$this->taskActivityProvider->updateDescription($params);
				$desiredStatus = TaskActivityStatus::STATUS_UPDATED;
				break;

			case CategoryType::RESPONSIBLE_CHANGED:
			case CategoryType::ACCOMPLICE_ADDED:
			case CategoryType::AUDITOR_ADDED:
			case CategoryType::CHECKLIST_ADDED:
			case CategoryType::GROUP_CHANGED:
			case CategoryType::TASK_UPDATED:
				$desiredStatus = TaskActivityStatus::STATUS_UPDATED;
				break;

			case CategoryType::DISAPPROVED:
				$activity = $this->taskActivityProvider->find($taskId);
				if (!is_null($activity))
				{
					$this->taskActivityProvider->renew($activity->getId());
				}
				break;
		}

		if ($desiredStatus && $this->isActivityStatusUpdateRequired($params, $bindings, $desiredStatus))
		{
			$result = $this->taskActivityProvider->updateStatus(
				$taskId,
				$desiredStatus
			);
		}

		if (!isset($params['SKIP_BINDINGS_UPDATE']) || $params['SKIP_BINDINGS_UPDATE'] === false)
		{
			$this->taskActivityProvider->updateBindings($bindings, $this->getCurrentBindings($params), $params);
		}

		$this->taskActivityProvider->updateByTask($params);

		if (isset($params['REFRESH_TASK_ACTIVITY']) && $params['REFRESH_TASK_ACTIVITY'] === true)
		{
			$this->refreshTaskActivity($bindings, $params);
		}

		return $result;
	}

	private function handleTaskActivityOnNewTask(Bindings $bindings, int $typeId, array $timelineParams): void
	{
		$taskId = $timelineParams['TASK_ID'] ?? null;
		if (is_null($taskId))
		{
			return;
		}

		$firstIdentifier = $bindings->getFirst();
		$responsibleId = $this->getAssignedByEntity($firstIdentifier);
		if (is_null($responsibleId))
		{
			return;
		}

		$activity = $this->taskActivityProvider->find($taskId);
		if (is_null($activity) || $activity->getCompleted())
		{
			$authorId = $timelineParams['AUTHOR_ID'] ?? 0;
			$taskResponsibleId = $timelineParams['RESPONSIBLE_ID'] ?? 0;
			$timelineParams['ACTIVITY_STATUS'] = ($authorId === $taskResponsibleId)
				? TaskActivityStatus::STATUS_VIEWED
				: TaskActivityStatus::STATUS_CREATED;

			$result = $this->taskActivityProvider->createActivity(
				Task::getProviderTypeId(),
				$this->taskActivityProvider->prepareFields($taskId, $bindings, $timelineParams)
			);
			if ($result->isSuccess())
			{
				$timelineParams['ASSOCIATED_ENTITY_TYPE_ID'] = CCrmOwnerType::Activity;
				$timelineParams['ASSOCIATED_ENTITY_ID'] = $result->getData()['id'];
				$this->handleTaskTimeline($typeId, $timelineParams, $bindings);
			}
		}
	}

	private function handleCommentActivity(Bindings $bindings, int $typeId, array $timelineParams): void
	{
		[$bindings, $timelineParams] = $this->prepareParams($bindings, $timelineParams);

		foreach ($bindings as $identifier)
		{
			$taskId = $timelineParams['TASK_ID'] ?? null;
			if (is_null($taskId))
			{
				return;
			}

			$fromUser = $timelineParams['FROM_USER'] ?? 0;
			$responsibleId = $this->getAssignedByEntity($identifier);

			if (is_null($responsibleId))
			{
				return;
			}

			if ($fromUser === $responsibleId)
			{
				continue;
			}

			$activity = $this->commentActivityProvider->find($taskId, $identifier);

			if (is_null($activity) || $activity->getCompleted())
			{
				$result = $this->commentActivityProvider->createActivity(
					Comment::getProviderTypeId(),
					$this->commentActivityProvider->prepareFields($taskId, $responsibleId, $identifier, $timelineParams)
				);

				if ($result->isSuccess())
				{
					$timelineParams['SKIP_BINDINGS_UPDATE'] = true;
					$this->handleTaskEvent($typeId, new Bindings(...[$identifier]), $timelineParams);
				}
			}
			else
			{
				$this->commentActivityProvider->update($activity, $timelineParams);
			}
		}
	}

	public function getAssignedByEntity(?ItemIdentifier $identifier): ?int
	{
		if ($identifier === null)
		{
			return null;
		}

		$factory = Container::getInstance()->getFactory($identifier->getEntityTypeId());
		if ($factory === null)
		{
			return null;
		}

		$assignedByFieldName = $factory->getEntityFieldNameByMap(Item::FIELD_NAME_ASSIGNED);

		$data = $factory->getDataClass()::getList([
			'select' => [
				$assignedByFieldName,
			],
			'filter' => [
				Item::FIELD_NAME_ID => $identifier->getEntityId(),
			],
			'limit' => 1,
		])->fetch() ?? [];

		$assignedBy = (int)($data[$assignedByFieldName] ?? null);

		return $assignedBy > 0 ? $assignedBy : null;
	}

	public function prepareHistoryDataModel(array $data, array $options = null): array
	{
		$data = array_merge($data, is_array($data['SETTINGS']) ? $data['SETTINGS'] : []);

		return parent::prepareHistoryDataModel($data, $options);
	}

	private function prepareParams(Bindings $bindings, array $timelineParams): array
	{
		$taskId = $timelineParams['TASK_ID'] ?? null;
		if (is_null($taskId))
		{
			return [new Bindings(), []];
		}

		$activity = $this->taskActivityProvider->find($timelineParams['TASK_ID']);
		if (is_null($activity))
		{
			return [new Bindings(), []];
		}

		$timelineParams = $this->filterParams($timelineParams);
		$bindings = $this->filterBindings($bindings, $timelineParams);

		return [$bindings, $timelineParams];
	}

	private function filterParams(array $timelineParams): array
	{
		$taskId = $timelineParams['TASK_ID'] ?? null;
		if (is_null($taskId))
		{
			return [];
		}

		$activity = $this->taskActivityProvider->find($timelineParams['TASK_ID']);
		if (is_null($activity))
		{
			return [];
		}

		$timelineParams['ASSOCIATED_ENTITY_TYPE_ID'] = CCrmOwnerType::Activity;
		$timelineParams['ASSOCIATED_ENTITY_ID'] = $activity->getId();

		return $timelineParams;
	}

	private function filterBindings(Bindings $bindings, array $timelineParams): Bindings
	{
		$activity = $this->taskActivityProvider->find($timelineParams['TASK_ID']);
		if (is_null($activity))
		{
			return new Bindings();
		}
		$query = ActivityBindingTable::query();
		$query
			->setSelect(['ID', 'OWNER_ID', 'OWNER_TYPE_ID'])
			->where('ACTIVITY_ID', $activity->getId())
		;

		$currentBindings = $query->exec()->fetchCollection();
		$result = new Bindings();
		foreach ($currentBindings as $activityIdentifier)
		{
			$identifier = new ItemIdentifier($activityIdentifier->getOwnerTypeId(), $activityIdentifier->getOwnerId());
			if ($bindings->contains($identifier))
			{
				$result->add($identifier);
			}

		}

		return $result;
	}

	public function getCurrentBindings(array $timelineParams): Bindings
	{
		$activity = $this->taskActivityProvider->find($timelineParams['TASK_ID']);
		if (is_null($activity))
		{
			return new Bindings();
		}
		$query = ActivityBindingTable::query();
		$query
			->setSelect(['ID', 'OWNER_ID', 'OWNER_TYPE_ID'])
			->where('ACTIVITY_ID', $activity->getId())
		;

		$currentBindings = $query->exec()->fetchCollection();
		$result = new Bindings();
		foreach ($currentBindings as $activityIdentifier)
		{
			$result->add(new ItemIdentifier($activityIdentifier->getOwnerTypeId(), $activityIdentifier->getOwnerId()));
		}

		return $result;
	}

	/**
	 * A task state the activity mirrors as not completed. States without a projection (deferred, declined)
	 * leave the activity state untouched, so there is nothing to return to work for them either.
	 */
	private function isActiveTaskState(int $taskStatus): bool
	{
		$projection = $this->taskActivityStatus->onStatusChange($taskStatus);

		return $projection !== '' && !$this->taskActivityStatus->isCompletedTaskState($taskStatus);
	}

	/**
	 * A failed write still leaves a task and an activity for the rest of the handler to work on, so only a
	 * missing one of them cancels it: the timeline entry, the triggers and the related updates never depended
	 * on the status write, while a missing task or activity leaves nothing to build them from anyway.
	 */
	private function isTaskOrActivityMissing(Result $syncResult): bool
	{
		$errors = $syncResult->getErrorCollection();

		return $errors->getErrorByCode(Task::ERROR_TASK_NOT_FOUND) !== null
			|| $errors->getErrorByCode(Task::ERROR_ACTIVITY_NOT_FOUND) !== null;
	}

	private function isActivityStatusUpdateRequired(array $params, Bindings $bindings, string $desiredStatus): bool
	{
		$authorId = $params['AUTHOR_ID'] ?? 0;

		if ($bindings->isEmpty())
		{
			return false;
		}

		$updateByParams = (!isset($params['UPDATE_ACTIVITY_STATUS']) || $params['UPDATE_ACTIVITY_STATUS'] === true);

		if ($updateByParams === false)
		{
			return false;
		}

		// a deadline change keeps its historical right to be applied by the assignee as well
		if ($desiredStatus === TaskActivityStatus::STATUS_DEADLINE_CHANGED)
		{
			return true;
		}

		foreach ($bindings as $identifier)
		{
			$responsibleId = $this->getAssignedByEntity($identifier);

			if ($responsibleId === $authorId)
			{
				return false;
			}
		}

		return true;
	}

	private function getOldTaskActivity(int $taskId): ?EO_Activity
	{
		$query = ActivityTable::query();
		$query
			->setSelect(['ID'])
			->where('PROVIDER_ID', \Bitrix\Crm\Activity\Provider\Task::getId())
			->where('PROVIDER_TYPE_ID', \Bitrix\Crm\Activity\Provider\Task::getTypeId([]))
			->where('ASSOCIATED_ENTITY_ID', $taskId)
			->where('TYPE_ID', \CCrmActivityType::Task)
		;

		return $query->exec()->fetchObject();
	}
}
