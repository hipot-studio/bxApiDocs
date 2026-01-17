<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Command\Task\Result;

use Bitrix\Tasks\V2\Internal\Entity\Task;
use Bitrix\Tasks\V2\Internal\Service\Task\ResultService;

class RequireResultHandler
{
	public function __construct(
		private readonly ResultService $resultService,
	)
	{
	}

	public function __invoke(RequireResultCommand $command): Task
	{
		return $this->resultService->require(
			$command->taskId,
			$command->userId,
			$command->require,
			$command->useConsistency,
		);
	}
}

