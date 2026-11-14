<?php

namespace Bitrix\Call;

use Bitrix\Call\Service\CallLogService;
use Bitrix\Main\Application;
use Bitrix\Main\EventResult;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\UserCounterTable;

/**
 * @internal
 */
class Counter
{
	const CACHE_TTL = 86400; // 1 day
	const CACHE_NAME = 'call_counter_v1';
	const CACHE_PATH = '/bx/call/counter/';

	const TYPE_CALLLOG = 'list';
	const MODULE_ID = 'call';

	// App menu badge reads b_user_counter['call_list']; the pull:onGetMobileCounter surface keeps its own
	// TYPE_CALLLOG ('list') identifier. Two ids for the same missed-calls entity — do not unify them.
	const USER_COUNTER_CODE = 'call_list';

	private const LOCK_WAIT_SEC = 1; // GET_LOCK wait timeout, in seconds

	/**
	 * Get count of missed calls for a specific user
	 *
	 * Answered from the cache when it is warm: a publication of the number leaves it there under the lock of the
	 * badge (@see self::refresh()), and a miss is counted here and cached.
	 *
	 * @param int $userId
	 * @return int
	 */
	public static function getCount(int $userId): int
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

		// The mobile badge and the journal must count the same thing. Counting rows by USER_ID here would
		// keep showing a guest the missed calls of a chat they were removed from - and those rows are the
		// ones the journal deliberately never clears, so the badge would stay lit with nothing able to put
		// it out. The cut lives in one place: CallLogService.
		$count = (new CallLogService())->getMissedCounter($userId);

		$cache->startDataCache();
		$cache->endDataCache($count);

		return $count;
	}

	/**
	 * Put an already counted number into the counter cache of a user
	 *
	 * Nothing is counted here - the caller brings the number. A warm cache is rewritten rather than left
	 * alone: startDataCache() writes nothing on a hit, and a warm-up that only wrote on a miss would silently
	 * keep the previous number.
	 *
	 * @param int $userId
	 * @param int $value
	 * @return void
	 */
	public static function warmCache(int $userId, int $value): void
	{
		if ($userId <= 0)
		{
			return;
		}

		$cache = \Bitrix\Main\Data\Cache::createInstance();
		$cache->forceRewriting(true);
		if ($cache->startDataCache(self::CACHE_TTL, self::CACHE_NAME . '_' . $userId, self::CACHE_PATH))
		{
			$cache->endDataCache($value);
		}
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
	 * Recompute the missed-calls value the way the journal counts it - a guest only within the chats
	 * they are still in - and persist it to b_user_counter (app menu badge). CUserCounter::Set also
	 * broadcasts the standard user_counter pull. The recount and the write share a per-user lock,
	 * so concurrent updates cannot overwrite a fresher absolute value with a stale one.
	 *
	 * @param int $userId
	 * @return int|null Recomputed value, or null if the per-user lock could not be taken; the badge then self-heals on the next counter change.
	 */
	public static function resync(int $userId): ?int
	{
		if ($userId <= 0)
		{
			return 0;
		}

		return self::recountUnderLock($userId, static function (int $count) use ($userId): int {
			\CUserCounter::Set($userId, self::USER_COUNTER_CODE, $count, '**');
			self::clearCache($userId);

			return $count;
		});
	}

	/**
	 * Recount the value the way resync() does and store it before the per-user lock is released: the badge only
	 * when its row holds another number - writing it drops the managed cache of every counter of the user - and
	 * the counter cache with the recount either way.
	 *
	 * @param int $userId
	 * @return array{count: int, isChanged: bool}|null The recount and whether the badge was rewritten, or null if
	 *     the per-user lock could not be taken; nothing is stored then.
	 */
	public static function refresh(int $userId): ?array
	{
		if ($userId <= 0)
		{
			return ['count' => 0, 'isChanged' => false];
		}

		return self::recountUnderLock($userId, static function (int $count) use ($userId): array {
			$isChanged = $count !== self::readBadge($userId);
			if ($isChanged)
			{
				\CUserCounter::Set($userId, self::USER_COUNTER_CODE, $count, '**');
			}
			self::warmCache($userId, $count);

			return ['count' => $count, 'isChanged' => $isChanged];
		});
	}

	/**
	 * The number the app menu badge holds, under the code and the site marker resync() writes it with.
	 *
	 * Read off b_user_counter rather than through CUserCounter: that one remembers the values it has read for
	 * the rest of the process, so a number another process wrote since would not be seen. Zero while the user
	 * has no row yet.
	 *
	 * @param int $userId
	 * @return int
	 */
	public static function readBadge(int $userId): int
	{
		if ($userId <= 0)
		{
			return 0;
		}

		$row = UserCounterTable::getByPrimary(
			['USER_ID' => $userId, 'SITE_ID' => '**', 'CODE' => self::USER_COUNTER_CODE],
			['select' => ['CNT']],
		)->fetch();

		return is_array($row) ? (int)$row['CNT'] : 0;
	}

	/**
	 * Hand $store the value recounted under the per-user lock of the badge, with both pinned to master.
	 *
	 * @param int $userId
	 * @param callable(int): mixed $store
	 * @return mixed What $store returned, or null if the lock is held by another process.
	 */
	private static function recountUnderLock(int $userId, callable $store): mixed
	{
		$connection = Application::getConnection();
		$lockName = 'call_counter_' . $userId;
		if (!$connection->lock($lockName, self::LOCK_WAIT_SEC))
		{
			// Another recompute for this user is in flight, and it may have counted before the change that brought
			// the caller here. Nothing is stored; who stores the number later is up to the caller.
			return null;
		}

		$pool = Application::getInstance()->getConnectionPool();
		try
		{
			// Pin the recount and the write to master: after a cluster delete a lagging replica may still
			// return the removed rows, and the badge is read before it is written.
			$pool->useMasterOnly(true);

			// A fresh service on purpose: it keeps guest flags and chat membership for its lifetime, and the
			// membership must be read here, under the lock, rather than taken from whoever asked for the recount.
			$count = (new CallLogService())->getMissedCounter($userId);

			return $store($count);
		}
		finally
		{
			// Release the advisory lock while still pinned to master: on a PG cluster release_lock is a
			// plain SELECT and would be routed to a replica that never held it, leaving the lock stuck.
			try
			{
				$connection->unlock($lockName);
			}
			finally
			{
				$pool->useMasterOnly(false);
			}
		}
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
