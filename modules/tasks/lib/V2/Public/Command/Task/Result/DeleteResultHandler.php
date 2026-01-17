<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Command\Task\Result;

use Bitrix\Tasks\V2\Internal\Service\Consistency\ConsistencyResolverInterface;
use Bitrix\Tasks\V2\Internal\Service\Task\ResultService;

class DeleteResultHandler
{
	public function __construct(
		private readonly ResultService $resultService,
		private readonly ConsistencyResolverInterface $consistencyResolver,
	)
	{
	}

	public function __invoke(DeleteResultCommand $command): void
	{
		if ($command->useConsistency)
		{
			$this->consistencyResolver->resolve('task.result.delete')->wrap(
				fn() => $this->resultService->delete($command->result->id, $command->userId)
			);
		}
		else
		{
			$this->resultService->delete($command->result->id, $command->userId);
		}
	}
}
