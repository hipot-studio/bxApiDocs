<?php

namespace Bitrix\Tasks\Promotion;

use Bitrix\Main\Data\Cache;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\UserTable;
use Bitrix\Tasks\Onboarding\FeatureAvailability;

/**
 * "New user" segment predicate of the onboarding banners feature.
 *
 * A user belongs to the segment when their registration date is not earlier than
 * the moment the feature became available on the portal; the boundary itself is
 * inclusive. Membership never expires: for how long the banners keep showing is
 * limited by the trigger counters and by the close count, not by this predicate.
 */
class NewUserSegment
{
	/**
	 * Membership is decided once and never changes, so the entry only has to be
	 * re-created often enough to keep the cache storage from growing forever.
	 */
	private const CACHE_TTL = 2592000;

	private const CACHE_DIR_PREFIX = '/tasks/onboarding/new_user_segment/';

	/**
	 * Per-hit memoization: the predicate is called from several render points on
	 * one page (task list, kanban, V2 card config) and its inputs cannot change
	 * within a single hit.
	 *
	 * @var array<int, bool>
	 */
	private static array $isNewUserCache = [];

	public function isNewUser(int $userId): bool
	{
		return self::$isNewUserCache[$userId] ??= $this->calculateIsNewUser($userId);
	}

	/**
	 * Clears the per-hit memoization.
	 *
	 * For test isolation only: production never invalidates the cache because the
	 * predicate inputs cannot change within a single hit.
	 *
	 * @internal
	 */
	public static function resetRuntimeCache(): void
	{
		self::$isNewUserCache = [];
	}

	/**
	 * Drops both caches for a single user.
	 *
	 * Needed by tests, which have to observe a cold predicate; production has no
	 * reason to call it, because a decided membership never changes and a changed
	 * availability moment already produces a different cache id.
	 *
	 * @internal
	 */
	public static function clearCache(int $userId): void
	{
		unset(self::$isNewUserCache[$userId]);

		// The directory holds this user's entries only, one per availability moment.
		Cache::createInstance()->cleanDir(self::getCacheDir($userId));
	}

	private function calculateIsNewUser(int $userId): bool
	{
		if ($userId <= 0)
		{
			return false;
		}

		// The option is read first: the predicate runs on every task creation, and
		// without a known boundary the b_user query would be wasted work.
		$featureStartDate = $this->getFeatureStartDate();

		// An unknown availability moment puts nobody into the segment. The answer is
		// not cached: the option arrives with the update and the very next hit may
		// already see it.
		if ($featureStartDate === null)
		{
			return false;
		}

		$cache = Cache::createInstance();
		$cacheId = self::getCacheId($userId, $featureStartDate);
		$cacheDir = self::getCacheDir($userId);

		if ($cache->initCache(self::CACHE_TTL, $cacheId, $cacheDir))
		{
			$cachedIsNewUser = $cache->getVars();
			if (is_bool($cachedIsNewUser))
			{
				return $cachedIsNewUser;
			}
		}

		$userDateRegister = $this->fetchRegistrationDate($userId);

		// Neither a missing b_user row nor a missing registration date proves that the
		// user joined after the feature had arrived — and both can turn into a real
		// registration date later, so this answer stays out of the persistent cache.
		if ($userDateRegister === null)
		{
			return false;
		}

		$isNewUser = $userDateRegister->getTimestamp() >= $featureStartDate->getTimestamp();

		// Both outcomes are stable: the registration date is a system field and the
		// availability moment is part of the cache id.
		if ($cache->startDataCache())
		{
			$cache->endDataCache($isNewUser);
		}

		return $isNewUser;
	}

	protected function fetchRegistrationDate(int $userId): ?DateTime
	{
		$user = UserTable::query()
			->setSelect(['DATE_REGISTER'])
			->where('ID', $userId)
			->setLimit(1)
			->fetch()
		;

		$userDateRegister = $user['DATE_REGISTER'] ?? null;

		return $userDateRegister instanceof DateTime ? $userDateRegister : null;
	}

	protected function getFeatureStartDate(): ?DateTime
	{
		return FeatureAvailability::getStartDate();
	}

	/**
	 * The availability moment is part of the id, so moving the option moves the whole
	 * segment onto fresh entries instead of reusing answers taken against the old one.
	 */
	private static function getCacheId(int $userId, DateTime $featureStartDate): string
	{
		return 'tasks.new_user_segment.v1.' . $userId . '.' . $featureStartDate->getTimestamp();
	}

	private static function getCacheDir(int $userId): string
	{
		return self::CACHE_DIR_PREFIX . substr(md5((string)$userId), 2, 2) . '/' . $userId . '/';
	}
}
