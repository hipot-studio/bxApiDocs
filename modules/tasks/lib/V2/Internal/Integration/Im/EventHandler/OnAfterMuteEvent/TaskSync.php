<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Im\EventHandler\OnAfterMuteEvent;

use Bitrix\Im\V2\Chat\ExternalChat\Event\AfterMuteEvent;
use Bitrix\Tasks\V2\Internal\Logger;
use Bitrix\Tasks\V2\Internal\Repository\Task\Select;
use Bitrix\Tasks\V2\Internal\Repository\TaskReadRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Traits\MutexTrait;
use Bitrix\Tasks\V2\Public\Command;

class TaskSync
{
	use MutexTrait;

	public function __construct(
		private readonly TaskReadRepositoryInterface $repository,
		private readonly Logger $logger,
	)
	{
	}

	public function __invoke(AfterMuteEvent $event): void
	{
		if (self::locked())
		{
			return;
		}

		try
		{
			$task = $this->repository->getById(
				id: (int)$event->getChat()->getEntityId(),
				select: new Select(options: true),
			);
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

		$shouldSkip = $event->isMuted() === in_array($event->getUserId(), $task->inMute, true);

		if ($shouldSkip)
		{
			return;
		}

		$command = $event->isMuted()
			? new Command\Task\Attention\MuteTaskCommand(
				taskId: (int)$event->getChat()->getEntityId(),
				userId: $event->getUserId(),
			)
			: new Command\Task\Attention\UnmuteTaskCommand(
				taskId: (int)$event->getChat()->getEntityId(),
				userId: $event->getUserId(),
			)
		;

		self::lock();

		try
		{
			$command->run();
		}
		catch (\Throwable $e)
		{
			$this->logger->logError($e);
		}

		self::unlock();
	}
}
