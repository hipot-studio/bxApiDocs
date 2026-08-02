<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Command\Task\Replication;

use Bitrix\Tasks\V2\Internal\Service\ReplicationService;

class SetReplicationStateHandler
{
	public function __construct(
		private readonly ReplicationService $replicationService,
	)
	{
	}

	public function __invoke(SetReplicationStateCommand $command): void
	{
		$this->replicationService->setReplicationStateByTask(
			task: $command->task,
			userId: $command->userId,
		);
	}
}
