<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Infrastructure\Controller\Task\Relation;

use Bitrix\Main\Engine\ActionFilter\Attribute\Rule\CloseSession;
use Bitrix\Main\Provider\Params\Pager;
use Bitrix\Main\Provider\Params\SelectInterface;
use Bitrix\Main\UI\PageNavigation;
use Bitrix\Main\Validation\Rule\ElementsType;
use Bitrix\Main\Validation\Rule\Enum\Type;
use Bitrix\Tasks\V2\Infrastructure\Controller\BaseController;
use Bitrix\Tasks\V2\Internal\Access\Service\TaskRightService;
use Bitrix\Tasks\V2\Internal\Access\Task\ActionDictionary;
use Bitrix\Tasks\V2\Internal\Access\Task\Permission;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Service\Task\ParentService;
use Bitrix\Tasks\V2\Public\Command\Task\Relation\DeleteParentRelationCommand;
use Bitrix\Tasks\V2\Public\Command\Task\Relation\SetParentRelationCommand;
use Bitrix\Tasks\V2\Public\Provider\Params\Relation\RelationTaskParams;
use Bitrix\Tasks\V2\Public\Provider\Relation\SubTaskProvider;
use Bitrix\Tasks\Validation\Rule\Count;

class Child extends BaseController
{
	/**
	 * @ajaxAction tasks.V2.Task.Relation.Child.list
	 */
	#[CloseSession]
	public function listAction(
		#[Permission\Read]
		Entity\Task $task,
		PageNavigation $pageNavigation,
		SubTaskProvider $subTaskProvider,
		?SelectInterface $relationTaskSelect = null,
		bool $withIds = true,
	): array
	{
		$params = new RelationTaskParams(
			userId: $this->userId,
			taskId: (int)$task->id,
			templateId: 0,
			pager: Pager::buildFromPageNavigation($pageNavigation),
			select: $relationTaskSelect,
		);

		$response = [
			'tasks' => $subTaskProvider->getTasks($params),
		];

		if ($withIds)
		{
			$response['ids'] = $subTaskProvider->getTaskIds($params);
		}

		return $response;
	}

	/**
	 * @ajaxAction tasks.V2.Task.Relation.Child.listByIds
	 */
	#[CloseSession]
	public function listByIdsAction(
		#[ElementsType(typeEnum: Type::Numeric)]
		array $taskIds,
		SubTaskProvider $subTaskProvider,
	): array
	{
		return [
			'tasks' => $subTaskProvider->getTasksByIds($taskIds, $this->userId),
		];
	}

	/**
	 * @ajaxAction tasks.V2.Task.Relation.Child.add
	 */
	public function addAction(
		#[Permission\Read]
		Entity\Task $task,
		#[Count(min: 1, max: 20)]
		Entity\TaskCollection $tasks,
		TaskRightService $taskRightService,
		ParentService $parentService,
		bool $noOverride = false,
	): ?array
	{
		$permissions = $taskRightService->getTaskRightsBatch(
			userId: $this->userId,
			taskIds: $tasks->getIdList(),
			rules: ['edit' => ActionDictionary::TASK_ACTIONS['edit']]
		);

		$parentMap = $parentService->getParentIds($tasks->getIdList());

		$response = [];
		foreach ($tasks as $subTask)
		{
			$subTaskId = (int)$subTask->id;
			if (!$permissions[$subTaskId]['edit'])
			{
				$response[$subTaskId] = false;
				$this->addError($this->buildForbiddenError());

				continue;
			}

			$canOverride = !$noOverride || (int)$parentMap[$subTaskId] === 0;
			if (!$canOverride)
			{
				$response[$subTaskId] = false;
				$this->addError($this->buildForbiddenError('No override parentId'));

				continue;
			}

			$result = (new SetParentRelationCommand(
				taskId: $subTaskId,
				userId: $this->userId,
				parentId: (int)$task->id,
				useConsistency: true,
			))->run();

			$response[$subTaskId] = $result->isSuccess();
			if ($response[$subTaskId] === false)
			{
				$this->addErrors($result->getErrors());
			}
		}

		return $response;
	}

	/**
	 * @ajaxAction tasks.V2.Task.Relation.Child.delete
	 */
	public function deleteAction(
		#[Permission\Update]
		#[Count(min: 1, max: 20)]
		Entity\TaskCollection $tasks,
	): ?array
	{
		$response = [];
		foreach ($tasks as $subTask)
		{
			$subTaskId = (int)$subTask->id;

			$result = (new DeleteParentRelationCommand(
				taskId: $subTaskId,
				userId: $this->userId,
				useConsistency: true,
			))->run();

			$response[$subTaskId] = $result->isSuccess();
			if ($response[$subTaskId] === false)
			{
				$this->addErrors($result->getErrors());
			}
		}

		return $response;
	}
}
