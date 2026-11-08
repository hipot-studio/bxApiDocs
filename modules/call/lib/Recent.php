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
	 * TTL of the KV stamps used to coalesce request storms: the cache-rebuild stamp of
	 * {@see self::scheduleUpdateCallCache()} and the recovery-teardown stamp of
	 * {@see self::scheduleTerminateAllCallsInChat()}.
	 */
	private const SCHEDULE_TOKEN_TTL = 60;

	/**
	 * Minimum interval (seconds) between two forceRecalc-driven snapshot rebuilds
	 * for the same user in {@see self::updateUserActiveCallsCacheThrottled()}.
	 * Bounds the roster-wide rebuild cost a single caller can trigger.
	 */
	private const FORCE_RECALC_COOLDOWN_TTL = 10;

	private static ?ActiveCallsCache $callsCache = null;

	/**
	 * Set of chat IDs currently inside {@see self::terminateAllCallsInChat()} (chatId => true).
	 * Each finish() re-enters EventHandler::onCallFinished synchronously; that handler checks
	 * this via {@see self::isTearingDownChat()} for its OWN chat and skips scheduling another
	 * teardown, so a chat with K sibling calls does not fan out into ~K-1 redundant teardowns
	 * (O(K^2) snapshot queries). Keyed per chat rather than a single global flag: finishing a
	 * call of a DIFFERENT chat while a teardown is in progress must still tear down that chat's
	 * own siblings instead of being suppressed and silently skipped.
	 *
	 * @var array<int, true>
	 */
	private static array $chatsInTeardown = [];

	public static function isTearingDownChat(int $chatId): bool
	{
		return isset(self::$chatsInTeardown[$chatId]);
	}

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

	/**
	 * Cache-first active calls for an explicit user id, without touching global
	 * $USER. Lets callers (e.g. the recent read-endpoint) resolve the snapshot
	 * for the already-authenticated user and stay testable.
	 *
	 * @param int $userId User ID
	 * @return array [callId => callData]
	 */
	public static function getActiveCallsForUser(int $userId): array
	{
		if (!Settings::isNewCallsEnabled())
		{
			return [];
		}

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
	 * forceRecalc entry point behind {@see Controller\Recent::getActiveAction()},
	 * throttled per user.
	 *
	 * A forced rebuild ({@see self::updateUserActiveCallsCache()}) is expensive: it
	 * primes the whole participant roster through {@see Util::getUsers()} and
	 * rewrites the file cache. Left ungated, any authenticated caller could hammer
	 * `forceRecalc=Y` and drive unbounded parallel rebuilds — a cheap DoS vector.
	 *
	 * This coalesces bursts to at most one rebuild per
	 * {@see self::FORCE_RECALC_COOLDOWN_TTL} seconds per user: an atomic KV claim
	 * ({@see KeyValueEngine::setNotExists()}) admits exactly one rebuild while the
	 * stamp is held; other callers get the current cache-first snapshot (the same
	 * payload a plain read returns), so a warm portal answers from cache and a cold
	 * one still rebuilds lazily on the cache miss.
	 *
	 * Falls back to an unconditional rebuild on file-cache deployments (no shared
	 * KV): correctness over storm-cost, mirroring {@see self::scheduleUpdateCallCache()}.
	 *
	 * @param int $userId User ID
	 * @return array [callId => callData]
	 */
	public static function updateUserActiveCallsCacheThrottled(int $userId): array
	{
		$engine = self::getKvCacheEngine();
		if ($engine === null)
		{
			return self::updateUserActiveCallsCache($userId);
		}

		// Atomic claim: setNotExists() admits exactly one caller per cooldown window,
		// so concurrent forceRecalc bursts collapse to a single rebuild. A separate
		// get()/set() pair would race -- two requests could both read an empty stamp
		// and both trigger the expensive rebuild before either wrote it back.
		$cooldownKey = self::forceRecalcCooldownKey($userId);
		if (!$engine->setNotExists($cooldownKey, self::FORCE_RECALC_COOLDOWN_TTL, 1))
		{
			// Cooldown already claimed: skip the rebuild and serve the current
			// snapshot, exactly as a non-forced read would.
			return self::getActiveCallsForUser($userId);
		}

		return self::updateUserActiveCallsCache($userId);
	}

	/**
	 * Drops the forceRecalc cooldown stamp for a user so the next forced read
	 * rebuilds immediately. No-op on file-cache deployments (no shared KV).
	 * Kept for tests, which must reset this non-transactional state between runs.
	 */
	public static function clearForceRecalcCooldown(int $userId): void
	{
		self::getKvCacheEngine()?->del(self::forceRecalcCooldownKey($userId));
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

	private static function cacheKeyPrefix(): string
	{
		$cacheConfig = Configuration::getValue('cache');
		$sid = (is_array($cacheConfig) && !empty($cacheConfig['sid'])) ? $cacheConfig['sid'] : 'BX';

		return $sid . '|call:';
	}

	private static function scheduleTokenKey(int $callId): string
	{
		return self::cacheKeyPrefix() . 'sched_token:' . $callId;
	}

	private static function scheduleTeardownTokenKey(int $chatId): string
	{
		return self::cacheKeyPrefix() . 'sched_teardown_token:' . $chatId;
	}

	private static function forceRecalcCooldownKey(int $userId): string
	{
		return self::cacheKeyPrefix() . 'recent_force_recalc:' . $userId;
	}

	/**
	 * Terminates all active calls in a chat except the specified call.
	 *
	 * @param int $chatId Chat ID
	 * @param int|null $excludeCallId Call ID to exclude from termination
	 * @return void
	 */
	public static function terminateAllCallsInChat(int $chatId, ?int $excludeCallId = null): void
	{
		if (!$chatId || !Loader::includeModule('im'))
		{
			return;
		}

		$filter = self::buildActiveCallsInChatFilter($chatId, $excludeCallId);
		if ($filter === null)
		{
			return;
		}

		$callList = CallTable::getList([
			'select' => array_keys(CallTable::getEntity()->getScalarFields()),
			'filter' => $filter,
			'order' => ['ID' => 'DESC']
		]);

		// Suppress recursive teardown of THIS chat: each finish() below re-enters
		// EventHandler::onCallFinished synchronously, which would otherwise call
		// terminateAllCallsInChat again for the remaining siblings (O(K^2)). The handler skips
		// while this chat's flag is raised. Keyed per chat so finishing a call of another chat
		// during this loop is not suppressed; restore prior membership so a re-entrant teardown
		// of the same chat does not clear the guard early.
		$wasTearingDown = isset(self::$chatsInTeardown[$chatId]);
		self::$chatsInTeardown[$chatId] = true;
		try
		{
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
		finally
		{
			if (!$wasTearingDown)
			{
				unset(self::$chatsInTeardown[$chatId]);
			}
		}
	}

	/**
	 * Deferred, storm-coalesced counterpart of {@see self::terminateAllCallsInChat()} for
	 * the repeat-finish recovery path. A repeat finish of an already-FINISHED call re-runs
	 * the sibling teardown as a safety net (the original finish may have committed the CAS
	 * but died before its inline teardown in onCallFinished). Under a finish-storm every
	 * such request would otherwise run the same IM-chat load + active-calls SELECT
	 * synchronously; the process-local {@see self::isTearingDownChat()} guard cannot dedupe
	 * across HTTP requests.
	 *
	 * Mirrors {@see self::scheduleUpdateCallCache()}: each request stamps a monotonic token
	 * in shared KV and schedules a job carrying it; the job runs the teardown only if no
	 * strictly fresher token appeared, so the storm collapses to a single off-clock
	 * teardown. Only this recovery re-run is coalesced — the primary onCallFinished
	 * teardown stays inline.
	 *
	 * Falls back to an unconditional background job on file-cache deployments (no shared
	 * KV): correctness over storm-cost.
	 */
	public static function scheduleTerminateAllCallsInChat(int $chatId, ?int $excludeCallId = null): void
	{
		if ($chatId <= 0)
		{
			return;
		}

		$engine = self::getKvCacheEngine();
		if ($engine === null)
		{
			\Bitrix\Main\Application::getInstance()->addBackgroundJob(
				[self::class, 'terminateAllCallsInChat'],
				[$chatId, $excludeCallId],
				\Bitrix\Main\Application::JOB_PRIORITY_LOW,
			);

			return;
		}

		$token = (int)round(microtime(true) * 1000);
		$engine->set(self::scheduleTeardownTokenKey($chatId), self::SCHEDULE_TOKEN_TTL, $token);

		\Bitrix\Main\Application::getInstance()->addBackgroundJob(
			[self::class, 'runScheduledTerminateAllCallsInChat'],
			[$chatId, $excludeCallId, $token],
			\Bitrix\Main\Application::JOB_PRIORITY_LOW,
		);
	}

	/**
	 * Background-job entry point for {@see self::scheduleTerminateAllCallsInChat()}.
	 * Aborts when the KV stamp shows a strictly later scheduling — that request's job
	 * owns the teardown. Ties (sub-millisecond bursts) let extra runs through; the
	 * teardown is idempotent (siblings already FINISHED are skipped).
	 */
	public static function runScheduledTerminateAllCallsInChat(int $chatId, ?int $excludeCallId, int $token): void
	{
		$engine = self::getKvCacheEngine();
		if ($engine !== null)
		{
			$latest = (int)$engine->get(self::scheduleTeardownTokenKey($chatId));
			if ($latest > $token)
			{
				return;
			}
		}

		self::terminateAllCallsInChat($chatId, $excludeCallId);
	}

	/**
	 * Builds the ORM filter for active calls in a chat (provider/entity by chat type,
	 * non-finished, within the depth window), optionally excluding one call. Returns
	 * null when the chat does not exist.
	 */
	private static function buildActiveCallsInChatFilter(int $chatId, ?int $excludeCallId): ?array
	{
		$chat = \Bitrix\Im\V2\Chat::getInstance($chatId);
		if ($chat instanceof \Bitrix\Im\V2\Chat\NullChat)
		{
			return null;
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

		return $filter;
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
