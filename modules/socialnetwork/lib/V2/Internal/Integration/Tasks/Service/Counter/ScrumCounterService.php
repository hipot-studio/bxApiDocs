<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Integration\Tasks\Service\Counter;

use Bitrix\Main\Loader;
use Bitrix\Socialnetwork\V2\Internal\Entity\Counter;
use Bitrix\Socialnetwork\V2\Internal\Entity\CounterCollection;
use Bitrix\Socialnetwork\V2\Internal\Entity\CounterColor;
use Bitrix\Tasks\Internals\Counter\Template\ScrumCounter;

class ScrumCounterService
{
	public function getCounters(array $scrumIds, int $userId): CounterCollection
	{
		if (empty($scrumIds) || !$this->isTasksModuleAvailable())
		{
			return new CounterCollection();
		}

		$entities = [];
		$scrumCounter = $this->createScrumCounter($userId);

		foreach ($scrumIds as $scrumId)
		{
			$scrumId = (int)$scrumId;
			$counterData = $scrumCounter->getRowCounter($scrumId);

			$entities[$scrumId] = new Counter(
				groupId: $scrumId,
				value: (int)($counterData['VALUE'] ?? 0),
				color: CounterColor::tryFrom((string)($counterData['COLOR'] ?? '')),
			);
		}

		return new CounterCollection(...$entities);
	}

	protected function isTasksModuleAvailable(): bool
	{
		return Loader::includeModule('tasks');
	}

	protected function createScrumCounter(int $userId): ScrumCounter
	{
		return new ScrumCounter($userId);
	}
}
