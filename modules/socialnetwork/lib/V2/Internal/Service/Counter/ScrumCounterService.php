<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Service\Counter;

use Bitrix\Socialnetwork\V2\Internal\Entity\CounterCollection;
use Bitrix\Socialnetwork\V2\Internal\Integration\Tasks;

class ScrumCounterService
{
	public function __construct(
		private readonly Tasks\Service\Counter\ScrumCounterService $scrumCounterService,
	)
	{
	}

	public function getCounters(array $projectIds, int $userId): CounterCollection
	{
		return $this->scrumCounterService->getCounters($projectIds, $userId);
	}
}
