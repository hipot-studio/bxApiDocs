<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Task;

use Bitrix\Tasks\Deadline\Internals\Repository\DeadlineUserOptionRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Entity\State\StateFlags;
use Bitrix\Tasks\V2\Internal\Entity\Task\State;
use Bitrix\Tasks\V2\Internal\Service\OptionDictionary;
use Bitrix\Tasks\V2\Internal\Service\State\StateFlagsService;

class StateService
{
	public function __construct(
		private readonly DefaultDeadlineService $defaultDeadlineService,
		private readonly DeadlineUserOptionRepositoryInterface $deadlineUserOptionRepository,
		private readonly StateFlagsService $stateFlagsService,
	)
	{

	}

	public function set(State $state, int $userId): void
	{
		if ($state->defaultDeadline !== null)
		{
			$state->defaultDeadline->userId = $userId;
			$this->defaultDeadlineService->set($state->defaultDeadline);
		}

		$flags = new StateFlags(
			needsControl: $state->needsControl,
			matchesWorkTime: $state->matchesWorkTime,
			defaultRequireResult: $state->defaultRequireResult,
			allowsTimeTracking: $state->allowsTimeTracking,
		);

		$this->stateFlagsService->set($flags, OptionDictionary::StateFlags, $userId);
	}

	public function get(int $userId): ?State
	{
		$deadlineUserOption = $this->deadlineUserOptionRepository->getByUserId($userId);
		$flags = $this->stateFlagsService->get(OptionDictionary::StateFlags, $userId);

		return State::mapFromArray([
			'defaultDeadline' => $deadlineUserOption,
			'userId' => $userId,
			'matchesWorkTime' => $flags->matchesWorkTime ?? true,
			'needsControl' => $flags->needsControl,
			'defaultRequireResult' => $flags->defaultRequireResult,
			'allowsTimeTracking' => $flags->allowsTimeTracking,
		]);
	}
}
