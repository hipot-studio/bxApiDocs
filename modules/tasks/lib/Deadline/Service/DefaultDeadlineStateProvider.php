<?php

declare(strict_types=1);

namespace Bitrix\Tasks\Deadline\Service;

use Bitrix\Main\Type\DateTime;
use Bitrix\Tasks\Deadline\Internals\Repository\DeadlineUserOptionRepositoryInterface;
use Bitrix\Tasks\V2\Public\Provider\StateFlagsProvider;

class DefaultDeadlineStateProvider
{
	public function __construct(
		private readonly DeadlineUserOptionRepositoryInterface $deadlineUserOptionRepository,
		private readonly StateFlagsProvider $stateFlagsProvider,
		private readonly EffectiveDeadlineCalculator $effectiveDeadlineCalculator,
	)
	{
	}

	public function getDefaultDeadline(int $userId, DateTime $now): ?DateTime
	{
		$deadlineUserOption = $this->deadlineUserOptionRepository->getByUserId($userId);
		return $this->effectiveDeadlineCalculator->calculate(
			deadlineUserOption: $deadlineUserOption,
			matchesWorkTime: $this->stateFlagsProvider->getMatchesWorkTime($userId),
			now: $now,
		);
	}
}
