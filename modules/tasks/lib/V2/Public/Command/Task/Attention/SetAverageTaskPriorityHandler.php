<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Command\Task\Attention;

use Bitrix\Tasks\V2\Internal\Service\Consistency\ConsistencyResolverInterface;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Update\Config\UpdateConfig;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Update\UpdateUserFields;
use Bitrix\Tasks\V2\Internal\Service\Task\UpdateService;
use Bitrix\Tasks\V2\Internal\Entity;

class SetAverageTaskPriorityHandler
{
	public function __construct(
		private readonly ConsistencyResolverInterface $consistencyResolver,
		private readonly UpdateService $updateService,
	)
	{

	}

	public function __invoke(SetAverageTaskPriorityCommand $command): Entity\Task
	{
		$entity = new Entity\Task(
			id:       $command->taskId,
			priority: Entity\Task\Priority::Average,
		);

		$config = new UpdateConfig(
			userId: $command->userId,
		);

		[$task, $fields] = $this->consistencyResolver->resolve('task.priority')->wrap(
			fn (): array => $this->updateService->update($entity, $config)
		);

		// this action is outside of consistency because it is containing nested transactions
		(new UpdateUserFields($config))($fields, $command->taskId);

		return $task;
	}
}