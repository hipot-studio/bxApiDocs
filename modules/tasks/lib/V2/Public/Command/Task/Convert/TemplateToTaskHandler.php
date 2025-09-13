<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Command\Task\Convert;

use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Service\Consistency\ConsistencyResolverInterface;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Add\AddUserFields;
use Bitrix\Tasks\V2\Internal\Service\Task\AddService;
use Bitrix\Tasks\V2\Public\Provider\TaskFromTemplateProvider;

class TemplateToTaskHandler
{
	public function __construct(
		private readonly ConsistencyResolverInterface $consistencyResolver,
		private readonly AddService $addService,
        private readonly TaskFromTemplateProvider $provider,
	)
	{
	}

	public function __invoke(TemplateToTaskCommand $command): Entity\Task
	{
		$task = $this->provider->getTaskByTemplateId($command->templateId);

		[$task, $fields] = $this->consistencyResolver->resolve('task.add')->wrap(
			fn (): array => $this->addService->add($task, $command->config)
		);

		// this action is outside of consistency because it contains nested transactions
		(new AddUserFields($command->config))($fields);

		return $task;
	}
}
