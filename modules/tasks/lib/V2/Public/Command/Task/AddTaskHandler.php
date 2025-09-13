<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Command\Task;

use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Service\Consistency\ConsistencyResolverInterface;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Add\AddUserFields;
use Bitrix\Tasks\V2\Internal\Service\Task\AddService;

class AddTaskHandler
{
	public function __construct(
		private readonly ConsistencyResolverInterface $consistencyResolver,
		private readonly AddService $addService,
	)
	{
	}

	public function __invoke(AddTaskCommand $command): Entity\Task
	{
		[$task, $fields] = $this->consistencyResolver->resolve('task.add')->wrap(
			fn (): array => $this->addService->add($command->task, $command->config)
		);

		// this action is outside of consistency because it contains nested transactions
		(new AddUserFields($command->config))($fields);

		return $task;
	}
}
