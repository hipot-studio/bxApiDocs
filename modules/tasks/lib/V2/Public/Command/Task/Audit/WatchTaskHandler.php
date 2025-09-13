<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Command\Task\Audit;

use Bitrix\Tasks\V2\Internal\Service\Consistency\ConsistencyResolverInterface;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Update\Config\UpdateConfig;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Update\UpdateUserFields;
use Bitrix\Tasks\V2\Internal\Repository\TaskMemberRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Service\Task\UpdateService;

class WatchTaskHandler
{
	public function __construct(
		private readonly TaskMemberRepositoryInterface $memberRepository,
		private readonly ConsistencyResolverInterface $consistencyResolver,
		private readonly UpdateService $updateService,
	)
	{

	}

	public function __invoke(WatchTaskCommand $command): Entity\UserCollection
	{
		$auditors = $this->memberRepository->getAuditors($command->taskId);
		if ($auditors->findOneById($command->auditorId))
		{
			return $auditors;
		}

		$auditors->add(Entity\User::mapFromId($command->auditorId));

		$task = new Entity\Task(
			id: $command->taskId,
			auditors: $auditors
		);

		$config = new UpdateConfig(
			userId: $command->userId,
			skipNotifications: $command->skipNotification,
		);

		[$taskAfter, $fields] = $this->consistencyResolver->resolve('task.watch')->wrap(
			fn (): array => $this->updateService->update($task, $config)
		);

		// this action is outside of consistency because it is containing nested transactions
		(new UpdateUserFields($config))($fields, $command->taskId);

		return $taskAfter->auditors;
	}
}