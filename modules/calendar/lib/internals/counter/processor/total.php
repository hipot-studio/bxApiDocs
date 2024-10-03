<?php

namespace Bitrix\Calendar\Internals\Counter\Processor;

use Bitrix\Calendar\Internals\Counter;
use Bitrix\Calendar\Internals\Counter\CounterDictionary;
use Bitrix\Calendar\Internals\Counter\Event\Event;
use Bitrix\Calendar\Internals\Counter\Event\EventCollection;

class Total implements Base
{
	public function process(): void
	{
		$events = (EventCollection::getInstance())->list();
		foreach ($events as $event)
		{
			/* @var $event Event */
			$affectedUsers = $event->getData()['user_ids'] ?? [];
			$this->recountTotal($affectedUsers);
		}
	}

	private function recountTotal(array $userIds): void
	{
		if (empty($userIds))
		{
			return;
		}

		foreach ($userIds as $userId)
		{
			$value = Counter::getInstance($userId)->get(CounterDictionary::COUNTER_TOTAL);
			if (!$this->isSameValueCached($value, $userId))
			{
				\CUserCounter::Set(
					$userId,
					CounterDictionary::COUNTER_TOTAL,
					$value,
					'**',
				);
			}
		}
	}

	private function isSameValueCached(int $value, int $userId): bool
	{
		global $CACHE_MANAGER;

		$cache = $CACHE_MANAGER->Get('user_counter' . $userId);
		if (!$cache)
		{
			return false;
		}

		foreach ($cache as $item)
		{
			if (
				$item['CODE'] === CounterDictionary::COUNTER_TOTAL
				&& $item['SITE_ID'] === '**'
				&& (int)$item['CNT'] === $value
			)
			{
				return true;
			}
		}

		return false;
	}
}