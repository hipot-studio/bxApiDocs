<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Template\Task\Add;

use Bitrix\Tasks\Control\Exception\TaskAddException;
use Bitrix\Tasks\Replication\Replicator\TemplateTaskReplicator;
use Bitrix\Tasks\V2\Internal\Service\Template\Task\Add\Trait\ConfigTrait;

class AddSubTasks
{
	use ConfigTrait;

	/**
	 * @throws TaskAddException
	 */
	public function __invoke(int $taskId, int $templateId): void
	{
		$replicator = new TemplateTaskReplicator($this->config->userId);
		$result = $replicator->setParentTaskId($taskId)->replicate($templateId);

		if (!$result->isSuccess())
		{
			throw new TaskAddException('Failed to create subtasks');
		}
	}
}
