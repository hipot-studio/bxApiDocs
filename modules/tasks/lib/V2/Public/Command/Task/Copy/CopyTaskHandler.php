<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Command\Task\Copy;

use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Service\Task\CopyTaskService;

class CopyTaskHandler
{
	public function __construct(
		private readonly CopyTaskService $copyTaskService,
	)
	{
	}

	public function __invoke(CopyTaskCommand $command): Entity\Task
	{
		return $this->copyTaskService->copy(
			task: $command->task,
			config: $command->config,
		);
	}
}
