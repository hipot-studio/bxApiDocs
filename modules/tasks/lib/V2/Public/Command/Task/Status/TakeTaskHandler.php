<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Command\Task\Status;

use Bitrix\Tasks\V2\Internal\Service\Task\StatusService;
use Bitrix\Tasks\V2\Internal\Entity;

class TakeTaskHandler
{
	public function __construct(
		private readonly StatusService $statusService,
	)
	{

	}

	public function __invoke(TakeTaskCommand $command): Entity\Task
	{
		return $this->statusService->take($command->taskId, $command->config);
	}
}
