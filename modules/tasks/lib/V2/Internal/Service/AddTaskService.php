<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service;

use Bitrix\Tasks\Control\Exception\TaskAddException;
use Bitrix\Tasks\Control\Exception\TaskNotExistsException;
use Bitrix\Tasks\V2\Internal\Entity\Task;
use Bitrix\Tasks\V2\Internal\Service\Consistency\ConsistencyResolverInterface;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Add\AddUserFields;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Add\Config\AddConfig;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Add\Integration\RunBizProc;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Add\Integration\RunCrm;
use Bitrix\Tasks\V2\Internal\Service\Task\AddService;

class AddTaskService
{
	public function __construct(
		private readonly AddService $addService,
		private readonly ConsistencyResolverInterface $consistencyResolver,
	)
	{

	}

	/**
	 * @throws TaskNotExistsException
	 * @throws TaskAddException
	 */
	public function add(Task $task, AddConfig $config): Task
	{
		if ($config->isUseConsistency())
		{
			[$task, $fields] = $this->consistencyResolver->resolve('task.add')->wrap(
				fn (): array => $this->addService->add($task, $config)
			);
		}
		else
		{
			[$task, $fields] = $this->addService->add($task, $config);
		}

		(new AddUserFields($config))($fields);

		(new RunBizProc($config))($fields);

		(new RunCrm($config))($fields);

		return $task;
	}
}
