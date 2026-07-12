<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Integration\Tasks\Service\Counter;

use Bitrix\Main\Loader;
use Bitrix\Tasks\Internals\Counter;

class ProjectTotalService
{

	public function __construct(
		private readonly CounterFactory $counterFactory,
	)
	{
	}

	public function getTotalForUser(int $userId): int
	{
		if ($userId < 1 || !$this->isTasksModuleAvailable())
		{
			return 0;
		}

		$counter = $this->counterFactory->getCounter($userId);

		$expired = $counter->get(Counter\CounterDictionary::COUNTER_SONET_TOTAL_EXPIRED);
		$mentioned = $counter->get(Counter\CounterDictionary::COUNTER_SONET_MUTED_MENTIONED);

		return $expired + $mentioned;
	}

	protected function isTasksModuleAvailable(): bool
	{
		return Loader::includeModule('tasks');
	}
}
