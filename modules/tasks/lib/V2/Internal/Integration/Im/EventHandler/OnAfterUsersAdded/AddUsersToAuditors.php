<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Im\EventHandler\OnAfterUsersAdded;

use Bitrix\Im\V2\Chat\ExternalChat\Event\AfterUsersAddEvent;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Tasks\V2\Internal\Entity\User;
use Bitrix\Tasks\V2\Internal\Logger;
use Bitrix\Tasks\V2\Internal\Repository\Task\Select;
use Bitrix\Tasks\V2\Internal\Repository\TaskReadRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Repository\UserRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Update\Config\UpdateConfig;
use Bitrix\Tasks\V2\Public\Command\Task\Stakeholder\AddAuditorsCommand;
use Bitrix\Tasks\V2\Public\Command\Task\Stakeholder\AddAuditorsHandler;

class AddUsersToAuditors
{
	public function __construct
	(
		private readonly TaskReadRepositoryInterface $tasksRepository,
		private readonly UserRepositoryInterface $userRepository,
		private readonly AddAuditorsHandler $handler,
		private readonly Logger $logger,
	) {
	}

	public function __invoke(AfterUsersAddEvent $event): void
	{
		$task = $this->tasksRepository->getById((int)$event->getChat()->getEntityId(), new Select(members: true));

		if (null === $task)
		{
			return;
		}

		try
		{
			$usersToBeAdded = $this->userRepository->getByIds(
				$event->getChanges()->getNewMembers())->filter(
					fn(User $user): bool => !in_array($user->getId(), $task->getMemberIds())
			);
		}
		catch (\Throwable $e)
		{
			$this->logger->logError($e);
			return;
		}

		$currentUserId = (int)CurrentUser::get()?->getId();
		$command = new AddAuditorsCommand(
			taskId: $task->getId(),
			auditorIds: $usersToBeAdded->getIds(),
			config: new UpdateConfig(
				// TODO: After complete task https://bitrix24.team/workgroups/group/2553/tasks/task/view/652963
				// should be replaced with $event->getTriggetedUser() or something similar.
				userId: $currentUserId > 0 ? $currentUserId : $task->creator->getId(),
			)
		);

		try
		{
			$this->handler->__invoke($command);
		}
		catch (\Throwable $e)
		{
			$this->logger->logError($e);
		}
	}
}
