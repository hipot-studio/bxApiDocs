<?php

namespace Bitrix\Tasks\V2\Internal\Service\Task\Action\Update;

use Bitrix\Tasks\Integration\Bizproc\Listener;
use Bitrix\Tasks\Internals\TaskObject;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Update\Trait\ConfigTrait;

class RunBizProc
{
	use ConfigTrait;

	public function __invoke(array $fields, TaskObject $taskBeforeUpdate): void
	{
		$runtime = $this->config->getRuntime();
		if (!$this->config->isSkipBP())
		{
			Listener::onTaskUpdate($taskBeforeUpdate->getId(), $fields, $runtime->getEventTaskData());
		}
	}
}
