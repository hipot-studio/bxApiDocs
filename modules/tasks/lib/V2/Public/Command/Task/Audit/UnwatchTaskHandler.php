<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Command\Task\Audit;

use Bitrix\Main\Application;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Update\Config\UpdateConfig;
use Bitrix\Tasks\V2\Internal\Repository\TaskMemberRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Service\Task\Mutation\TaskMutationLock;
use Bitrix\Tasks\V2\Internal\Service\Task\Mutation\TaskMutationRegistry;
use Bitrix\Tasks\V2\Internal\Service\Task\Mutation\TaskMutationScope;
use Bitrix\Tasks\V2\Internal\Service\UpdateTaskService;

class UnwatchTaskHandler
{
	public function __construct(
		private readonly TaskMemberRepositoryInterface $memberRepository,
		private readonly UpdateTaskService $updateTaskService,
		private readonly TaskMutationRegistry $mutationRegistry,
		private readonly TaskMutationLock $mutationLock,
	)
	{

	}

	public function __invoke(UnwatchTaskCommand $command): Entity\Task
	{
		return $this->mutationRegistry->run(
			Application::getConnection(),
			function (TaskMutationScope $scope) use ($command): Entity\Task {
				$this->mutationLock->acquire($command->taskId, $scope);

				$auditors = $this->memberRepository->getAuditors($command->taskId);
				if (!$auditors->findOneById($command->auditorId))
				{
					return new Entity\Task(id: $command->taskId, auditors: $auditors);
				}

				$auditors->remove($command->auditorId);
				$config = new UpdateConfig(
					userId: $command->userId,
					useConsistency: $command->useConsistency,
					origin: $command->origin,
				);

				return $this->updateTaskService->update(
					task: new Entity\Task(id: $command->taskId, auditors: $auditors),
					config: $config,
				);
			},
		);
	}
}
