<?php

namespace Bitrix\Call;

use Bitrix\Call\Model\CallUserLogCountersTable;
use Bitrix\Main\EventResult;
use Bitrix\Main\Localization\Loc;

class Counter
{
	const CACHE_TTL = 86400; // 1 day
	const CACHE_NAME = 'call_counter_v1';
	const CACHE_PATH = '/bx/call/counter/';

	const TYPE_CALLLOG = 'calllog';
	const MODULE_ID = 'call';

	/**
	 * Get count of missed calls for a specific user
	 *
	 * @param int $userId
	 * @return int
	 */
	private static function getCount(int $userId): int
	{
		if ($userId <= 0)
		{
			return 0;
		}

		$cache = \Bitrix\Main\Data\Cache::createInstance();
		if ($cache->initCache(self::CACHE_TTL, self::CACHE_NAME . '_' . $userId, self::CACHE_PATH))
		{
			return (int)$cache->getVars();
		}

		$count = CallUserLogCountersTable::getCount(['USER_ID' => $userId]);

		$cache->startDataCache();
		$cache->endDataCache($count);

		return $count;
	}

	/**
	 * Clear cache for a specific user or all users
	 *
	 * @param int|null $userId
	 * @return bool
	 */
	public static function clearCache($userId = null): bool
	{
		$cache = \Bitrix\Main\Data\Cache::createInstance();
		if ($userId)
		{
			$cache->clean(self::CACHE_NAME . '_' . $userId, self::CACHE_PATH);
		}
		else
		{
			$cache->cleanDir(self::CACHE_PATH);
		}

		return true;
	}

	/**
	 * Handler for mobile counter types registration
	 *
	 * @param \Bitrix\Main\Event $event
	 * @return EventResult
	 */
	public static function onGetMobileCounterTypes(\Bitrix\Main\Event $event): EventResult
	{
		return new EventResult(
			EventResult::SUCCESS,
			[
				self::TYPE_CALLLOG => [
					'NAME' => Loc::getMessage('CALL_COUNTER_TYPE_CALLLOG'),
					'DEFAULT' => true
				],
			],
			self::MODULE_ID
		);
	}

	/**
	 * Handler for mobile counter value
	 *
	 * @param \Bitrix\Main\Event $event
	 * @return EventResult
	 */
	public static function onGetMobileCounter(\Bitrix\Main\Event $event): EventResult
	{
		$params = $event->getParameters();
		$userId = (int)($params['USER_ID']);

		$counter = self::getCount($userId);

		$mobileCounters = [
			[
				'TYPE' => self::TYPE_CALLLOG,
				'COUNTER' => $counter,
			],
		];

		return new EventResult(EventResult::SUCCESS, $mobileCounters, self::MODULE_ID);
	}
}
