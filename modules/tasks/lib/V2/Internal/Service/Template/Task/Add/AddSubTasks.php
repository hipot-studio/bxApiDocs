<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Template\Task\Add;

use Bitrix\Tasks\Control\Exception\TaskAddException;
use Bitrix\Tasks\Replication\Replicator\TemplateTaskReplicator;
use Bitrix\Tasks\V2\Internal\Service\Template\Task\Add\Config\AddTaskConfig;

class AddSubTasks
{
	/**
	 * @throws TaskAddException
	 */
	public function __invoke(int $taskId, int $templateId, AddTaskConfig $config): void
	{
		$replicator = new TemplateTaskReplicator($config->userId);
		$result = $replicator->setParentTaskId($taskId)->replicate($templateId);

		if (!$result->isSuccess())
		{
			throw new TaskAddException('Failed to create subtasks');
		}
	}
}
