<?php

declare(strict_types=1);

namespace Bitrix\Calendar\Internal\Service;

use Bitrix\Calendar\Internal\Factory\CounterFactory;
use Bitrix\Calendar\Internals\Counter;
use Bitrix\Main\Type\Collection;

class GroupCounterService
{
	protected array $counterValueCache = [];

	public function __construct(
		private readonly CounterFactory $counterFactory,
	)
	{
	}

	public function getGroupTotalMapByGroupIds(int $userId, array $groupIds): array
	{
		$counterName = Counter\CounterDictionary::COUNTER_GROUP_INVITES;
		$this->counterValueCache[$userId][$counterName] ??= [];
		$cachedValues = $this->counterValueCache[$userId][$counterName];

		$counterMap = [];
		$notInCacheGroupIds = [];
		Collection::normalizeArrayValuesByInt($groupIds, false);

		foreach ($groupIds as $groupId)
		{
			if (!\array_key_exists($groupId, $cachedValues))
			{
				$notInCacheGroupIds[] = $groupId;
				continue;
			}

			$counterMap[$groupId] = (int)$cachedValues[$groupId];
		}

		if (!$notInCacheGroupIds)
		{
			return $counterMap;
		}

		$counterService = $this->counterFactory->factory($userId);
		foreach ($notInCacheGroupIds as $groupId)
		{
			$value = (int)$counterService->get($counterName, $groupId);
			$this->counterValueCache[$userId][$counterName][$groupId] = $value;
			$counterMap[$groupId] = $value;
		}

		return $counterMap;
	}
}
