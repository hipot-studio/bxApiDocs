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

	/**
	 * @param (callable(int): DeadlineUserOption)|null $getSavedOption
	 */
	public function set(
		DeadlineUserOption $defaultDeadlineUserOption,
		?callable $getSavedOption = null,
	): array
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

		if ($getSavedOption !== null)
		{
			$deadlineUserOption = $getSavedOption($defaultDeadlineUserOption->userId);
		}

		// every toArray() recalculates the deadline date from the current moment, so the answer and the push
		// have to share one array: otherwise a minute crossed between them gives the open form two dates
		$savedOption = $deadlineUserOption->toArray();

		$this->sendPush(
			$defaultDeadlineUserOption->userId,
			[
				'deadline' => $savedOption['defaultDeadlineInSeconds'],
				'defaultDeadlineDate' => $savedOption['defaultDeadlineDate'],
				'canChangeDeadline' => $savedOption['canChangeDeadline'],
				'maxDeadlineChangeDate' => $savedOption['maxDeadlineChangeDate'] ?: null,
				'maxDeadlineChanges' => $savedOption['maxDeadlineChanges'],
				'requireDeadlineChangeReason' => $savedOption['requireDeadlineChangeReason'],
				'deadlineUserOption' => $savedOption,
			],
		);

		return $savedOption;
	}

	protected function sendPush(int $userId, array $params = []): void
	{
		PushService::addEvent($userId, [
			'module_id' => 'tasks',
			'command' => PushCommand::DEFAULT_DEADLINE_UPDATED,
			'params' => $params,
		]);
	}
}
