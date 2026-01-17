<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Task;

use Bitrix\Tasks\Deadline\Entity\DeadlineUserOption;
use Bitrix\Tasks\Deadline\Internals\Repository\DeadlineUserOptionRepositoryInterface;
use Bitrix\Tasks\Integration\Pull\PushCommand;
use Bitrix\Tasks\Integration\Pull\PushService;

class DefaultDeadlineService
{
	public function __construct(
		private readonly DeadlineUserOptionRepositoryInterface $deadlineUserOptionRepository,
	)
	{

	}

	public function set(DeadlineUserOption $defaultDeadlineUserOption): void
	{
		$deadlineUserOption = $this->deadlineUserOptionRepository->getByUserId($defaultDeadlineUserOption->userId);

		$deadlineUserOption->defaultDeadlineInSeconds = $defaultDeadlineUserOption->defaultDeadlineInSeconds;
		$deadlineUserOption->isExactDeadlineTime = $defaultDeadlineUserOption->isExactDeadlineTime;

		$deadlineUserOption->canChangeDeadline = $defaultDeadlineUserOption->canChangeDeadline;
		$deadlineUserOption->maxDeadlineChangeDate = $defaultDeadlineUserOption->maxDeadlineChangeDate;
		$deadlineUserOption->maxDeadlineChanges = $defaultDeadlineUserOption->maxDeadlineChanges;
		$deadlineUserOption->requireDeadlineChangeReason = $defaultDeadlineUserOption->requireDeadlineChangeReason;
		$deadlineUserOption->matchWorkTime = $defaultDeadlineUserOption->matchWorkTime;

		$this->deadlineUserOptionRepository->save($deadlineUserOption);

		$this->sendPush(
			$defaultDeadlineUserOption->userId,
			[
				'deadline' => $defaultDeadlineUserOption->defaultDeadlineInSeconds,
				'defaultDeadlineDate' => $deadlineUserOption->toArray()['defaultDeadlineDate'],
				'canChangeDeadline' => $defaultDeadlineUserOption->canChangeDeadline,
				'maxDeadlineChangeDate' => $defaultDeadlineUserOption->maxDeadlineChangeDate ? : null,
				'maxDeadlineChanges' => $defaultDeadlineUserOption->maxDeadlineChanges,
				'requireDeadlineChangeReason' => $defaultDeadlineUserOption->requireDeadlineChangeReason,
				'deadlineUserOption' => $deadlineUserOption->toArray(),
			],
		);
	}

	private function sendPush(int $userId, array $params = []): void
	{
		PushService::addEvent($userId, [
			'module_id' => 'tasks',
			'command' => PushCommand::DEFAULT_DEADLINE_UPDATED,
			'params' => $params,
		]);
	}
}
