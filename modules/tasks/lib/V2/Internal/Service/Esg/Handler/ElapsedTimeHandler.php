<?php

namespace Bitrix\Tasks\V2\Internal\Service\Esg\Handler;

use Bitrix\Tasks\V2\Internal\Entity\Task\Elapsed\Source;
use Bitrix\Tasks\V2\Internal\Integration\Im\ChatNotificationInterface;
use Bitrix\Tasks\V2\Internal\Integration\Im\NotificationType;
use Bitrix\Tasks\V2\Internal\Repository\TaskReadRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Repository\UserRepositoryInterface;
use Bitrix\Tasks\V2\Public\Command\AbstractCommand;
use Bitrix\Tasks\V2\Public\Command\Task\Tracking\AddElapsedTimeCommand;
use Bitrix\Tasks\V2\Internal\Entity\Task\ElapsedTime;

class ElapsedTimeHandler implements EgressHandlerInterface
{
	public function __construct(
		private readonly ChatNotificationInterface $chatNotification,
		private readonly TaskReadRepositoryInterface $taskReadRepository,
		private readonly UserRepositoryInterface $userRepository,
	)
	{
	}

	public function handle(AbstractCommand $command): void
	{
		match (true)
		{
			$command instanceof AddElapsedTimeCommand => $this->handleAddElapsedTime($command->elapsedTime),
			default => '',
		};
	}

	private function handleAddElapsedTime(ElapsedTime $elapsedTime): void
	{
		if ($elapsedTime->source !== Source::Manual)
		{
			return;
		}

		$task = $this->taskReadRepository->getById($elapsedTime->taskId);

		if (!$task)
		{
			return;
		}

		$triggeredBy = $this->userRepository->getByIds([$elapsedTime->userId])->findOneById($elapsedTime->userId);

		$this->chatNotification->notify(
			type: NotificationType::ElapsedTimeAdded,
			task: $task,
			args: [
				'triggeredBy' => $triggeredBy,
				'elapsedTime' => $elapsedTime,
			],
		);
	}
}
