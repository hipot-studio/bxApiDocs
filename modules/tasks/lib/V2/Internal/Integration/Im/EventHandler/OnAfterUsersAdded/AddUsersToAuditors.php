<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Im\EventHandler\OnAfterUsersAdded;

use Bitrix\Im\V2\Chat\ExternalChat\Event\AfterUsersAddEvent;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Tasks\V2\Internal\Repository\TaskRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Update\Config\UpdateConfig;
use Bitrix\Tasks\V2\Public\Command\Task\Stakeholder\AddAuditorsCommand;

class AddUsersToAuditors
{
	public function __construct
	(
		private readonly TaskRepositoryInterface $tasksRepository,
	)
	{
	}

	public function __invoke(AfterUsersAddEvent $event): void
	{
		$task = $this->tasksRepository->getById((int)$event->getChat()->getEntityId());

		if ($task === null)
		{
			return;
		}

		$newUserIds = [];
		foreach ($event->getChanges()->getNewMembers() as $userId)
		{
			if ($userId > 0 && !in_array($userId, $task->getMemberIds(), true))
			{
				$newUserIds[] = $userId;
			}
		}

		if (empty($newUserIds))
		{
			return;
		}

		$currentUserId = (int)CurrentUser::get()?->getId();

		(new AddAuditorsCommand(
			taskId: $task->getId(),
			auditorIds: $newUserIds,
			config: new UpdateConfig(
				// TODO: After complete task https://bitrix24.team/workgroups/group/2553/tasks/task/view/652963/
				// should be replaced with $event->getTriggetedUser() or something similar.
				userId: $currentUserId > 0 ? $currentUserId : $task->creator->getId(),
			),
		))->run();
	}
}
