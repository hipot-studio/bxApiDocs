<?php

namespace Bitrix\Call;

use Bitrix\Main\Loader;
use Bitrix\Main\Data\Cache;
use Bitrix\Main\Data\Cache\KeyValueEngine;
use Bitrix\Main\Config\Configuration;
use Bitrix\Call\Model\CallTable;
use Bitrix\Main\Type\DateTime;
use Bitrix\Call\Cache\ActiveCallsCache;

class Recent
{
	public const ACTIVE_CALLS_DEPTH_HOURS = 12;

	/**
	 * TTL of the KV stamp used by {@see self::scheduleUpdateCallCache()} to coalesce the decline-storm.
	 */
	private const SCHEDULE_TOKEN_TTL = 60;

	private static ?ActiveCallsCache $callsCache = null;

	private static function callsCache(): ActiveCallsCache
	{
		if (!self::$callsCache)
		{
			self::$callsCache = new ActiveCallsCache();
		}

		return self::$callsCache;
	}

	protected static function getCurrentUserId(): int
	{
		global $USER;

		return $USER->getId();
	}

	/**
	 * Gets list active calls of a user on portal
	 *
	 * @return array
	 */
	public static function getActiveCalls(): array
	{
		if (!Settings::isNewCallsEnabled())
		{
			return [];
		}

		$userId = self::getCurrentUserId();

		return self::callsCache()->getActiveCallsForUser($userId);
	}

	public static function finishActiveCalls(int $depthHours = self::ACTIVE_CALLS_DEPTH_HOURS): void
	{
		Loader::includeModule('im');

		$callList = CallTable::getList([
			'select' => array_keys(CallTable::getEntity()->getScalarFields()),
			'filter' => [
				'!=STATE' => Call::STATE_FINISHED,
				'<START_DATE' => (new DateTime())->add("-{$depthHours} hour"),
			]
		]);

		while ($row = $callList->fetch())
		{
			$call = CallFactory::createWithArray($row['PROVIDER'], $row);
			$call->finish();
			self::callsCache()->updateCallCache($call->getId());
		}
	}

	/**
	 * Updates and rebuilds the active calls cache for a specific user
	 *
	 * @param int $userId User ID
	 * @return array Updated active calls data for the user
	 */
	public static function updateUserActiveCallsCache(int $userId): array
	{
		return self::callsCache()->updateUserActiveCallsCache($userId);
	}

	/**
	 * Batched counterpart of {@see self::updateUserActiveCallsCache()}.
	 *
	 * @param int[] $userIds
	 */
	public static function updateUsersActiveCallsCache(array $userIds): void
	{
		self::callsCache()->updateUsersActiveCallsCache($userIds);
	}

	/**
	 * Updates cache for all users participating in a specific call
	 *
	 * @param int $callId Call ID
	 * @return void
	 */
	public static function updateCallCache(int $callId): void
	{
		self::callsCache()->updateCallCache($callId);
	}

	/**
	 * Defers {@see self::updateUsersActiveCallsCache()} to run after the HTTP
	 * response is sent. Used on the startCall / decline hot path so clients
	 * are not blocked by ~5 SQL + 2 JWT + file-cache writes per participant.
	 *
	 * @param int[] $userIds
	 */
	public static function scheduleUpdateUsersActiveCallsCache(array $userIds): void
	{
		if ($userIds === [])
		{
			return;
		}
		\Bitrix\Main\Application::getInstance()->addBackgroundJob(
			[ActiveCallsCache::class, 'updateUsersActiveCallsCache'],
			[$userIds],
			\Bitrix\Main\Application::JOB_PRIORITY_LOW,
		);
	}

	/**
	 * Defers {@see self::updateCallCache()} to run after the HTTP response is
	 * sent. Background job resolves participants via a single SELECT inside
	 * ActiveCallsCache::updateCallCache and then rebuilds each user's cache.
	 *
	 * Trailing-edge coalescing of the decline-storm: each
	 * request stamps a monotonic token in shared KV cache and schedules a job
	 * carrying that token. The job aborts if it sees a strictly fresher token
	 * — the latest scheduling owns the rebuild and runs after every coalesced
	 * request has committed its state change to the DB. Job count under a
	 * 66-decline storm stays at 66, but 65 of them are KV-read no-ops.
	 *
	 * Falls back to unconditional scheduling on file-cache deployments (no
	 * shared KV available): correctness over storm-cost.
	 */
	public static function scheduleUpdateCallCache(int $callId): void
	{
		if ($callId <= 0)
		{
			return;
		}

		$engine = self::getKvCacheEngine();
		if ($engine === null)
		{
			\Bitrix\Main\Application::getInstance()->addBackgroundJob(
				[ActiveCallsCache::class, 'updateCallCache'],
				[$callId],
				\Bitrix\Main\Application::JOB_PRIORITY_LOW,
			);

			return;
		}

		$token = (int)round(microtime(true) * 1000);
		$engine->set(self::scheduleTokenKey($callId), self::SCHEDULE_TOKEN_TTL, $token);

		\Bitrix\Main\Application::getInstance()->addBackgroundJob(
			[self::class, 'runScheduledUpdateCallCache'],
			[$callId, $token],
			\Bitrix\Main\Application::JOB_PRIORITY_LOW,
		);
	}

	/**
	 * Background-job entry point for {@see self::scheduleUpdateCallCache()}.
	 * Aborts when the KV stamp shows a strictly later scheduling — that
	 * request's job will rebuild after committing its own state change. Ties
	 * (sub-millisecond bursts) intentionally let through extra rebuilds:
	 * cheaper than risking a stale cache.
	 */
	public static function runScheduledUpdateCallCache(int $callId, int $token): void
	{
		$engine = self::getKvCacheEngine();
		if ($engine !== null)
		{
			$latest = (int)$engine->get(self::scheduleTokenKey($callId));
			if ($latest > $token)
			{
				return;
			}
		}

		self::callsCache()->updateCallCache($callId);
	}

	private static function getKvCacheEngine(): ?KeyValueEngine
	{
		$engine = Cache::createCacheEngine();

		return $engine instanceof KeyValueEngine ? $engine : null;
	}

	private static function scheduleTokenKey(int $callId): string
	{
		$cacheConfig = Configuration::getValue('cache');
		$sid = (is_array($cacheConfig) && !empty($cacheConfig['sid'])) ? $cacheConfig['sid'] : 'BX';

		return $sid . '|call:sched_token:' . $callId;
	}

	/**
	 * Terminates all active calls in a chat except the specified call
	 *
	 * @param int $chatId Chat ID
	 * @param int|null $excludeCallId Call ID to exclude from termination
	 * @return void
	 */
	public static function terminateAllCallsInChat(int $chatId, ?int $excludeCallId = null): void
	{
		if (!$chatId)
		{
			return;
		}

		if (!Loader::includeModule('im'))
		{
			return;
		}

		$chat = \Bitrix\Im\V2\Chat::getInstance($chatId);
		if ($chat instanceof \Bitrix\Im\V2\Chat\NullChat)
		{
			return;
		}

		$depthHours = self::ACTIVE_CALLS_DEPTH_HOURS;
		if ($chat instanceof \Bitrix\Im\V2\Chat\PrivateChat)
		{
			$filter = [
				'=PROVIDER' => Call::PROVIDER_PLAIN,
				'=ENTITY_TYPE' => Integration\EntityType::CHAT,
				'=CHAT_ID' => $chatId,
				'!=STATE' => Call::STATE_FINISHED,
				'>START_DATE' => (new DateTime())->add("-{$depthHours} hour"),
			];
		}
		else
		{
			$filter = [
				'=PROVIDER' => Call::PROVIDER_BITRIX,
				'=ENTITY_TYPE' => Integration\EntityType::CHAT,
				'=ENTITY_ID' => 'chat' . $chatId,
				'!=STATE' => Call::STATE_FINISHED,
				'>START_DATE' => (new DateTime())->add("-{$depthHours} hour"),
			];
		}

		if ($excludeCallId)
		{
			$filter['!=ID'] = $excludeCallId;
		}

		$callList = CallTable::getList([
			'select' => array_keys(CallTable::getEntity()->getScalarFields()),
			'filter' => $filter,
			'order' => ['ID' => 'DESC']
		]);

		while ($callData = $callList->fetch())
		{
			// Reset END_DATE to null before creating the call instance to ensure proper finish() behavior
			$callData['END_DATE'] = null;

			$activeCall = CallFactory::createWithArray($callData['PROVIDER'], $callData);
			if ($activeCall->getState() === Call::STATE_FINISHED)
			{
				continue;
			}

			$activeCall->finish();
			self::updateCallCache($activeCall->getId());
		}
	}

	/**
	 * Finishes any non-finished PROVIDER_PLAIN call the user participates in.
	 * Called on new 1-1 initiation: a user cannot be in two 1-1 calls at once,
	 * so anything left over is stuck. STATE/LAST_SEEN are not checked —
	 * JWT plain calls do not update LAST_SEEN during a call.
	 */
	public static function terminateStuckPlainCallsForUser(int $userId): void
	{
		if (!$userId || !Loader::includeModule('im'))
		{
			return;
		}

		$depthHours = self::ACTIVE_CALLS_DEPTH_HOURS;
		$callList = CallTable::query()
			->setSelect(array_keys(CallTable::getEntity()->getScalarFields()))
			->where('PROVIDER', Call::PROVIDER_PLAIN)
			->whereNot('STATE', Call::STATE_FINISHED)
			->where('START_DATE', '>', (new DateTime())->add("-{$depthHours} hour"))
			->where('CALL_USER.USER_ID', $userId)
			->setOrder(['ID' => 'DESC'])
			->exec()
		;

		while ($callData = $callList->fetch())
		{
			$callData['END_DATE'] = null;

			$call = CallFactory::createWithArray($callData['PROVIDER'], $callData);
			if ($call->getState() === Call::STATE_FINISHED)
			{
				continue;
			}

			$call->finish();
			self::updateCallCache($call->getId());
		}
	}

	public static function finishOldCallsAgent(): string
	{
		if (!Loader::includeModule('im'))
		{
			return __METHOD__ . '();';
		}

		$callList = CallTable::getList([
			'select' => array_keys(CallTable::getEntity()->getScalarFields()),
			'filter' => [
				'!=STATE' => Call::STATE_FINISHED,
				'<START_DATE' => (new DateTime())->add('-' . self::ACTIVE_CALLS_DEPTH_HOURS . ' hour'),
			]
		]);

		while ($row = $callList->fetch())
		{
			$call = CallFactory::createWithArray($row['PROVIDER'], $row);
			$call->finish();

			self::callsCache()->updateCallCache($call->getId());

			(new Analytics\CallAnalytics($call))->finishOldCalls();
		}

		return __METHOD__ . '();';
	}
}
