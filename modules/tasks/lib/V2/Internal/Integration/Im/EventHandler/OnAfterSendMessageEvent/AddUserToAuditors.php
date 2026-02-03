<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Im\EventHandler\OnAfterSendMessageEvent;

use Bitrix\Im\V2\Chat\ExternalChat\Event\AfterSendMessageEvent;
use Bitrix\Tasks\V2\Internal\Logger;
use Bitrix\Tasks\V2\Internal\Repository\Task\Select;
use Bitrix\Tasks\V2\Internal\Repository\TaskReadRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Update\Config\UpdateConfig;
use Bitrix\Tasks\V2\Public\Command\Task\Stakeholder\AddAuditorsCommand;
use Bitrix\Tasks\V2\Public\Command\Task\Stakeholder\AddAuditorsHandler;

class AddUserToAuditors
{
	public function __construct
	(
		private readonly TaskReadRepositoryInterface $tasksRepository,
		private readonly AddAuditorsHandler $handler,
		private readonly Logger $logger,
	) {
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

		$task = $this->tasksRepository->getById((int)$event->getChat()->getEntityId(), new Select(members: true));

		if (null === $task)
		{
			return;
		}

		if (in_array($event->getMessage()->getAuthorId(), $task->getMemberIds()))
		{
			return;
		}

		$auditorsIds = $task->auditors->getIds();
		$auditorsIds[] = $event->getMessage()->getAuthorId();

		$command = new AddAuditorsCommand(
			taskId: $task->getId(),
			auditorIds: $auditorsIds,
			config: new UpdateConfig(
				userId: $event->getMessage()->getAuthorId(),
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
