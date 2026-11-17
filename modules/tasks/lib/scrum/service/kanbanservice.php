<?php
namespace Bitrix\Tasks\Scrum\Service;

use Bitrix\Main\Error;
use Bitrix\Main\Errorable;
use Bitrix\Main\ErrorCollection;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Type\Collection;
use Bitrix\Tasks\Access\ActionDictionary;
use Bitrix\Tasks\Access\TaskAccessController;
use Bitrix\Tasks\Control\Exception\TaskStageValidationException;
use Bitrix\Tasks\Integration\Recyclebin;
use Bitrix\Tasks\Internals\Task\UpdateEventSuppression;
use Bitrix\Tasks\Internals\TaskTable;
use Bitrix\Tasks\Kanban\StagesTable;
use Bitrix\Tasks\Kanban\TaskStageTable;
use Bitrix\Tasks\Kanban\ProjectsTable;
use Bitrix\Tasks\Scrum\Form\EntityForm;
use Bitrix\Tasks\Scrum\Internal\EntityTable;
use Bitrix\Tasks\Util\User;
use Bitrix\Tasks\V2\Internal\DI\Container;

class KanbanService implements Errorable
{
	const ERROR_COULD_NOT_ADD_TASK = 'TASKS_KS_01';
	const ERROR_COULD_NOT_REMOVE_TASK = 'TASKS_KS_02';
	const ERROR_COULD_NOT_GET_LAST_STAGES = 'TASKS_KS_03';
	const ERROR_COULD_NOT_ADD_ONE_TASK = 'TASKS_KS_05';
	const ERROR_COULD_NOT_ROLLBACK_TASK = 'TASKS_KS_06';

	private const TASK_STAGE_BATCH_SIZE = 300;

	private $errorCollection;

	private $unFinishedTaskIdsCache = [];
	private $finishedTaskIdsCache = [];

	private static $sprintStageIds = [];
	private bool $skipTaskUpdateEvent;
	private bool $deferTaskStageUpdate;
	/** @var array<int, array{stageId: int, userId: int}> */
	private array $deferredTaskStageUpdates = [];

	public function __construct(bool $skipTaskUpdateEvent = false, bool $deferTaskStageUpdate = false)
	{
		$this->errorCollection = new ErrorCollection;
		$this->skipTaskUpdateEvent = $skipTaskUpdateEvent;
		$this->deferTaskStageUpdate = $deferTaskStageUpdate;
	}

	/**
	 * Applies task stage updates deferred during placement changes.
	 *
	 * @return bool
	 */
	public function flushTaskStageUpdates(): bool
	{
		$updates = $this->deferredTaskStageUpdates;
		$this->deferredTaskStageUpdates = [];

		$success = true;
		foreach ($updates as $taskId => $update)
		{
			if (!$this->updateTaskStageId($taskId, $update['stageId'], $update['userId']))
			{
				$success = false;
			}
		}

		return $success;
	}

	/**
	 * The method returns the stages for the task, depending on whether it is in the active sprint,
	 * completed sprint, or in the backlog.
	 *
	 * @param int $taskId Task id.
	 * @return array
	 */
	public function getStagesToTask(int $taskId): array
	{
		$itemService = new ItemService();
		$entityService = new EntityService();

		$scrumItem = $itemService->getItemBySourceId($taskId);
		if ($itemService->getErrors() || $scrumItem->isEmpty())
		{
			return [];
		}

		$entity = $entityService->getEntityById($scrumItem->getEntityId());
		if ($entityService->getErrors() || $entity->isEmpty())
		{
			return [];
		}

		if ($entity->getEntityType() === EntityForm::BACKLOG_TYPE)
		{
			return [];
		}

		return $this->getStages($entity->getId());
	}

	public function getStages(int $sprintId): array
	{
		$stages = [];

		$queryObject = StagesTable::getList([
			'filter' => [
				'ENTITY_ID' => $sprintId,
				'=ENTITY_TYPE' => StagesTable::WORK_MODE_ACTIVE_SPRINT,
			],
			'order' => [
				'SORT' => 'ASC',
			],
		]);
		while ($stage = $queryObject->fetch())
		{
			if ($stage['COLOR'] == '')
			{
				$stage['COLOR'] = StagesTable::DEF_COLOR_STAGE;
			}

			if ($stage['TITLE'] == '')
			{
				if ($stage['SYSTEM_TYPE'] != '')
				{
					$stage['TITLE'] = $this->getDefaultStageTitle($stage['SYSTEM_TYPE']);
				}
				else
				{
					$stage['TITLE'] = $this->getDefaultStageTitle(StagesTable::SYS_TYPE_DEFAULT);
				}
			}

			$stages[$stage['ID']] = $stage;
		}

		return $stages;
	}

	/**
	 * The method returns the stage id for the task.
	 *
	 * @param int $taskId Task id.
	 * @return int
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\ObjectPropertyException
	 * @throws \Bitrix\Main\SystemException
	 */
	public function getTaskStageId(int $taskId): int
	{
		$itemService = new ItemService();
		$entityService = new EntityService();

		$scrumItem = $itemService->getItemBySourceId($taskId);
		if ($itemService->getErrors() || $scrumItem->isEmpty())
		{
			return 0;
		}

		$entity = $entityService->getEntityById($scrumItem->getEntityId());
		if ($entityService->getErrors() || $entity->isEmpty())
		{
			return 0;
		}

		if ($entity->getEntityType() === EntityForm::BACKLOG_TYPE)
		{
			return 0;
		}

		$queryObject = TaskStageTable::getList([
			'filter' => [
				'TASK_ID' => $taskId,
				'=STAGE.ENTITY_TYPE' => StagesTable::WORK_MODE_ACTIVE_SPRINT,
				'STAGE.ENTITY_ID' => $entity->getId()
			]
		]);
		if ($taskStage = $queryObject->fetch())
		{
			return $taskStage['STAGE_ID'];
		}

		return 0;
	}

	/**
	 * The method returns the entity id for the task.
	 *
	 * @param int $taskId Task id.
	 * @return int
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\ObjectPropertyException
	 * @throws \Bitrix\Main\SystemException
	 */
	public function getTaskEntityId(int $taskId): int
	{
		$itemService = new ItemService();
		$entityService = new EntityService();

		$scrumItem = $itemService->getItemBySourceId($taskId);
		if ($itemService->getErrors() || $scrumItem->isEmpty())
		{
			return 0;
		}

		$entity = $entityService->getEntityById($scrumItem->getEntityId());
		if ($entityService->getErrors() || $entity->isEmpty())
		{
			return 0;
		}

		return $entity->getId();
	}

	/**
	 * Add the tasks to default stage of the sprint.
	 *
	 * @param int $sprintId Sprint id.
	 * @param array $taskIds List task id.
	 * @param int $lastSprintId Last sprint id only for start new sprint.
	 * @return bool
	 */
	public function addTasksToKanban(int $sprintId, array $taskIds, int $lastSprintId = 0): bool
	{
		$userId = $this->getTaskUpdateUserId();

		Collection::normalizeArrayValuesByInt($taskIds, false);
		if (empty($taskIds))
		{
			return true;
		}

		try
		{
			return Container::getInstance()
				->getStageCompatibilityService()
				->runWithValidationCache(function () use ($sprintId, $taskIds, $lastSprintId, $userId): bool {
					$taskStageIds = $this->getTaskStageIdsForKanban($sprintId, $taskIds, $lastSprintId);
					if ($taskStageIds === [])
					{
						return false;
					}

					$sprintStageIds = $this->getSprintStageIds($sprintId);
					if ($lastSprintId > 0)
					{
						$sprintStageIds = array_values(array_unique(array_merge(
							$sprintStageIds,
							$this->getSprintStageIds($lastSprintId),
						)));
					}

					return $this->updateTaskStagesWithRelations(
						$taskStageIds,
						$sprintStageIds,
						$userId,
					);
				});
		}
		catch (\Throwable $exception)
		{
			$this->errorCollection->setError(
				new Error($exception->getMessage(), self::ERROR_COULD_NOT_ADD_TASK)
			);

			return false;
		}
	}

	public function getNewStatus(): string
	{
		return StagesTable::SYS_TYPE_NEW;
	}

	public function getFinishStatus(): string
	{
		return StagesTable::SYS_TYPE_FINISH;
	}

	public function addTaskToFinishStatus(int $sprintId, int $taskId): void
	{
		try
		{
			$finishStageId = $this->getFinishStageId($sprintId);

			if ($finishStageId)
			{
				$this->assignKanbanStage(
					$taskId,
					$finishStageId,
					$this->getSprintStageIds($sprintId),
				);
			}
			else
			{
				$this->errorCollection->setError(
					new Error('Failed to get the finish stage', self::ERROR_COULD_NOT_ADD_ONE_TASK)
				);
			}
		}
		catch (\Throwable $exception)
		{
			$this->errorCollection->setError(
				new Error($exception->getMessage(), self::ERROR_COULD_NOT_ADD_ONE_TASK)
			);
		}
	}

	//todo static cache
	public function isTaskInFinishStatus(int $sprintId, int $taskId): bool
	{
		try
		{
			$finishStageId = $this->getFinishStageId($sprintId);

			if ($finishStageId)
			{
				$queryObject = TaskStageTable::getList([
					'filter' => [
						'TASK_ID' => $taskId,
						'STAGE_ID' => $finishStageId
					]
				]);
				return ($queryObject->fetch() ? true : false);
			}
		}
		catch (\Exception $exception)
		{
			$this->errorCollection->setError(
				new Error($exception->getMessage(), self::ERROR_COULD_NOT_ADD_ONE_TASK)
			);
		}

		return false;
	}

	public function isTaskInKanban(int $sprintId, int $taskId): bool
	{
		try
		{
			$stageIds = $this->getSprintStageIds($sprintId);

			$queryObject = TaskStageTable::getList([
				'filter' => [
					'TASK_ID' => $taskId,
					'STAGE_ID' => $stageIds
				]
			]);

			return ($queryObject->fetch() ? true : false);
		}
		catch (\Exception $exception)
		{
			$this->errorCollection->setError(
				new Error($exception->getMessage(), self::ERROR_COULD_NOT_ADD_ONE_TASK)
			);
		}

		return false;
	}

	public function addTaskToNewStatus(int $sprintId, int $taskId): void
	{
		try
		{
			$newStageId = $this->getNewStageId($sprintId);

			if ($newStageId)
			{
				$this->assignKanbanStage(
					$taskId,
					$newStageId,
					$this->getSprintStageIds($sprintId),
				);
			}
			else
			{
				$this->errorCollection->setError(
					new Error('Failed to get the new stage', self::ERROR_COULD_NOT_ADD_ONE_TASK)
				);
			}
		}
		catch (\Throwable $exception)
		{
			$this->errorCollection->setError(
				new Error($exception->getMessage(), self::ERROR_COULD_NOT_ADD_ONE_TASK)
			);
		}
	}

	/**
	 * Removes the tasks of the sprint.
	 *
	 * @param int $sprintId Sprint id.
	 * @param array $taskIds List task id.
	 * @param bool $updateTaskStage Write the cleared stage into the task itself.
	 * @return bool
	 */
	public function removeTasksFromKanban(int $sprintId, array $taskIds, bool $updateTaskStage = true): bool
	{
		Collection::normalizeArrayValuesByInt($taskIds, false);
		if (empty($taskIds))
		{
			return true;
		}

		$userId = $this->getTaskUpdateUserId();

		try
		{
			return Container::getInstance()
				->getStageCompatibilityService()
				->runWithValidationCache(function () use ($sprintId, $taskIds, $userId, $updateTaskStage): bool {
					$stageIds = $this->getSprintStageIds($sprintId);
					$relationsByTaskId = $this->getTaskStageRelationsByTaskIds($taskIds, $stageIds);
					$taskStageIds = [];
					foreach ($relationsByTaskId as $taskId => $relations)
					{
						if ($relations !== [])
						{
							$taskStageIds[$taskId] = 0;
						}
					}

					if ($taskStageIds === [])
					{
						return true;
					}

					return $this->updateTaskStagesWithRelations(
						$taskStageIds,
						$stageIds,
						$userId,
						$relationsByTaskId,
						$updateTaskStage,
					);
				})
			;
		}
		catch (\Throwable $exception)
		{
			$this->errorCollection->setError(
				new Error($exception->getMessage(), self::ERROR_COULD_NOT_REMOVE_TASK)
			);

			return false;
		}
	}

	public function removeStages(int $sprintId): void
	{
		StagesTable::setWorkMode(StagesTable::WORK_MODE_ACTIVE_SPRINT);

		$queryObject = StagesTable::getList([
			'select' => ['ID'],
			'filter' => [
				'=ENTITY_TYPE' => StagesTable::WORK_MODE_ACTIVE_SPRINT,
				'ENTITY_ID' => $sprintId,
			],
			'order' => ['ID' => 'ASC']
		]);
		while ($stage = $queryObject->fetch())
		{
			StagesTable::delete($stage['ID'], $sprintId);
		}
	}

	/**
	 * Gets default stages or stages of last sprint for active sprint.
	 *
	 * @param int $lastSprintId Last sprint id for copy last stages.
	 * @return array
	 */
	public function generateKanbanStages(int $lastSprintId = 0): array
	{
		if ($lastSprintId)
		{
			$stages = $this->getStagesCompletedSprint($lastSprintId);

			if ($stages)
			{
				return $stages;
			}
			else
			{
				$this->errorCollection->setError(
					new Error(
						'Failed to get last completed sprint',
						self::ERROR_COULD_NOT_GET_LAST_STAGES
					)
				);

				return [];
			}
		}

		return [
			'NEW' => [
				'TITLE' => $this->getDefaultStageTitle(StagesTable::SYS_TYPE_DEFAULT),
				'COLOR' => '00C4FB',
				'SYSTEM_TYPE' => StagesTable::SYS_TYPE_DEFAULT
			],
			'WORK' => [
				'TITLE' => $this->getDefaultStageTitle(StagesTable::SYS_TYPE_PROGRESS),
				'COLOR' => '47D1E2',
				'SYSTEM_TYPE' => StagesTable::SYS_TYPE_PROGRESS
			],
			'FINISH' => [
				'TITLE' => $this->getDefaultStageTitle(StagesTable::SYS_TYPE_FINISH),
				'COLOR' => '75D900',
				'SYSTEM_TYPE' => StagesTable::SYS_TYPE_FINISH
			]
		];
	}

	public function getFinishedTaskIdsInSprint(int $sprintId): array
	{
		if (!isset($this->finishedTaskIdsCache[$sprintId]))
		{
			$this->finishedTaskIdsCache[$sprintId] = $this->getTaskIds([
				'=STAGE.SYSTEM_TYPE' => StagesTable::SYS_TYPE_FINISH,
				'=STAGE.ENTITY_ID' => $sprintId,
				'=STAGE.ENTITY_TYPE' => StagesTable::WORK_MODE_ACTIVE_SPRINT,
			]);
		}

		return $this->finishedTaskIdsCache[$sprintId];
	}

	public function getUnfinishedTaskIdsInSprint(int $sprintId): array
	{
		if (!isset($this->unFinishedTaskIdsCache[$sprintId]))
		{
			$this->unFinishedTaskIdsCache[$sprintId] = $this->getTaskIds([
				'!=STAGE.SYSTEM_TYPE' => StagesTable::SYS_TYPE_FINISH,
				'=STAGE.ENTITY_ID' => $sprintId,
				'=STAGE.ENTITY_TYPE' => StagesTable::WORK_MODE_ACTIVE_SPRINT,
			]);
		}

		return $this->unFinishedTaskIdsCache[$sprintId];
	}

	public function extractFinishedTaskIds(array $taskIds): array
	{
		return $this->getTaskIds([
			'=STAGE.SYSTEM_TYPE' => StagesTable::SYS_TYPE_FINISH,
			'=TASK_ID' => $taskIds,
			'=STAGE.ENTITY_TYPE' => StagesTable::WORK_MODE_ACTIVE_SPRINT,
		]);
	}

	public function getKanbanSortValue(int $groupId): string
	{
		if (($row = ProjectsTable::getById($groupId)->fetch()))
		{
			return $row['ORDER_NEW_TASK'] ? $row['ORDER_NEW_TASK'] : 'actual';
		}
		else
		{
			return 'actual';
		}
	}

	public function getErrors()
	{
		return $this->errorCollection->toArray();
	}

	public function getErrorByCode($code)
	{
		return $this->errorCollection->getErrorByCode($code);
	}

	public function getStageTitles(array $stageIds): array
	{
		$stageTitles = [];
		foreach ($stageIds as $stageId)
		{
			$stageTitles[$stageId] = '';
		}

		$res = StagesTable::getList([
			'select' => ['ID', 'TITLE', 'SYSTEM_TYPE'],
			'filter' => ['@ID' => $stageIds],
		]);
		while ($stage = $res->fetch())
		{
			$stageId = (int) $stage['ID'];
			$stageKey = array_search($stageId, $stageIds);
			if ($stageKey !== false && $stageId === $stageIds[$stageKey])
			{
				if ($stage['TITLE'] === '')
				{
					if ($stage['SYSTEM_TYPE'] !== '')
					{
						$title = $this->getDefaultStageTitle($stage['SYSTEM_TYPE']);
					}
					else
					{
						$title = $this->getDefaultStageTitle(StagesTable::SYS_TYPE_DEFAULT);
					}
				}
				else
				{
					$title = $stage['TITLE'];
				}

				$stageTitles[$stageId] = $title;
			}
		}

		return $stageTitles;
	}

	public function moveTask(int $taskId, int $stageId): bool
	{
		$itemService = new ItemService();
		$entityService = new EntityService();

		$scrumItem = $itemService->getItemBySourceId($taskId);
		if ($itemService->getErrors() || $scrumItem->isEmpty())
		{
			return false;
		}

		$entity = $entityService->getEntityById($scrumItem->getEntityId());
		if ($entityService->getErrors() || $entity->isEmpty())
		{
			return false;
		}

		if ($entity->getEntityType() === EntityForm::BACKLOG_TYPE)
		{
			return false;
		}

		$queryObject = TaskStageTable::getList([
			'filter' => [
				'TASK_ID' => $taskId,
				'=STAGE.ENTITY_TYPE' => StagesTable::WORK_MODE_ACTIVE_SPRINT,
				'STAGE.ENTITY_ID' => $entity->getId()
			]
		]);

		$taskStage = $queryObject->fetch();
		if (!$taskStage)
		{
			return false;
		}

		return $this->updateTaskStageWithRelations($taskId, $stageId, [(int)$taskStage['ID']]);
	}

	public function getLastCompletedSprintIdSameGroup(int $sprintId): int
	{
		$queryObject = EntityTable::getList([
			'select' => ['ID', 'GROUP_ID'],
			'filter' => [
				'ID'=> (int) $sprintId
			]
		]);

		if ($sprintData = $queryObject->fetch())
		{
			$queryObjectLastSprint = EntityTable::getList([
				'select' => ['ID', 'DATE_END'],
				'filter' => [
					'GROUP_ID'=> (int) $sprintData['GROUP_ID'],
					'=ENTITY_TYPE' => EntityForm::SPRINT_TYPE,
					'=STATUS' => EntityForm::SPRINT_COMPLETED
				],
				'order' => ['DATE_END' => 'DESC'],
				'limit' => 1
			]);

			return (($fields = $queryObjectLastSprint->fetch()) ? $fields['ID'] : 0);
		}

		return 0;
	}

	public function getStageIdsMapBetweenTwoSprints(int $firstSprintId, int $secondSprintId): array
	{
		$firstStages = [];
		$secondStages = [];

		$firstSprintId = (int) $firstSprintId;
		$secondSprintId = (int) $secondSprintId;

		$queryObject = StagesTable::getList([
			'select' => ['*'],
			'filter' => [
				'=ENTITY_TYPE' => StagesTable::WORK_MODE_ACTIVE_SPRINT,
				'ENTITY_ID' => [$firstSprintId, $secondSprintId],
			],
			'order' => ['SORT' => 'ASC']
		]);
		while ($stage = $queryObject->fetch())
		{
			$entityId = (int) $stage['ENTITY_ID'];

			if ($entityId === $firstSprintId)
			{
				$firstStages[] = $stage;
			}
			else if ($entityId === $secondSprintId)
			{
				$secondStages[] = $stage;
			}
		}

		$stageIdsMap = [];

		if (count($firstStages) !== count($secondStages))
		{
			return $stageIdsMap;
		}

		foreach ($firstStages as $firstStage)
		{
			foreach ($secondStages as $secondStage)
			{
				if (
					$firstStage['TITLE'] === $secondStage['TITLE']
					&& $firstStage['SYSTEM_TYPE'] === $secondStage['SYSTEM_TYPE']
				)
				{
					$stageIdsMap[$secondStage['ID']] = $firstStage['ID'];
				}
			}
		}

		return $stageIdsMap;
	}

	public function createSprintStages(int $sprintId, int $lastSprintId = 0)
	{
		$stages = $this->generateKanbanStages($lastSprintId);

		$sort = 0;
		foreach ($stages as $stageCode => $stageItem)
		{
			StagesTable::add(
				[
					'SYSTEM_TYPE' => array_key_exists('SYSTEM_TYPE', $stageItem)
						? $stageItem['SYSTEM_TYPE']
						: $stageCode
					,
					'TITLE' => array_key_exists('TITLE', $stageItem) ? $stageItem['TITLE'] : '',
					'SORT' => ++$sort * 100,
					'ENTITY_ID' => $sprintId,
					'ENTITY_TYPE' => StagesTable::WORK_MODE_ACTIVE_SPRINT,
					'COLOR' => $stageItem['COLOR']
				]
			);
		}
	}

	public function hasSprintStages(int $sprintId): bool
	{
		$queryObject = StagesTable::getList([
			'select' => ['*'],
			'filter' => [
				'=ENTITY_TYPE' => StagesTable::WORK_MODE_ACTIVE_SPRINT,
				'ENTITY_ID' => $sprintId
			],
			'order' => [
				'SORT' => 'ASC'
			]
		]);

		return (bool) $queryObject->fetch();
	}

	/**
	 * Checks if the number of columns differs between sprints.
	 *
	 * @param int $firstSprintId First sprint id.
	 * @param int $secondSprintId Second sprint id.
	 * @return bool
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\ObjectPropertyException
	 * @throws \Bitrix\Main\SystemException
	 */
	public function hasDifferencesBetweenTwoSprints(int $firstSprintId, int $secondSprintId): bool
	{
		$firstStages = [];
		$secondStages = [];

		$firstSprintId = (int) $firstSprintId;
		$secondSprintId = (int) $secondSprintId;

		$queryObject = StagesTable::getList([
			'select' => ['ENTITY_ID'],
			'filter' => [
				'=ENTITY_TYPE' => StagesTable::WORK_MODE_ACTIVE_SPRINT,
				'ENTITY_ID' => [$firstSprintId, $secondSprintId],
			],
			'order' => ['SORT' => 'ASC']
		]);
		while ($stage = $queryObject->fetch())
		{
			$entityId = (int) $stage['ENTITY_ID'];

			if ($entityId === $firstSprintId)
			{
				$firstStages[] = $stage;
			}
			else if ($entityId === $secondSprintId)
			{
				$secondStages[] = $stage;
			}
		}

		return count($firstStages) !== count($secondStages);
	}

	public function getDefaultStageId(int $sprintId): int
	{
		foreach ($this->getStages($sprintId) as $stage)
		{
			if ($stage['SYSTEM_TYPE'] == $this->getNewStatus())
			{
				return $stage['ID'];
			}
		}

		return 0;
	}

	private function getDefaultStageTitle(string $systemType): string
	{
		return (string)Loc::getMessage('TASKS_SCRUM_STAGE_' . $systemType);
	}

	private function getTaskIds(array $filter): array
	{
		$taskIds = [];

		$queryObject = TaskStageTable::getList([
			'select' => ['TASK_ID'],
			'filter' => $filter
		]);
		while ($taskStage = $queryObject->fetch())
		{
			$taskIds[$taskStage['TASK_ID']] = $taskStage['TASK_ID'];
		}

		if (empty($taskIds))
		{
			return [];
		}

		foreach ($this->isTasksInBasket($taskIds) as $taskId => $result)
		{
			if ($result === true)
			{
				unset($taskIds[$taskId]);
			}
		}

		return array_values($taskIds);
	}

	private function getStagesCompletedSprint(int $sprintId): array
	{
		$stages = [];

		$queryObject = StagesTable::getList([
			'select' => ['*'],
			'filter' => [
				'=ENTITY_TYPE' => StagesTable::WORK_MODE_ACTIVE_SPRINT,
				'ENTITY_ID' => $sprintId
			],
			'order' => [
				'SORT' => 'ASC'
			]
		]);
		while ($stage = $queryObject->fetch())
		{
			$stages[] = [
				'ID' => $stage['ID'],
				'TITLE' => $stage['TITLE'],
				'COLOR' => $stage['COLOR'],
				'SYSTEM_TYPE' => $stage['SYSTEM_TYPE']
			];
		}

		return $stages;
	}

	public function getSprintStageIds(int $sprintId): array
	{
		if (isset(self::$sprintStageIds[$sprintId]))
		{
			return self::$sprintStageIds[$sprintId];
		}

		self::$sprintStageIds[$sprintId] = [];

		$queryObject = StagesTable::getList([
			'select' => ['ID'],
			'filter' => [
				'=ENTITY_TYPE' => StagesTable::WORK_MODE_ACTIVE_SPRINT,
				'ENTITY_ID' => $sprintId,
			],
			'order' => ['SORT' => 'ASC']
		]);
		while ($stage = $queryObject->fetch())
		{
			self::$sprintStageIds[$sprintId][] = $stage['ID'];
		}

		return self::$sprintStageIds[$sprintId];
	}

	private function getFinishStageId(int $sprintId): int
	{
		$stageId = 0;

		$stages = $this->getStages($sprintId);
		foreach ($stages as $stage)
		{
			if ($stage['SYSTEM_TYPE'] == $this->getFinishStatus())
			{
				$stageId = (int) $stage['ID'];
			}
		}

		return $stageId;
	}

	private function getNewStageId(int $sprintId): int
	{
		$stageId = 0;

		$stages = $this->getStages($sprintId);
		foreach ($stages as $stage)
		{
			if ($stage['SYSTEM_TYPE'] == $this->getNewStatus())
			{
				$stageId = (int)$stage['ID'];
			}
		}

		return $stageId;
	}

	private function assignKanbanStage(int $taskId, int $stageId, array $sprintStageIds): bool
	{
		$relationIds = $this->getTaskStageRelationIds($taskId, $sprintStageIds);

		return $this->updateTaskStageWithRelations($taskId, $stageId, $relationIds);
	}

	public function canAddTasksToKanban(int $sprintId, array $taskIds, int $lastSprintId = 0): bool
	{
		$taskStageIds = $this->getTaskStageIdsForKanban($sprintId, $taskIds, $lastSprintId);

		return $taskStageIds !== []
			&& $this->validateTaskStageUpdates($taskStageIds, $this->getTaskUpdateUserId())
		;
	}

	public function updateTaskStageWithRelations(int $taskId, int $stageId, array $relationIds): bool
	{
		$userId = $this->getTaskUpdateUserId();

		return Container::getInstance()
			->getStageCompatibilityService()
			->runWithValidationCache(function () use ($taskId, $stageId, $relationIds, $userId): bool {
				if (!$this->validateTaskStageUpdates([$taskId => $stageId], $userId))
				{
					return false;
				}

				return $this->applyTaskStageWithRelations($taskId, $stageId, $relationIds, $userId);
			})
		;
	}

	private function applyTaskStageWithRelations(
		int $taskId,
		int $stageId,
		array $relationIds,
		int $userId,
	): bool
	{
		$relations = $this->getTaskStageRelations($relationIds);
		$targetRelationExists = $stageId > 0
			&& $this->getTaskStageRelationIds($taskId, [$stageId]) !== []
		;

		return $this->applyPreparedTaskStageWithRelations(
			$taskId,
			$stageId,
			$relations,
			$targetRelationExists,
			$userId,
		);
	}

	private function applyPreparedTaskStageWithRelations(
		int $taskId,
		int $stageId,
		array $relations,
		bool $targetRelationExists,
		int $userId,
	): bool
	{
		$stageService = Container::getInstance()->getTaskStageService();
		$stageService->clearRelations(array_keys($relations));
		$createdRelationId = null;
		if ($stageId > 0)
		{
			$relationId = $stageService->upsert($taskId, $stageId);
			if (!$targetRelationExists)
			{
				$createdRelationId = $relationId;
			}
		}

		if ($this->deferTaskStageUpdate)
		{
			$this->deferredTaskStageUpdates[$taskId] = [
				'stageId' => $stageId,
				'userId' => $userId,
			];

			return true;
		}

		try
		{
			if ($this->updateTaskStageId($taskId, $stageId, $userId))
			{
				return true;
			}
		}
		catch (\Throwable $exception)
		{
			$this->errorCollection->setError(
				new Error($exception->getMessage(), self::ERROR_COULD_NOT_ADD_ONE_TASK)
			);
		}

		$this->restoreTaskStageRelations($taskId, $relations, $createdRelationId);

		return false;
	}

	private function updateTaskStagesWithRelations(
		array $taskStageIds,
		array $relationStageIds,
		int $userId,
		?array $relationsByTaskId = null,
		bool $updateTaskStage = true,
	): bool
	{
		// Detaching the relations alone writes no stage into the task, so it needs neither the
		// compatibility check of a stage nor a rollback, and the relations go out in batches.
		if (!$updateTaskStage)
		{
			return $this->applyTaskStageRelationsOnly(
				$taskStageIds,
				$relationsByTaskId ?? $this->getTaskStageRelationsByTaskIds(
					array_keys($taskStageIds),
					$relationStageIds,
				),
			);
		}

		$taskRows = $this->getTaskStageUpdateRows(array_keys($taskStageIds));
		if (!$this->validateTaskStageUpdates($taskStageIds, $userId, $taskRows))
		{
			return false;
		}

		$relationsByTaskId ??= $this->getTaskStageRelationsByTaskIds(
			array_keys($taskStageIds),
			$relationStageIds,
		);
		$previousTaskStageIds = [];
		foreach ($taskStageIds as $taskId => $stageId)
		{
			$previousTaskStageIds[$taskId] = $taskRows[$taskId]['STAGE_ID'];
		}

		$appliedTaskIds = [];
		foreach ($taskStageIds as $taskId => $stageId)
		{
			$relations = $relationsByTaskId[$taskId] ?? [];
			if (!$this->applyPreparedTaskStageWithRelations(
				$taskId,
				$stageId,
				$relations,
				in_array($stageId, $relations, true),
				$userId,
			))
			{
				$this->rollbackTaskStageUpdates(
					$appliedTaskIds,
					$previousTaskStageIds,
					$relationsByTaskId,
					$relationStageIds,
				);

				return false;
			}

			$appliedTaskIds[] = $taskId;
		}

		return true;
	}

	private function applyTaskStageRelationsOnly(array $taskStageIds, array $relationsByTaskId): bool
	{
		$stageService = Container::getInstance()->getTaskStageService();

		$relationIds = [];
		foreach (array_keys($taskStageIds) as $taskId)
		{
			foreach (array_keys($relationsByTaskId[$taskId] ?? []) as $relationId)
			{
				$relationIds[] = $relationId;
			}
		}

		foreach (array_chunk($relationIds, self::TASK_STAGE_BATCH_SIZE) as $relationIdsChunk)
		{
			$stageService->clearRelations($relationIdsChunk);
		}

		foreach ($taskStageIds as $taskId => $stageId)
		{
			if ($stageId > 0)
			{
				$stageService->upsert($taskId, $stageId);
			}
		}

		return true;
	}

	private function rollbackTaskStageUpdates(
		array $taskIds,
		array $previousTaskStageIds,
		array $relationsByTaskId,
		array $relationStageIds,
	): void
	{
		$stageService = Container::getInstance()->getTaskStageService();
		foreach (array_reverse($taskIds) as $taskId)
		{
			unset($this->deferredTaskStageUpdates[$taskId]);

			try
			{
				$currentRelationIds = $this->getTaskStageRelationIds($taskId, $relationStageIds);
				$stageService->clearRelations($currentRelationIds);
				foreach ($relationsByTaskId[$taskId] ?? [] as $previousStageId)
				{
					$stageService->upsert($taskId, $previousStageId);
				}
			}
			catch (\Throwable $exception)
			{
				$this->errorCollection->setError(new Error(
					$exception->getMessage(),
					self::ERROR_COULD_NOT_ROLLBACK_TASK,
				));
			}

			if ($this->deferTaskStageUpdate)
			{
				continue;
			}

			try
			{
				$result = TaskTable::update($taskId, [
					'STAGE_ID' => $previousTaskStageIds[$taskId],
				]);
				if (!$result->isSuccess())
				{
					$this->errorCollection->setError(new Error(
						implode('; ', $result->getErrorMessages()),
						self::ERROR_COULD_NOT_ROLLBACK_TASK,
					));
				}
			}
			catch (\Throwable $exception)
			{
				$this->errorCollection->setError(new Error(
					$exception->getMessage(),
					self::ERROR_COULD_NOT_ROLLBACK_TASK,
				));
			}
		}
	}

	private function getTaskStageIdsForKanban(int $sprintId, array $taskIds, int $lastSprintId): array
	{
		$defaultStageId = $this->getDefaultStageId($sprintId);
		if (!$defaultStageId)
		{
			$this->errorCollection->setError(
				new Error('Failed to get the default stage', self::ERROR_COULD_NOT_ADD_TASK)
			);

			return [];
		}

		$taskStageIdsMap = [];
		if ($lastSprintId)
		{
			$stageIdsMap = $this->getStageIdsMapBetweenTwoSprints($sprintId, $lastSprintId);
			if ($stageIdsMap)
			{
				$lastStages = $this->getStagesCompletedSprint($lastSprintId);
				foreach ($lastStages as $lastStage)
				{
					$taskIdsInLastSprint = $this->getTaskIds([
						'=STAGE.ENTITY_TYPE' => StagesTable::WORK_MODE_ACTIVE_SPRINT,
						'TASK_ID' => $taskIds,
						'STAGE_ID' => $lastStage['ID'],
					]);
					foreach ($taskIdsInLastSprint as $taskIdInLastSprint)
					{
						$taskStageIdsMap[(int)$taskIdInLastSprint] = (int)(
							$stageIdsMap[$lastStage['ID']] ?? $defaultStageId
						);
					}
				}
			}
		}

		$taskStageIds = [];
		foreach ($taskIds as $taskId)
		{
			$taskId = (int)$taskId;
			$taskStageIds[$taskId] = $taskStageIdsMap[$taskId] ?? (int)$defaultStageId;
		}

		return $taskStageIds;
	}

	private function validateTaskStageUpdates(array $taskStageIds, int $userId, ?array $taskRows = null): bool
	{
		$taskRows ??= $this->getTaskStageUpdateRows(array_keys($taskStageIds));
		$stageCompatibilityService = Container::getInstance()->getStageCompatibilityService();

		foreach ($taskStageIds as $taskId => $stageId)
		{
			if (!isset($taskRows[$taskId]))
			{
				$this->errorCollection->setError(
					new Error('Task was not found', self::ERROR_COULD_NOT_ADD_ONE_TASK)
				);

				return false;
			}

			if (!TaskAccessController::can($userId, ActionDictionary::ACTION_TASK_EDIT, (int)$taskId))
			{
				$this->errorCollection->setError(new Error(
					Loc::getMessage('TASKS_SCRUM_ACCESS_DENIED') ?? 'Access denied to update task',
					'TASKS_SCRUM_TASK_UPDATE_ACCESS_DENIED',
				));

				return false;
			}

			try
			{
				$stageCompatibilityService->validateStageId((int)$stageId, $taskRows[$taskId]['GROUP_ID']);
			}
			catch (TaskStageValidationException $exception)
			{
				$this->errorCollection->setError(new Error($exception->getMessage(), $exception->getErrorCode()));

				return false;
			}
		}

		return true;
	}

	private function getTaskStageUpdateRows(array $taskIds): array
	{
		$taskRows = [];
		foreach (array_chunk(array_values(array_unique($taskIds)), self::TASK_STAGE_BATCH_SIZE) as $taskIdChunk)
		{
			$rows = TaskTable::query()
				->setSelect(['ID', 'GROUP_ID', 'STAGE_ID'])
				->whereIn('ID', $taskIdChunk)
				->fetchAll();
			foreach ($rows as $row)
			{
				$taskRows[(int)$row['ID']] = [
					'GROUP_ID' => (int)$row['GROUP_ID'],
					'STAGE_ID' => (int)$row['STAGE_ID'],
				];
			}
		}

		return $taskRows;
	}

	private function getTaskStageRelationsByTaskIds(array $taskIds, array $stageIds): array
	{
		$taskIds = array_values(array_unique(array_map('intval', $taskIds)));
		$relationsByTaskId = array_fill_keys($taskIds, []);
		if ($taskIds === [] || $stageIds === [])
		{
			return $relationsByTaskId;
		}

		foreach (array_chunk($taskIds, self::TASK_STAGE_BATCH_SIZE) as $taskIdChunk)
		{
			$rows = TaskStageTable::query()
				->setSelect(['ID', 'TASK_ID', 'STAGE_ID'])
				->whereIn('TASK_ID', $taskIdChunk)
				->whereIn('STAGE_ID', $stageIds)
				->fetchAll();
			foreach ($rows as $row)
			{
				$relationsByTaskId[(int)$row['TASK_ID']][(int)$row['ID']] = (int)$row['STAGE_ID'];
			}
		}

		return $relationsByTaskId;
	}

	private function getTaskStageRelations(array $relationIds): array
	{
		if ($relationIds === [])
		{
			return [];
		}

		$rows = TaskStageTable::query()
			->setSelect(['ID', 'STAGE_ID'])
			->whereIn('ID', $relationIds)
			->fetchAll();

		return array_column($rows, 'STAGE_ID', 'ID');
	}

	private function restoreTaskStageRelations(int $taskId, array $relations, ?int $createdRelationId): void
	{
		if ($createdRelationId !== null)
		{
			Container::getInstance()->getTaskStageService()->clearRelations([$createdRelationId]);
		}

		foreach ($relations as $previousStageId)
		{
			Container::getInstance()->getTaskStageService()->upsert($taskId, (int)$previousStageId);
		}
	}

	private function getTaskStageRelationIds(int $taskId, array $stageIds): array
	{
		$rows = TaskStageTable::getList([
			'select' => ['ID'],
			'filter' => [
				'=TASK_ID' => $taskId,
				'@STAGE_ID' => $stageIds,
			],
		])->fetchAll();

		$relationIds = array_column($rows, 'ID');
		Collection::normalizeArrayValuesByInt($relationIds, false);

		return $relationIds;
	}

	private function getTaskUpdateUserId(): int
	{
		$userId = User::getId();

		return $userId > 0 ? $userId : 1;
	}

	protected function updateTaskStageId(int $taskId, int $stageId, int $userId): bool
	{
		$parameters = ['USER_ID' => $userId];
		if ($this->skipTaskUpdateEvent)
		{
			$parameters += UpdateEventSuppression::getParameters();
		}

		$taskObject = new \CTasks();
		if ($taskObject->update($taskId, ['STAGE_ID' => $stageId], $parameters) !== false)
		{
			return true;
		}

		$errors = $taskObject->GetErrors();
		if ($errors === [])
		{
			$this->errorCollection->setError(
				new Error('Failed to update task stage', self::ERROR_COULD_NOT_ADD_ONE_TASK)
			);

			return false;
		}

		foreach ($errors as $error)
		{
			$this->errorCollection->setError(
				new Error(
					(string)($error['text'] ?? ''),
					(string)($error['id'] ?? self::ERROR_COULD_NOT_ADD_ONE_TASK),
				)
			);
		}

		return false;
	}

	private function isTasksInBasket(array $taskIds): array
	{
		return Recyclebin\Task::isInTheRecycleBin($taskIds);
	}
}
