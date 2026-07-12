<?php

namespace Bitrix\Call\Cache;

use Bitrix\Main\Data\Cache;
use Bitrix\Main\Application;
use Bitrix\Call\JwtCall;
use Bitrix\Call\Util;
use Bitrix\Call\CallFactory;
use Bitrix\Call\Model\CallUserTable;


/**
 * @internal
 */
class ActiveCallsCache
{
	private const CACHE_TTL = 86400;
	private const CACHE_DIR = 'call/active_calls';

	/**
	 * Generates cache ID for user's active calls
	 *
	 * @param int $userId User ID
	 * @return string Cache ID string
	 */
	private static function getUserCacheId(int $userId): string
	{
		return 'call/active_calls_' . $userId;
	}

	/**
	 * Generates cache tag for user's active calls
	 *
	 * @param int $userId User ID
	 * @return string Cache tag string
	 */
	private static function getUserCacheTag(int $userId): string
	{
		return 'call/active_calls_user_' . $userId;
	}

	/**
	 * Generates cache tag for a specific call
	 *
	 * @param int $callId Call ID
	 * @return string Cache tag string
	 */
	private static function getCallCacheTag(int $callId): string
	{
		return 'call/active_call_call_' . $callId;
	}

	/**
	 * Retrieves active calls for a specific user from cache or rebuilds if not cached.
	 *
	 * @param int $userId User ID.
	 * @return array Array of active calls data for the user.
	 */
	public static function getActiveCallsForUser(int $userId): array
	{
		$cache = Cache::createInstance();
		if ($cache->initCache(self::CACHE_TTL, self::getUserCacheId($userId), self::CACHE_DIR))
		{
			return $cache->getVars();
		}

		return self::updateUserActiveCallsCache($userId);
	}

	/**
	 * Updates and rebuilds the active calls cache for a specific user.
	 *
	 * @param int $userId User ID.
	 * @return array Updated active calls data for the user.
	 */
	public static function updateUserActiveCallsCache(int $userId): array
	{
		return self::buildAndWriteForUsers([$userId])[$userId] ?? [];
	}

	/**
	 * Writes one user's active-calls payload into the file cache plus the
	 * tagged-cache index, mirroring the side-effects the legacy per-user
	 * path produced.
	 *
	 * @param array<int, array> $data [callId => callData]
	 */
	private static function writeUserActiveCallsCache(int $userId, array $data): void
	{
		$cache = Cache::createInstance();
		$cache->forceRewriting(true);
		if ($cache->startDataCache(self::CACHE_TTL, self::getUserCacheId($userId), self::CACHE_DIR))
		{
			$tagged = Application::getInstance()->getTaggedCache();
			$tagged->startTagCache(self::CACHE_DIR);

			$tagged->registerTag(self::getUserCacheTag($userId));

			foreach ($data as $callId => $callData)
			{
				$tagged->registerTag(self::getCallCacheTag($callId));
			}

			$tagged->endTagCache();
			$cache->endDataCache($data);
		}
	}

	/**
	 * Updates cache for all users participating in a specific call.
	 *
	 * @param int $callId Call ID.
	 * @return void
	 */
	public static function updateCallCache(int $callId): void
	{
		$userRows = CallUserTable::query()
			->addSelect('USER_ID')
			->where('CALL_ID', $callId)
			->fetchAll()
		;

		if (!$userRows)
		{
			return;
		}

		$userIds = array_map('intval', array_column($userRows, 'USER_ID'));
		self::updateUsersActiveCallsCache($userIds);
	}

	/**
	 * Batched counterpart of {@see self::updateUserActiveCallsCache()}. Rebuilds
	 * active-calls cache for every userId in one call — intended to be handed
	 * off to {@see \Bitrix\Main\Application::addBackgroundJob()} so it runs
	 * after the HTTP response is sent.
	 *
	 * @param int[] $userIds
	 */
	public static function updateUsersActiveCallsCache(array $userIds): void
	{
		self::buildAndWriteForUsers($userIds);
	}

	/**
	 * Single entry-point for both {@see self::updateUserActiveCallsCache()}
	 * and {@see self::updateUsersActiveCallsCache()}. Pre-fetches the entire
	 * active-calls graph for the input userIds in three batched SELECTs:
	 *
	 *  1. CallFactory::getUsersActiveCalls()  — every active CallTable row a
	 *     userId participates in (joined on CALL_USER, partitioned by USER_ID)
	 *  2. CallUserTable::getList(IN $callIds) — every b_call_user row for the
	 *     unique callIds touched, grouped by callId for {@see Call::setPreloadedUsers()}
	 *  3. Util::getUsers(allParticipantIds)   — primes the static profile cache
	 *
	 * After the prefetch, the per-user payload is assembled in PHP without
	 * additional SQL: each Call instance is built via
	 * {@see CallFactory::getCallInstance()}, fed its CallUser rows through
	 * {@see Call::setPreloadedUsers()} (so {@see Call::loadUsers()} short-circuits),
	 * and the cache is written via {@see self::writeUserActiveCallsCache()}.
	 *
	 * Replaces the legacy O(N) hot path (≥ 2 SELECTs per userId in
	 * {@see self::buildActiveCallsArray()} → 866 SQL for N=433). For one chat
	 * of N participants the new path issues ≤ 5 SQL total (1 active-calls,
	 * 1 call-users, 1 user-profiles, plus one cache write per user).
	 *
	 * @param int[] $userIds
	 * @return array<int, array> [userId => activeCallsPayload]
	 */
	private static function buildAndWriteForUsers(array $userIds): array
	{
		$normalized = [];
		foreach ($userIds as $uid)
		{
			$uid = (int)$uid;
			if ($uid > 0)
			{
				$normalized[$uid] = true;
			}
		}
		if ($normalized === [])
		{
			return [];
		}
		$normalized = array_keys($normalized);

		$activeCallsByUser = CallFactory::getUsersActiveCalls($normalized);

		$uniqueCallIds = [];
		$participantIds = [];
		foreach ($activeCallsByUser as $userCalls)
		{
			foreach ($userCalls as $callId => $_row)
			{
				$uniqueCallIds[(int)$callId] = true;
			}
		}
		$uniqueCallIds = array_keys($uniqueCallIds);

		$callUsersByCallId = self::loadCallUserRows($uniqueCallIds);
		foreach ($callUsersByCallId as $callId => $rows)
		{
			foreach ($rows as $row)
			{
				$pid = (int)($row['USER_ID'] ?? 0);
				if ($pid > 0)
				{
					$participantIds[$pid] = true;
				}
			}
		}

		// Prime Util::getUsers static cache once for every participant the
		// downstream toArray()/USER_DATA paths will touch — subsequent
		// per-user assemblies hit the cache instead of doing N+1 SELECTs.
		if ($participantIds !== [])
		{
			Util::getUsers(array_keys($participantIds));
		}

		$result = [];
		foreach ($normalized as $userId)
		{
			$userCalls = $activeCallsByUser[$userId] ?? [];
			$payload = [];
			foreach ($userCalls as $callId => $callRow)
			{
				$callInstance = CallFactory::getCallInstance($callRow['PROVIDER'], $callRow);
				if (isset($callUsersByCallId[$callId]))
				{
					$callInstance->setPreloadedUsers($callUsersByCallId[$callId]);
				}
				$callParticipantIds = $callInstance->getUsers();

				$callToken = '';
				if (!empty($callRow['CHAT_ID']) && (int)$callRow['CHAT_ID'] > 0)
				{
					$callToken = JwtCall::getCallToken((int)$callRow['CHAT_ID']);
				}

				$payload[$callId] = array_merge(
					$callInstance->toArray($userId),
					[
						'CALL_TOKEN' => $callToken,
						'CONNECTION_DATA' => $callInstance->getConnectionData($userId),
						'USERS' => $callParticipantIds,
						'LOG_TOKEN' => $callInstance->getLogToken($userId),
						'USER_DATA' => Util::getUsers($callParticipantIds),
					]
				);
			}

			self::writeUserActiveCallsCache($userId, $payload);
			$result[$userId] = $payload;
		}

		return $result;
	}

	/**
	 * Loads `b_call_user` rows for a set of callIds in chunks of 500, grouped
	 * by callId for {@see Call::setPreloadedUsers()}.
	 *
	 * @param int[] $callIds
	 * @return array<int, array<int, array>> [callId => [userId => row]]
	 */
	private static function loadCallUserRows(array $callIds): array
	{
		$grouped = [];
		if ($callIds === [])
		{
			return $grouped;
		}

		foreach (array_chunk($callIds, 500) as $chunk)
		{
			$cursor = CallUserTable::getList([
				'filter' => ['=CALL_ID' => $chunk],
			]);
			while ($row = $cursor->fetch())
			{
				$callId = (int)($row['CALL_ID'] ?? 0);
				$userId = (int)($row['USER_ID'] ?? 0);
				if ($callId <= 0 || $userId <= 0)
				{
					continue;
				}
				$grouped[$callId][$userId] = $row;
			}
		}

		return $grouped;
	}

	/**
	 * Clears active calls cache for a specific user.
	 *
	 * @param int $userId User ID.
	 * @return void
	 */
	public static function clearUserActiveCallsCache(int $userId): void
	{
		$tagged = Application::getInstance()->getTaggedCache();
		$tagged->clearByTag(self::getUserCacheTag($userId));
	}

	/**
	 * Clears cache for a specific call across all users.
	 *
	 * @param int $callId Call ID.
	 * @return void
	 */
	public static function clearCallCache(int $callId): void
	{
		$tagged = Application::getInstance()->getTaggedCache();
		$tagged->clearByTag(self::getCallCacheTag($callId));
	}
}
