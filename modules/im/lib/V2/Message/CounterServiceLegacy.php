<?php

namespace Bitrix\Im\V2\Message;

use Bitrix\Im\V2\Reading\Counter\Adapter\LegacyCountersAdapter;
use Bitrix\Im\V2\Reading\Counter\UserCountersCollector;
use Bitrix\Main\DI\ServiceLocator;

class CounterServiceLegacy extends CounterService
{
	public const CACHE_PATH = '/bx/im/counter/';

	protected function buildCounters(): array
	{
		$userId = $this->getContext()->getUserId();
		$collector = ServiceLocator::getInstance()->get(UserCountersCollector::class);
		$userCounters = $collector->get($userId);

		$adapter = ServiceLocator::getInstance()->get(LegacyCountersAdapter::class);

		return $adapter->toArrayWithDialogs($userCounters, $userId);
	}
}