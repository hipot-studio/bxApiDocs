<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Infrastructure\Controller\Task;

use Bitrix\Main\Engine\ActionFilter\Attribute\Rule\CloseSession;
use Bitrix\Tasks\V2\Internal\Access\Service\TaskRightService;
use Bitrix\Tasks\V2\Internal\Access\Task\ActionDictionary;
use Bitrix\Tasks\V2\Infrastructure\Controller\BaseController;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Public\Command\Task\Access\RequestAccessCommand;
use Bitrix\Tasks\V2\Public\Provider\Task\AccessRequestProvider;

class Access extends BaseController
{
	/**
	 * @ajaxAction tasks.V2.Task.Access.get
	 */
	#[CloseSession]
	public function getAction(
		Entity\Task $task,
		TaskRightService $taskRightService,
	): array
	{
		$rights = $taskRightService->get(ActionDictionary::TASK_ACTIONS, $task->getId(), $this->userId);

		return [
			'taskId' => $task->getId(),
			'userId' => $this->userId,
			'rights' => $rights,
		];
	}

	/**
	 * @ajaxAction tasks.V2.Task.Access.request
	 */
	public function requestAction(Entity\Task $task): ?array
	{
		$result = (new RequestAccessCommand(
			userId: $this->userId,
			taskId: (int)$task->getId(),
		))->run();

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return [
			'accessRequest' => $result->getObject(),
		];
	}

	/**
	 * @ajaxAction tasks.V2.Task.Access.isRequested
	 */
	public function isRequestedAction(
		Entity\Task $task,
		AccessRequestProvider $accessRequestProvider,
	): bool
	{
		return $accessRequestProvider->isExist(
			userId: $this->userId,
			taskId: (int)$task->getId(),
		);
	}
}
