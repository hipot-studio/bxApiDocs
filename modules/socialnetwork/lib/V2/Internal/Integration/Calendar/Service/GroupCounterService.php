<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Integration\Calendar\Service;

use Bitrix\Calendar\Public\Provider\GroupCounterProvider;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Loader;
use Bitrix\Socialnetwork\V2\Internal\Entity\Counter;
use Bitrix\Socialnetwork\V2\Internal\Entity\CounterCollection;

class GroupCounterService
{
	private ?GroupCounterProvider $provider;

	public function __construct()
	{
		$this->provider = null;

		if (!Loader::includeModule('calendar'))
		{
			return;
		}

		if (class_exists(GroupCounterProvider::class))
		{
			$this->provider = ServiceLocator::getInstance()->get(GroupCounterProvider::class);
		}
	}

	public function getTotalGroupCounters(int $userId, array $groupIds): CounterCollection
	{
		$collection = new CounterCollection();
		if (!$this->provider)
		{
			return $collection;
		}

		$counterMap = $this->provider->getGroupTotalMapByGroupIds($userId, $groupIds);

		foreach ($counterMap as $groupId => $totalValue)
		{
			$collection->add(new Counter($groupId, $totalValue, null));
		}

		return $collection;
	}

	public function getTotalCountForGroupIds(int $userId, array $groupIds): int
	{
		if (!$this->provider)
		{
			return 0;
		}

		return array_sum($this->provider->getGroupTotalMapByGroupIds($userId, $groupIds));
	}
}
