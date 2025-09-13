<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Command\Task\Status;

use Bitrix\Tasks\V2\Internal\Entity\Task;
use Bitrix\Tasks\V2\Internal\Service\Consistency\ConsistencyResolverInterface;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Update\UpdateUserFields;
use Bitrix\Tasks\V2\Internal\Service\Task\StatusService;

class PauseTaskHandler
{
	public function __construct(
		private readonly StatusService $statusService,
		private readonly ConsistencyResolverInterface $consistencyResolver,
	)
	{

	}

	public function __invoke(PauseTaskCommand $command): Task
	{
		[$task, $fields] = $this->consistencyResolver->resolve('task.status')->wrap(
			fn () => $this->statusService->pause($command->taskId, $command->config)
		);

		// this action is outside of consistency because it is containing nested transactions
		(new UpdateUserFields($command->config))($fields, $command->taskId);

		return $task;
	}
}