<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Command\Task\Replication;

use Bitrix\Tasks\V2\Internal\Service\ReplicationService;

class CreateReplicationTemplateHandler
{
	public function __construct(
		private readonly ReplicationService $replicationService,
	)
	{
	}

	public function __invoke(CreateReplicationTemplateCommand $command): int
	{
		return $this->replicationService->createTemplate(
			task: $command->task,
			userId: $command->userId,
		);
	}
}
