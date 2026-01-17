<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Infrastructure\Controller\Task\Relation\Multi;

use Bitrix\Main\Validation\Rule\ElementsType;
use Bitrix\Main\Validation\Rule\Enum\Type;
use Bitrix\Tasks\V2\Infrastructure\Controller\BaseController;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Result\Result;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Copy\Config\CopyConfig;
use Bitrix\Tasks\V2\Public\Command\Task\Relation\AddMultiTaskChildrenCommand;

class Task extends BaseController
{
	/**
	 * @ajaxAction tasks.V2.Task.Relation.Multi.Task.add
	 */
	public function addAction(
		// todo: access rights
		Entity\Task $task,
		#[ElementsType(typeEnum: Type::Numeric)]
		array $userIds,
	): ?Entity\TaskCollection
	{
		$result = (new AddMultiTaskChildrenCommand(
			taskId: (int)$task->getId(),
			userIds: $userIds,
			config: new CopyConfig(
				userId: $this->userId,
				withCheckLists: true,
				withAttachments: true,
				withRelatedTasks: true,
				withReminders: true,
				withGanttLinks: true,
				useConsistency: true,
			),
		))->run();

		/** @var Result $result */
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return $result->getCollection();
	}
}
