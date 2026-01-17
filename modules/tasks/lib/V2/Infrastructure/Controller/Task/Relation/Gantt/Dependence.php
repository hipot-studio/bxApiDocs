<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Infrastructure\Controller\Task\Relation\Gantt;

use Bitrix\Main\Engine\ActionFilter\Attribute\Rule\CloseSession;
use Bitrix\Main\Provider\Params\Pager;
use Bitrix\Main\Provider\Params\SelectInterface;
use Bitrix\Main\UI\PageNavigation;
use Bitrix\Main\Validation\Rule\ElementsType;
use Bitrix\Main\Validation\Rule\Enum\Type;
use Bitrix\Tasks\V2\Infrastructure\Controller\ActionFilter\Rule\GanttRestriction;
use Bitrix\Tasks\V2\Infrastructure\Controller\BaseController;
use Bitrix\Tasks\V2\Internal\Access\Task\Gantt\Permission\Update;
use Bitrix\Tasks\V2\Internal\Access\Task\Permission\Read;
use Bitrix\Tasks\V2\Internal\Entity\Task;
use Bitrix\Tasks\V2\Internal\Entity\Task\GanttLink;
use Bitrix\Tasks\V2\Internal\Service\Task\Gantt\GanttDependenceService;
use Bitrix\Tasks\V2\Public\Command\Gantt\AddDependenceCommand;
use Bitrix\Tasks\V2\Public\Command\Gantt\DeleteDependenceCommand;
use Bitrix\Tasks\V2\Public\Command\Gantt\UpdateDependenceCommand;
use Bitrix\Tasks\V2\Public\Provider\Params\Relation\RelationTaskParams;
use Bitrix\Tasks\V2\Public\Provider\Relation\GanttDependenceProvider;

class Dependence extends BaseController
{
	/**
	 * @ajaxAction tasks.V2.Task.Relation.Gantt.Dependence.list
	 */
	#[CloseSession]
	public function listAction(
		#[Read]
		Task $task,
		PageNavigation $pageNavigation,
		GanttDependenceProvider $ganttDependenceProvider,
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
			'tasks' => $ganttDependenceProvider->getTasks($params),
		];

		if ($withIds)
		{
			$response['ids'] = $ganttDependenceProvider->getTaskIds($params);
		}

		return $response;
	}

	/**
	 * @ajaxAction tasks.V2.Task.Relation.Gantt.Dependence.listByIds
	 */
	#[CloseSession]
	public function listByIdsAction(
		#[ElementsType(typeEnum: Type::Numeric)]
		array $taskIds,
		GanttDependenceProvider $ganttDependenceProvider,
	): array
	{
		return [
			'tasks' => $ganttDependenceProvider->getTasksByIds($taskIds, $this->userId),
		];
	}

	/**
	 * @ajaxAction tasks.V2.Task.Relation.Gantt.Dependence.check
	 */
	#[GanttRestriction]
	public function checkAction(
		#[Update]
		GanttLink $ganttLink,
		GanttDependenceService $ganttDependenceService,
	): ?bool
	{
		$result = $ganttDependenceService->check($ganttLink);

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return true;
	}

	/**
	 * @ajaxAction tasks.V2.Task.Relation.Gantt.Dependence.add
	 */
	#[GanttRestriction]
	public function addAction(
		#[Update]
		GanttLink $ganttLink,
	): ?bool
	{
		$result = (new AddDependenceCommand(
			taskId: $ganttLink->taskId,
			dependentId: $ganttLink->dependentId,
			userId: $this->userId,
			linkType: $ganttLink->type,
			useConsistency: true,
		))->run();

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return true;
	}

	/**
	 * @ajaxAction tasks.V2.Task.Relation.Gantt.Dependence.update
	 */
	public function updateAction(
		#[Update]
		GanttLink $ganttLink,
	): ?bool
	{
		$result = (new UpdateDependenceCommand(
			taskId: $ganttLink->taskId,
			dependentId: $ganttLink->dependentId,
			linkType: $ganttLink->type,
			useConsistency: true,
		))->run();

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return true;
	}

	/**
	 * @ajaxAction tasks.V2.Task.Relation.Gantt.Dependence.delete
	 */
	public function deleteAction(
		#[Update]
		GanttLink $ganttLink,
	): ?bool
	{
		$result = (new DeleteDependenceCommand(
			taskId: $ganttLink->taskId,
			dependentId: $ganttLink->dependentId,
			useConsistency: true,
		))->run();

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return true;
	}
}
