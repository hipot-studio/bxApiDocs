<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository;

use Bitrix\Main\Application;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\Type\Collection;
use Bitrix\Tasks\Control\Exception\TaskAddException;
use Bitrix\Tasks\Control\Exception\TaskNotExistsException;
use Bitrix\Tasks\Control\Exception\TaskNotFoundException;
use Bitrix\Tasks\Control\Exception\TaskStopDeleteException;
use Bitrix\Tasks\Control\Exception\TaskUpdateException;
use Bitrix\Tasks\Control\Exception\WrongTaskIdException;
use Bitrix\Tasks\Internals\Registry\TaskRegistry;
use Bitrix\Tasks\Internals\Task\CheckListTable;
use Bitrix\Tasks\Internals\TaskTable;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Repository\Trait\ApplicationErrorTrait;
use Bitrix\Tasks\V2\Internal\Repository\Mapper\OrmTaskMapper;
use Bitrix\Tasks\V2\Internal\Repository\Mapper\TaskMapper;

class TaskRepository implements TaskRepositoryInterface
{
	use ApplicationErrorTrait;

	public function __construct(
		private readonly GroupRepositoryInterface $groupRepository,
		private readonly FlowRepositoryInterface  $flowRepository,
		private readonly StageRepositoryInterface $stageRepository,
		private readonly UserRepositoryInterface  $userRepository,
		private readonly CheckListRepository $checkListRepository,
		private readonly ChatRepositoryInterface $chatRepository,
		private readonly TaskMapper               $taskMapper,
		private readonly OrmTaskMapper $ormTaskMapper,
	)
	{
	}

	public function getById(int $id): ?Entity\Task
	{
		// todo: replace with 1 query
		$task = TaskRegistry::getInstance()->getObject($id, true);

		if ($task === null || $task->getZombie())
		{
			return null;
		}

		$group = null;
		if ($task->getGroupId() > 0)
		{
			$group = $this->groupRepository->getById($task->getGroupId());
		}

		$flow = null;
		if ($task->customData['FLOW_ID'] > 0)
		{
			$flow = $this->flowRepository->getById($task->customData['FLOW_ID']);
		}

		$stage = null;
		if ($task->getStageId() > 0)
		{
			$stage = $this->stageRepository->getById($task->getStageId());
		}

		$memberIds = array_merge($task->getMemberList()->getUserIdList(), [$task->getCreatedBy(), $task->getResponsibleId()]);

		Collection::normalizeArrayValuesByInt($memberIds, false);

		$members = $this->userRepository->getByIds($memberIds);

		$checkListIds = $this->checkListRepository->getIdsByEntity($id, Entity\CheckList\Type::Task);
		if (empty($checkListIds))
		{
			$checkListIds = null;
		}

		$aggregates['containsCheckList'] = !empty($checkListIds);

		$chatId = $this->chatRepository->getChatIdByTaskId($id);

		return $this->taskMapper->mapToEntity($task, $group, $flow, $stage, $members, $aggregates, $chatId, $checkListIds);
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
			throw new TaskUpdateException('Not found');
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
}
