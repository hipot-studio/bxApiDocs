<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository;

use Bitrix\Main\Type\Collection;
use Bitrix\Tasks\Internals\Registry\TaskRegistry;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Repository\Mapper\TaskMapper;
use Bitrix\Tasks\V2\Internal\Repository\Task\Select;

class TaskReadRepository implements TaskReadRepositoryInterface
{
	public function __construct(
		private readonly GroupRepositoryInterface $groupRepository,
		private readonly FlowRepositoryInterface  $flowRepository,
		private readonly StageRepositoryInterface $stageRepository,
		private readonly UserRepositoryInterface  $userRepository,
		private readonly CheckListRepository      $checkListRepository,
		private readonly ChatRepositoryInterface  $chatRepository,
		private readonly TaskMapper               $taskMapper,
	)
	{
	}

	public function getById(int $id, ?Select $select = null): ?Entity\Task
	{
		$task = TaskRegistry::getInstance()->getObject($id, true);

		if ($task === null || $task->getZombie())
		{
			return null;
		}

		$select ??= new Select();

		$group = null;
		if ($select->group && $task->getGroupId() > 0)
		{
			$group = $this->groupRepository->getById($task->getGroupId());
		}

		$flow = null;
		if ($select->flow && $task->customData['FLOW_ID'] > 0)
		{
			$flow = $this->flowRepository->getById($task->customData['FLOW_ID']);
		}

		$stage = null;
		if ($select->stage && $task->getStageId() > 0)
		{
			$stage = $this->stageRepository->getById($task->getStageId());
		}

		$memberIds = array_merge($task->getMemberList()->getUserIdList(), [$task->getCreatedBy(), $task->getResponsibleId()]);

		Collection::normalizeArrayValuesByInt($memberIds, false);

		$members = $this->userRepository->getByIds($memberIds);

		$checkListIds = null;
		if ($select->checkLists)
		{
			$checkListIds = $this->checkListRepository->getIdsByEntity($id, Entity\CheckList\Type::Task);
		}

		$chatId = null;
		if ($select->chat)
		{
			$chatId = $this->chatRepository->getChatIdByTaskId($id);
		}

		$aggregates['containsCheckList'] = !empty($checkListIds);

		return $this->taskMapper->mapToEntity($task, $group, $flow, $stage, $members, $aggregates, $chatId, $checkListIds);
	}
}