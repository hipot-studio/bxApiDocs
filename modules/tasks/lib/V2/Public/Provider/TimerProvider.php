<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Provider;

use Bitrix\Tasks\V2\Internal\Entity\Task\Timer;
use Bitrix\Tasks\V2\Internal\Repository\TimerRepositoryInterface;

class TimerProvider
{
	public function __construct(
		private readonly TimerRepositoryInterface $timerRepository,
	)
	{
	}

	public function getActiveTimer(int $userId): ?Timer
	{
		$timer = $this->timerRepository->get($userId);
		if ($timer && $timer->startedAtTs)
		{
			return $timer;
		}

		return null;
	}
}
