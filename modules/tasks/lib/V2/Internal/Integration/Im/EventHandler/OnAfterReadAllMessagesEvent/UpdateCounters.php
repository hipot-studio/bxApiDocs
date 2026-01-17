<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Im\EventHandler\OnAfterReadAllMessagesEvent;

use Bitrix\Im\V2\Chat\ExternalChat\Event\AfterReadAllMessagesEvent;
use Bitrix\Tasks\V2\Internal\Entity\User;
use Bitrix\Tasks\V2\Internal\Logger;
use Bitrix\Tasks\V2\Internal\Repository\Task\Select;
use Bitrix\Tasks\V2\Internal\Repository\TaskMemberRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Repository\TaskReadRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Service\Counter;

class UpdateCounters
{
	public function __construct
	(
		private readonly TaskReadRepositoryInterface $tasksRepository,
		private readonly TaskMemberRepositoryInterface $memberRepository,
		private readonly Counter\Service $counters,
		private readonly Logger $logger,
	) {
	}

	public function __invoke(AfterReadAllMessagesEvent $event): void
	{
		try
		{
			$task = $this->tasksRepository->getById((int)$event->getChat()->getEntityId(), select: new Select(members: true));
		}
		catch (\Throwable $e)
		{
			$this->logger->logError($e);
			return;
		}

		if (null === $task)
		{
			return;
		}

		try
		{
			/** @var ?User $member */
			$member = $this->memberRepository->get($task->getId())->filter(fn(User $member): bool => $member->getId() === (int) $event->getReaderId())->getFirstEntity();

			$this->counters->send(new Counter\Command\AfterCommentsRead(
				userId: $event->getReaderId(),
				taskId: $task->getId(),
			));
		}
		catch (\Throwable $e)
		{
			$this->logger->logError($e);
		}
	}
}
