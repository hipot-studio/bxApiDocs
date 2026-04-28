<?php

namespace Bitrix\Im\V2\Message;

use Bitrix\Im\V2\Common\ContextCustomer;

use Bitrix\Im\V2\Reading\Counter\Adapter\LegacyCountersAdapter;
use Bitrix\Im\V2\Reading\Counter\CountersProvider;
use Bitrix\Im\V2\Reading\Counter\CountersService;
use Bitrix\Im\V2\Reading\Counter\Infrastructure\Agent\DeleteExpiredAgent;
use Bitrix\Im\V2\Reading\Counter\Infrastructure\Agent\DeleteFiredUserAgent;
use Bitrix\Im\V2\Reading\Counter\Internal\CountersCache;
use Bitrix\Im\V2\Reading\Counter\UserCountersCollector;
use Bitrix\Im\V2\Service\Context;
use Bitrix\Main\Data\Cache;
use Bitrix\Main\DI\ServiceLocator;
use CTimeZone;

class CounterService
{
	use ContextCustomer;

	protected const DELAY_DELETION_COUNTERS_OF_FIRED_USER = 604800; // 1 week

	protected const CACHE_TTL = 86400; // 1 month
	protected const CACHE_NAME = 'counter_v7';
	protected const CACHE_CHATS_COUNTERS_NAME = 'chats_counter_v7';
	protected const CACHE_PATH = '/bx/im/counter/v7/';

	protected static array $staticCounterCache = [];
	protected static array $staticChatsCounterCache = [];
	protected static array $staticSpecificChatsCounterCache = [];

	protected array $counters;

	public function __construct(?int $userId = null)
	{
		if (isset($userId))
		{
			$context = new Context();
			$context->setUser($userId);
			$this->setContext($context);
		}
	}

	/**
	 * @deprecated
	 * @use \Bitrix\Im\V2\Reading\Counter\CountersProvider::getForUsers
	 */
	public function getByChatForEachUsers(int $chatId, array $userIds): array
	{
		$counters = ServiceLocator::getInstance()->get(CountersProvider::class)->getForUsers($chatId, $userIds);

		return $counters->getRaw();
	}

	/**
	 * @deprecated
	 * @use \Bitrix\Im\V2\Reading\Counter\CountersProvider::getForUser
	 */
	public function getByChat(int $chatId): int
	{
		$userId = $this->getContext()->getUserId();

		return ServiceLocator::getInstance()->get(CountersProvider::class)->getForUser($chatId, $userId);
	}

	/**
	 * @deprecated
	 * @use \Bitrix\Im\V2\Reading\Counter\CountersService::getForUsersWithOverflowTracking
	 */
	public function getByChatWithOverflow(int $chatId): int
	{
		$userId = $this->getContext()->getUserId();

		return ServiceLocator::getInstance()
			->get(CountersService::class)
			->getForUsersWithOverflowTracking($chatId, [$userId])
			->getByUserId($userId)
		;
	}

	/**
	 * @deprecated
	 * @use \Bitrix\Im\V2\Reading\Counter\UserCountersCollector::get
	 */
	public function get(): array
	{
		$userId = $this->getContext()->getUserId();
		if (isset(self::$staticCounterCache[$userId]))
		{
			return self::$staticCounterCache[$userId];
		}

		$cache = $this->getCacheForPreparedCounters();
		$cachedCounters = $cache->getVars();
		if ($cachedCounters !== false)
		{
			self::$staticCounterCache[$userId] = $cachedCounters;

			return $cachedCounters;
		}

		$this->counters = $this->buildCounters();

		$this->savePreparedCountersInCache($cache);

		return $this->counters;
	}

	protected function buildCounters(): array
	{
		$collector = ServiceLocator::getInstance()->get(UserCountersCollector::class);
		$userCounters = $collector->get($this->getContext()->getUserId());

		$adapter = ServiceLocator::getInstance()->get(LegacyCountersAdapter::class);

		return $adapter->toArray($userCounters);
	}

	public static function onAfterUserUpdate(array $fields): void
	{
		if (!isset($fields['ACTIVE']))
		{
			return;
		}

		if ($fields['ACTIVE'] === 'N')
		{
			self::onFireUser((int)$fields['ID']);
		}
		else
		{
			self::onHireUser((int)$fields['ID']);
		}
	}

	/**
	 * @deprecated
	 * @use \Bitrix\Im\V2\Reading\Counter\UserCountersCollector::get
	 */
	public function getForEachChat(?array $chatIds = null): array
	{
		$result = [];
		if ($chatIds === null)
		{
			$collector = ServiceLocator::getInstance()->get(UserCountersCollector::class);
			$userCounters = $collector->get($this->getContext()->getUserId());
			foreach ($userCounters as $counter)
			{
				$result[$counter->chatId] = $counter->counter;
			}

			return $result;
		}

		$provider = ServiceLocator::getInstance()->get(CountersProvider::class);
		$counters = $provider->getForUserByChatIds($this->getContext()->getUserId(), $chatIds);

		return $counters->getRaw();
	}

	/**
	 * @deprecated
	 * @use \Bitrix\Im\V2\Reading\Counter\Infrastructure\Agent\DeleteFiredUserAgent::execute
	 */
	public static function deleteCountersOfFiredUserAgent(int $userId): string
	{
		return DeleteFiredUserAgent::execute($userId);
	}

	public static function deleteExpiredCountersAgent(): string
	{
		DeleteExpiredAgent::execute();

		return '\Bitrix\Im\V2\Message\CounterService::deleteExpiredCountersAgent();';
	}

	protected static function onFireUser(int $userId): void
	{
		\CAgent::AddAgent(
			"\Bitrix\Im\V2\Message\CounterService::deleteCountersOfFiredUserAgent({$userId});",
			'im',
			'N',
			self::DELAY_DELETION_COUNTERS_OF_FIRED_USER,
			'',
			'Y',
			ConvertTimeStamp(time()+CTimeZone::GetOffset()+self::DELAY_DELETION_COUNTERS_OF_FIRED_USER, "FULL"),
			existError: false
		);
	}

	protected static function onHireUser(int $userId): void
	{
		\CAgent::RemoveAgent(
			"\Bitrix\Im\V2\Message\CounterService::deleteCountersOfFiredUserAgent({$userId});",
			'im'
		);
	}

	public static function clearCache(?int $userId = null): void
	{
		$newCache = ServiceLocator::getInstance()->get(CountersCache::class);
		if (isset($userId))
		{
			$newCache->remove($userId);
		}
		else
		{
			$newCache->removeAll();
		}
	}

	public static function clearLegacyCache(?int $userId = null): void
	{
		$cache = \Bitrix\Main\Data\Cache::createInstance();
		if (isset($userId))
		{
			unset(self::$staticCounterCache[$userId], self::$staticChatsCounterCache[$userId], self::$staticSpecificChatsCounterCache[$userId]);
			$cache->clean(static::CACHE_NAME.'_'.$userId, self::CACHE_PATH);
			$cache->clean(static::CACHE_NAME.'_'.$userId, CounterServiceLegacy::CACHE_PATH);
			$cache->clean(self::CACHE_CHATS_COUNTERS_NAME.'_'.$userId, self::CACHE_PATH);
		}
		else
		{
			self::$staticCounterCache = [];
			self::$staticChatsCounterCache = [];
			self::$staticSpecificChatsCounterCache = [];
			$cache->cleanDir(self::CACHE_PATH);
			$cache->cleanDir(CounterServiceLegacy::CACHE_PATH);
		}
	}

	protected function getCacheForPreparedCounters(): Cache
	{
		$userId = $this->getContext()->getUserId();
		$cache = \Bitrix\Main\Data\Cache::createInstance();
		$cache->initCache(static::CACHE_TTL, static::CACHE_NAME . '_' . $userId, static::CACHE_PATH);

		return $cache;
	}

	protected function savePreparedCountersInCache(Cache $cache): void
	{
		$cache->startDataCache();
		$cache->endDataCache($this->counters);
		self::$staticCounterCache[$this->getContext()->getUserId()] = $this->counters;
	}
}
