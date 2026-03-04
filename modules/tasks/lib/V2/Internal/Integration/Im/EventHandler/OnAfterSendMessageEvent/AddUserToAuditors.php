<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Im\EventHandler\OnAfterSendMessageEvent;

use Bitrix\Im\V2\Chat\ExternalChat\Event\AfterSendMessageEvent;
use Bitrix\Tasks\V2\Internal\Repository\TaskRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Update\Config\UpdateConfig;
use Bitrix\Tasks\V2\Public\Command\Task\Stakeholder\AddAuditorsCommand;

class AddUserToAuditors
{
	public function __construct
	(
		private readonly TaskRepositoryInterface $tasksRepository,
	)
	{
	}

	public function __invoke(AfterSendMessageEvent $event): void
	{
		if ($event->getMessage()->isSystem())
		{
			return;
		}

		if ($event->getMessage()->getAuthorId() === 0)
		{
			return;
		}

		if ($event->getMessage()->getAuthor()?->isBot())
		{
			return;
		}

		$task = $this->tasksRepository->getById((int)$event->getChat()->getEntityId());

		if ($task === null)
		{
			return;
		}

		if (in_array($event->getMessage()->getAuthorId(), $task->getMemberIds(), true))
		{
			return;
		}

		(new AddAuditorsCommand(
			taskId: $task->getId(),
			auditorIds: [$event->getMessage()->getAuthorId()],
			config: new UpdateConfig(
				userId: $event->getMessage()->getAuthorId(),
			),
		))->run();
	}
}
