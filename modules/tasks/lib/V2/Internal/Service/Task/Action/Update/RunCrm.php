<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Task\Action\Update;

use Bitrix\Tasks\Integration\CRM\TimeLineManager;
use Bitrix\Tasks\Internals\TaskObject;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Update\Trait\ConfigTrait;

class RunCrm
{
	use ConfigTrait;

	public function __invoke(array $fields, TaskObject $taskBeforeUpdate): void
	{
		(new TimeLineManager($taskBeforeUpdate->getId(), $this->config->getUserId()))
			->onTaskUpdated($taskBeforeUpdate)
			->save();
	}
}
