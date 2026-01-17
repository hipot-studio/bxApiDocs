<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Infrastructure\Controller\Copy;

use Bitrix\Tasks\V2\Infrastructure\Controller\BaseController;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Result\Result;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Copy\Config\CopyConfig;
use Bitrix\Tasks\V2\Public\Command\Task\Copy\CopyTaskCommand;
use Bitrix\Tasks\V2\Internal\Access\Task\Permission;
use Bitrix\Tasks\V2\Public\Provider\Params\TaskParams;
use Bitrix\Tasks\V2\Public\Provider\TaskProvider;

class Task extends BaseController
{
	/**
	 * @ajaxAction tasks.V2.Copy.Task.copy
	 */
	public function copyAction(
		#[Permission\Read]
		Entity\Task $task,
		TaskProvider $taskProvider,
		#[Permission\Read]
		?Entity\Task $targetTask = null,
		bool $withSubTasks = false,
		bool $withCheckLists = true,
		bool $withAttachments = true,
		bool $withRelatedTasks = true,
	): ?Entity\EntityInterface
	{
		$copyConfig = new CopyConfig(
			userId: $this->userId,
			withSubTasks: $withSubTasks,
			withCheckLists: $withCheckLists,
			withAttachments: $withAttachments,
			withRelatedTasks: $withRelatedTasks,
			targetTaskId: $targetTask?->getId(),
		);

		/** @var Result $result */
		$result = (new CopyTaskCommand(
			task: $task,
			config: $copyConfig,
		))->run();

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return $taskProvider->get(new TaskParams(taskId: $result->getId(), userId: $this->userId));
	}
}
