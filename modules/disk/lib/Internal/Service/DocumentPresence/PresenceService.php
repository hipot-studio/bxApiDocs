<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\DocumentPresence;

use Bitrix\Main\Application;
use Bitrix\Main\Data\Storage\PersistentStorageInterface;
use Bitrix\Main\DB\Connection;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;

class PresenceService
{
	public const ACTION_ENTER = 'enter';
	public const ACTION_HEARTBEAT = 'heartbeat';
	public const ACTION_LEAVE = 'leave';

	public const ERROR_CONTEXT_INVALID = 'PRESENCE_CONTEXT_INVALID';
	public const ERROR_ACCESS_DENIED = 'ACCESS_DENIED';
	public const ERROR_LOCKED = 'PRESENCE_LOCKED';

	// Two heartbeat intervals plus a margin: one lost beat must not drop a participant out of the roster,
	// because a dropped registration also stops receiving roster changes.
	private const REGISTRATION_TTL = 660;
	// A cached verdict stays within one heartbeat interval and no longer follows REGISTRATION_TTL:
	// a participant whose access was revoked would otherwise linger in the rosters of others for eleven
	// minutes. It is rechecked once per beat instead.
	private const AUTHORIZATION_TTL = 300;
	// Below the heartbeat interval, so every rare beat refreshes the presence mark of its caller.
	private const STATE_REFRESH_INTERVAL = 150;
	/**
	 * A third of the beat an opening keeps, in seconds - the share the throttle of the previous scheme held
	 * of the previous beat, so a beat arriving early is still let through. What it refuses is a stream of
	 * requests from one tab, and it refuses them on a read of the state alone: before the lock of the
	 * scope, before anything is written and before any roster is published.
	 */
	private const HEARTBEAT_THROTTLE = PresenceAccessService::HEARTBEAT_INTERVAL / 1000 / 3;
	private const MAX_REGISTRATIONS_PER_SESSION = 8;
	private const MAX_REGISTRATIONS_PER_USER = 16;
	/** The ceiling of the roster as a whole: nothing that feeds it may offer more participants than this. */
	public const MAX_REGISTRATIONS_PER_SCOPE = 100;
	private const LOCK_TIMEOUT = 5;
	private const REVISION_TIME_MULTIPLIER = 1000000;

	private PersistentStorageInterface $storage;
	private Connection $connection;
	private PresenceAccessService $presenceAccessService;
	private \Closure $clock;
	private \Closure $revisionClock;

	public function __construct(
		?PersistentStorageInterface $storage = null,
		?Connection $connection = null,
		?PresenceAccessService $presenceAccessService = null,
		?callable $clock = null,
		?callable $revisionClock = null,
	)
	{
		$this->storage = $storage ?? ServiceLocator::getInstance()->get(PersistentStorageInterface::class);
		$this->connection = $connection ?? Application::getConnection();
		$this->presenceAccessService = $presenceAccessService ?? new PresenceAccessService();
		$this->clock = \Closure::fromCallable($clock ?? static fn(): int => time());
		$this->revisionClock = \Closure::fromCallable(
			$revisionClock ?? static fn(): int => (int)(microtime(true) * self::REVISION_TIME_MULTIPLIER),
		);
	}

	/**
	 * @param bool $isConfirming Whether the calling opening maintains the contour of confirmations. Only the
	 *                           participants that do are ever hidden by silence, so an opening which cannot
	 *                           confirm itself - an old tab, one without a channel of confirmations - says so
	 *                           and is shown until the server itself takes it out of the roster.
	 */
	public function handle(
		string $action,
		string $presenceContext,
		int $currentUserId,
		int $clientRevision = 0,
		bool $isConfirming = false,
	): Result
	{
		if (!in_array($action, [self::ACTION_ENTER, self::ACTION_HEARTBEAT, self::ACTION_LEAVE], true))
		{
			return $this->getErrorResult(self::ERROR_CONTEXT_INVALID, 'Presence action is invalid.');
		}

		$binding = $this->presenceAccessService->verifyPresenceContext($presenceContext);
		if ($binding === null)
		{
			return $this->getErrorResult(self::ERROR_CONTEXT_INVALID, 'Presence context is invalid.');
		}

		$isRelease = $action === self::ACTION_LEAVE;
		$scope = $isRelease
			? $this->presenceAccessService->resolveScopeForReleaseFromBinding($binding, $currentUserId)
			: $this->presenceAccessService->resolveAuthorizedScopeFromBinding($binding, $currentUserId)
		;
		if ($scope === null)
		{
			return $this->getErrorResult(self::ERROR_ACCESS_DENIED, 'Presence access is denied.');
		}

		if (!$isRelease && $this->isThrottledBeat($scope, $binding, $isConfirming, ($this->clock)()))
		{
			return new Result();
		}

		/** @var array<string, bool> $liveAuthorizations Verdicts actually rechecked during this request. */
		$liveAuthorizations = [];
		if (!$isRelease)
		{
			// resolveAuthorizedScopeFromBinding() above is the caller's own live check; a release is not.
			$liveAuthorizations[$this->getAuthorizationKey($binding['userId'], $binding['sessionId'])] = true;
		}

		$lockName = $scope['storageKey'];
		try
		{
			$locked = $this->connection->lock($lockName, self::LOCK_TIMEOUT);
		}
		catch (\Throwable)
		{
			$locked = false;
		}

		if (!$locked)
		{
			return $this->getErrorResult(self::ERROR_LOCKED, 'Presence is temporarily locked.');
		}

		try
		{
			$now = ($this->clock)();
			$state = $this->normalizeState($this->storage->get($scope['storageKey'], []));

			$authorizationKey = $this->getAuthorizationKey($binding['userId'], $binding['sessionId']);
			$isCallerStateFresh = $this->isCallerStateFresh($state, $binding, $authorizationKey, $now)
				&& $isConfirming === $this->isRegisteredAsConfirming($state['registrations'], $binding)
			;
			if (!$isCallerStateFresh && !$isRelease)
			{
				// The caller is already reauthorized by resolveAuthorizedScopeFromBinding() above. A release
				// carries no such verdict, so its registrations stay subject to the live recheck below and
				// leave the roster even when the session is no longer active.
				$state['authorizations'][$authorizationKey] = $now;
			}

			$initialRegistrations = $state['registrations'];
			$state['registrations'] = $this->pruneRegistrations(
				$state['registrations'],
				$state['authorizations'],
				$liveAuthorizations,
				$scope,
				$now,
			);
			$shouldPublishRoster = $initialRegistrations !== $state['registrations'];

			if ($isRelease)
			{
				$this->removeRegistration($state['registrations'], $binding);
				$shouldPublishRoster = $shouldPublishRoster
					|| $initialRegistrations !== $state['registrations'];
			}
			else
			{
				$previousRegistration = $this->normalizeRegistration($state['registrations'][$binding['tabId']] ?? null);
				$hasChangedConfirming = $previousRegistration !== null
					&& $previousRegistration['isConfirming'] !== $isConfirming
				;
				$previousConfirmingChangedAt = $previousRegistration['confirmingChangedAt'] ?? 0;
				$state['registrations'][$binding['tabId']] = [
					'userId' => $binding['userId'],
					'sessionId' => $binding['sessionId'],
					'tabId' => $binding['tabId'],
					'lastSeen' => $now,
					'isConfirming' => $isConfirming,
					// A registration that has never changed its contour keeps 0, so the one correction an
					// opening makes right after its entry is never refused by the throttle.
					'confirmingChangedAt' => $hasChangedConfirming ? $now : $previousConfirmingChangedAt,
				];
				$shouldPublishRoster = $shouldPublishRoster
					|| $previousRegistration === null
					|| $previousRegistration['userId'] !== $binding['userId']
					|| $previousRegistration['sessionId'] !== $binding['sessionId']
					// the roster carries the flag, so a change of it is a change of the roster
					|| $previousRegistration['isConfirming'] !== $isConfirming;
				$shouldPublishRoster = $this->enforceRegistrationLimits($state['registrations'], $binding)
					|| $shouldPublishRoster;
			}

			$state['authorizations'] = $this->collectActiveAuthorizations(
				$state['registrations'],
				$state['authorizations'],
			);

			if ($shouldPublishRoster)
			{
				// The state record expires after REGISTRATION_TTL, while a browser can retain the last roster.
				// A physical-time floor prevents a newly created state from restarting the revision at zero.
				$state['revision'] = max($state['revision'] + 1, ($this->revisionClock)());
			}

			// A heartbeat that neither changes the roster nor lets the caller's own entries age past
			// STATE_REFRESH_INTERVAL needs no state rewrite, so a client still beating at the former
			// frequent interval does not turn into one storage write per beat.
			if ($shouldPublishRoster || !$isCallerStateFresh)
			{
				// The record has to outlive every registration it holds, or a roster would vanish between
				// two rare beats instead of ageing out registration by registration.
				$stored = $this->storage->set(
					$scope['storageKey'],
					$state,
					new \DateInterval('PT' . self::REGISTRATION_TTL . 'S'),
				);
				if (!$stored)
				{
					return $this->getErrorResult(self::ERROR_LOCKED, 'Presence state is temporarily unavailable.');
				}
			}

			$rosterRegistrations = $state['registrations'];
			$revision = $state['revision'];
		}
		catch (\Throwable)
		{
			return $this->getErrorResult(self::ERROR_LOCKED, 'Presence state is temporarily unavailable.');
		}
		finally
		{
			try
			{
				$this->connection->unlock($lockName);
			}
			catch (\Throwable)
			{
			}
		}

		$result = new Result();
		// The response also carries the stored snapshot back to a client that fell behind: a failed
		// Pull publication or an event missed across a reconnect would otherwise keep the browser on a
		// stale roster until the next real join or leave.
		if ($shouldPublishRoster || max(0, $clientRevision) < $revision)
		{
			// Participant profiles, the recipient recheck and delivery stay outside the scope lock.
			[$participants, $recipientIds] = $this->buildParticipants(
				$this->filterAuthorizedRecipients($rosterRegistrations, $liveAuthorizations, $scope),
			);
			$snapshot = [
				'scope' => $scope['scope'],
				'revision' => $revision,
				'participants' => $participants,
			];
			if ($shouldPublishRoster)
			{
				$this->publishRoster($recipientIds, $snapshot);
			}
			// The release path does not recheck the caller's current rights, so the roster is published to the
			// authorized recipients but never returned to the caller itself.
			if (!$isRelease)
			{
				$result->setData($snapshot);
			}
		}

		return $result;
	}

	/**
	 * Roster of a scope for a reader that holds no registration of its own: expired entries are dropped,
	 * but nothing is stored and no authorization verdict is reused - the caller checks the rights of every
	 * participant itself. The revision comes from the same read, so it never runs ahead of the roster it
	 * is returned with.
	 *
	 * @param array{storageKey: string} $scope
	 * @return array{revision: int, userIds: list<int>, confirmingUserIds: list<int>}
	 */
	public function snapshot(array $scope): array
	{
		try
		{
			$state = $this->normalizeState($this->storage->get($scope['storageKey'], []));
		}
		catch (\Throwable)
		{
			return ['revision' => 0, 'userIds' => [], 'confirmingUserIds' => []];
		}

		$now = ($this->clock)();
		$userIds = [];
		$liveRegistrations = [];
		foreach ($state['registrations'] as $tabId => $storedRegistration)
		{
			$registration = $this->normalizeRegistration($storedRegistration);
			if (
				$registration === null
				|| $registration['tabId'] !== $tabId
				|| $now >= $registration['lastSeen'] + self::REGISTRATION_TTL
			)
			{
				continue;
			}

			$userIds[$registration['userId']] = $registration['userId'];
			$liveRegistrations[$tabId] = $registration;
		}

		return [
			'revision' => $state['revision'],
			'userIds' => array_values($userIds),
			'confirmingUserIds' => array_keys($this->collectConfirmingUserIds($liveRegistrations)),
		];
	}

	/**
	 * EVENT-01. Anonymous participants hold no roster registration and are never notified: they only
	 * arrive with the initial roster.
	 *
	 * @param list<int> $recipientIds
	 * @param array{scope: string, revision: int, participants: list<array{id: int, name: string, avatar: string, isConfirming: bool}>} $snapshot
	 */
	protected function publishRoster(array $recipientIds, array $snapshot): void
	{
		$recipientIds = array_values(array_unique(array_filter(
			$recipientIds,
			static fn(int $userId): bool => $userId > 0,
		)));
		if ($recipientIds === [])
		{
			return;
		}

		$this->sendPullEvent($recipientIds, [
			'module_id' => 'disk',
			'command' => PresenceAccessService::PULL_COMMAND,
			'params' => $snapshot,
		]);
	}

	/**
	 * @param list<int> $recipientIds
	 * @param array{module_id: string, command: string, params: array<string, mixed>} $event
	 */
	protected function sendPullEvent(array $recipientIds, array $event): void
	{
		if (!Loader::includeModule('pull'))
		{
			return;
		}

		try
		{
			\Bitrix\Pull\Event::add($recipientIds, $event, \CPullChannel::TYPE_PRIVATE);
		}
		catch (\Throwable)
		{
		}
	}

	/**
	 * @param mixed $state
	 * @return array{revision: int, registrations: array<string, array{userId: int, sessionId: int, tabId: string, lastSeen: int}>, authorizations: array<string, int>}
	 */
	private function normalizeState(mixed $state): array
	{
		if (!is_array($state))
		{
			return ['revision' => 0, 'registrations' => [], 'authorizations' => []];
		}

		$revision = $state['revision'] ?? 0;
		$registrations = $state['registrations'] ?? [];
		$authorizations = $state['authorizations'] ?? [];

		return [
			'revision' => is_int($revision) && $revision >= 0 ? $revision : 0,
			'registrations' => is_array($registrations) ? $registrations : [],
			'authorizations' => is_array($authorizations) ? $authorizations : [],
		];
	}

	/**
	 * Both of the caller's own entries are younger than STATE_REFRESH_INTERVAL, so nothing has to be
	 * persisted for them. The interval sits below the heartbeat one, so a beat of the current client always
	 * rewrites; what this absorbs is the extra beats of a client still running the former frequent interval.
	 *
	 * @param array{revision: int, registrations: array<string, mixed>, authorizations: array<string, mixed>} $state
	 * @param array{sessionId: int, userId: int, tabId: string, accessType: string} $binding
	 */
	private function isCallerStateFresh(array $state, array $binding, string $authorizationKey, int $now): bool
	{
		$registration = $this->normalizeRegistration($state['registrations'][$binding['tabId']] ?? null);
		if (
			$registration === null
			|| $registration['userId'] !== $binding['userId']
			|| $registration['sessionId'] !== $binding['sessionId']
			|| $registration['lastSeen'] > $now
			|| $now >= $registration['lastSeen'] + self::STATE_REFRESH_INTERVAL
		)
		{
			return false;
		}

		$authorizedAt = $state['authorizations'][$authorizationKey] ?? null;

		return (
			is_int($authorizedAt)
			&& $authorizedAt <= $now
			&& $now < $authorizedAt + self::STATE_REFRESH_INTERVAL
		);
	}

	/**
	 * A repeat of a request whose answer the stored state already holds. It is refused on a read alone: the
	 * lock of the scope is never taken, nothing is stored and no roster reaches the recipients, so a tab
	 * beating far ahead of its interval costs one read of the storage and nothing else.
	 *
	 * A change of the contour is let through, because the opening corrects the claim of its entry within one
	 * frequent beat - long before the throttle opens. It is let through once per throttle though: flipping
	 * the flag back and forth would otherwise be a free way of making the server publish the roster to every
	 * recipient of the scope.
	 *
	 * @param array{storageKey: string} $scope
	 * @param array{sessionId: int, userId: int, tabId: string, accessType: string} $binding
	 */
	private function isThrottledBeat(array $scope, array $binding, bool $isConfirming, int $now): bool
	{
		try
		{
			$state = $this->normalizeState($this->storage->get($scope['storageKey'], []));
		}
		catch (\Throwable)
		{
			return false;
		}

		$registration = $this->normalizeRegistration($state['registrations'][$binding['tabId']] ?? null);
		if (
			$registration === null
			|| $registration['userId'] !== $binding['userId']
			|| $registration['sessionId'] !== $binding['sessionId']
			|| $registration['lastSeen'] > $now
			|| $now >= $registration['lastSeen'] + self::HEARTBEAT_THROTTLE
		)
		{
			return false;
		}

		if ($isConfirming === $registration['isConfirming'])
		{
			return true;
		}

		return $registration['confirmingChangedAt'] > 0
			&& $now < $registration['confirmingChangedAt'] + self::HEARTBEAT_THROTTLE
		;
	}

	/**
	 * @param array<string, mixed> $registrations
	 * @param array{sessionId: int, userId: int, tabId: string, accessType: string} $binding
	 */
	private function isRegisteredAsConfirming(array $registrations, array $binding): bool
	{
		$registration = $this->normalizeRegistration($registrations[$binding['tabId']] ?? null);

		return $registration !== null && $registration['isConfirming'];
	}

	/**
	 * @param array<string, mixed> $registrations
	 * @param array<string, mixed> $authorizations Cached moments of the last successful authorization per user/session.
	 * @param array<string, bool> $liveAuthorizations Collects the verdicts rechecked here for the snapshot below.
	 * @param array{accessType: string, storageKey: string, scope: string, realObjectId: int, attachedObjectId: int} $scope
	 * @return array<string, array{userId: int, sessionId: int, tabId: string, lastSeen: int}>
	 */
	private function pruneRegistrations(
		array $registrations,
		array &$authorizations,
		array &$liveAuthorizations,
		array $scope,
		int $now,
	): array
	{
		$liveRegistrations = [];
		$expiredRegistrations = [];
		foreach ($registrations as $tabId => $registration)
		{
			$normalizedRegistration = $this->normalizeRegistration($registration);
			if (
				$normalizedRegistration === null
				|| $normalizedRegistration['tabId'] !== $tabId
				|| $now >= $normalizedRegistration['lastSeen'] + self::REGISTRATION_TTL
			)
			{
				continue;
			}

			$liveRegistrations[$tabId] = $normalizedRegistration;
			if (!$this->isAuthorizationFresh($authorizations, $normalizedRegistration, $now))
			{
				$expiredRegistrations[$tabId] = $normalizedRegistration;
			}
		}

		$this->recheckAuthorizations($expiredRegistrations, $authorizations, $liveAuthorizations, $scope, $now);

		return array_filter(
			$liveRegistrations,
			fn(array $registration): bool => $this->isAuthorizationFresh($authorizations, $registration, $now),
		);
	}

	/**
	 * Rechecking every registration on every heartbeat costs O(N^2) session loads and ACL checks per scope,
	 * so a positive verdict is reused until AUTHORIZATION_TTL expires.
	 *
	 * @param array<string, mixed> $authorizations
	 * @param array{userId: int, sessionId: int} $registration
	 */
	private function isAuthorizationFresh(array $authorizations, array $registration, int $now): bool
	{
		$authorizationKey = $this->getAuthorizationKey($registration['userId'], $registration['sessionId']);
		$authorizedAt = $authorizations[$authorizationKey] ?? null;

		return is_int($authorizedAt) && $authorizedAt <= $now && $now < $authorizedAt + self::AUTHORIZATION_TTL;
	}

	/**
	 * AUTHORIZATION_TTL is as long as a heartbeat interval, so the verdict of a neighbour is regularly
	 * expired by the time anybody else beats. That recheck happens inside the scope lock, where one live
	 * check per registration would hold every parallel beat of the same document on the lock, so all of
	 * them go through a single batch call instead.
	 *
	 * @param array<string, array{userId: int, sessionId: int, tabId: string, lastSeen: int}> $expiredRegistrations
	 * @param array<string, mixed> $authorizations
	 * @param array<string, bool> $liveAuthorizations
	 * @param array{accessType: string, storageKey: string, scope: string, realObjectId: int, attachedObjectId: int} $scope
	 */
	private function recheckAuthorizations(
		array $expiredRegistrations,
		array &$authorizations,
		array &$liveAuthorizations,
		array $scope,
		int $now,
	): void
	{
		if ($expiredRegistrations === [])
		{
			return;
		}

		$authorizedRegistrations = $this->presenceAccessService->filterAuthorizedRegistrations(
			$scope,
			$expiredRegistrations,
		);
		foreach ($expiredRegistrations as $tabId => $registration)
		{
			$authorizationKey = $this->getAuthorizationKey($registration['userId'], $registration['sessionId']);
			$authorized = array_key_exists($tabId, $authorizedRegistrations);
			$liveAuthorizations[$authorizationKey] = $authorized;
			if ($authorized)
			{
				$authorizations[$authorizationKey] = $now;
			}
			else
			{
				unset($authorizations[$authorizationKey]);
			}
		}
	}

	/**
	 * @param array<string, array{userId: int, sessionId: int, tabId: string, lastSeen: int}> $registrations
	 * @param array<string, mixed> $authorizations
	 * @return array<string, int>
	 */
	private function collectActiveAuthorizations(array $registrations, array $authorizations): array
	{
		$activeAuthorizations = [];
		foreach ($registrations as $registration)
		{
			$authorizationKey = $this->getAuthorizationKey($registration['userId'], $registration['sessionId']);
			$authorizedAt = $authorizations[$authorizationKey] ?? null;
			if (is_int($authorizedAt))
			{
				$activeAuthorizations[$authorizationKey] = $authorizedAt;
			}
		}

		return $activeAuthorizations;
	}

	/**
	 * Every editor render issues a new tabId, so the state has to stay bounded per session, user and scope.
	 *
	 * @param array<string, array{userId: int, sessionId: int, tabId: string, lastSeen: int}> $registrations
	 * @param array{sessionId: int, userId: int, tabId: string, accessType: string} $binding
	 * @return bool Whether any registration was evicted.
	 */
	private function enforceRegistrationLimits(array &$registrations, array $binding): bool
	{
		$evicted = $this->evictOldestRegistrations(
			$registrations,
			self::MAX_REGISTRATIONS_PER_SESSION,
			$binding['tabId'],
			static fn(array $registration): bool => (
				$registration['userId'] === $binding['userId']
				&& $registration['sessionId'] === $binding['sessionId']
			),
		);
		$evicted = $this->evictOldestRegistrations(
			$registrations,
			self::MAX_REGISTRATIONS_PER_USER,
			$binding['tabId'],
			static fn(array $registration): bool => $registration['userId'] === $binding['userId'],
		) || $evicted;

		return $this->evictOldestRegistrations(
			$registrations,
			self::MAX_REGISTRATIONS_PER_SCOPE,
			$binding['tabId'],
			static fn(): bool => true,
		) || $evicted;
	}

	/**
	 * @param array<string, array{userId: int, sessionId: int, tabId: string, lastSeen: int}> $registrations
	 */
	private function evictOldestRegistrations(
		array &$registrations,
		int $limit,
		string $protectedTabId,
		\Closure $isLimited,
	): bool
	{
		$limitedRegistrations = array_filter($registrations, $isLimited);
		$excess = count($limitedRegistrations) - $limit;
		if ($excess <= 0)
		{
			return false;
		}

		unset($limitedRegistrations[$protectedTabId]);
		uasort(
			$limitedRegistrations,
			static fn(array $first, array $second): int => $first['lastSeen'] <=> $second['lastSeen'],
		);
		foreach (array_slice(array_keys($limitedRegistrations), 0, $excess) as $tabId)
		{
			unset($registrations[$tabId]);
		}

		return true;
	}

	private function getAuthorizationKey(int $userId, int $sessionId): string
	{
		return $userId . ':' . $sessionId;
	}

	/**
	 * @param array<string, array{userId: int, sessionId: int, tabId: string, lastSeen: int}> $registrations
	 * @param array{sessionId: int, userId: int, tabId: string, accessType: string} $binding
	 */
	private function removeRegistration(array &$registrations, array $binding): void
	{
		$registration = $registrations[$binding['tabId']] ?? null;
		if (
			is_array($registration)
			&& ($registration['userId'] ?? null) === $binding['userId']
			&& ($registration['sessionId'] ?? null) === $binding['sessionId']
		)
		{
			unset($registrations[$binding['tabId']]);
		}
	}

	/**
	 * A cached verdict keeps a participant in the roster for up to AUTHORIZATION_TTL, so every recipient whose
	 * verdict was not rechecked live during this request is rechecked here, right before names, avatars and
	 * roster changes are sent to it. The recheck goes through a single batch call, so a roster of N participants
	 * does not cost N session loads. The stored state is left alone: the scope lock is already released, and
	 * the next pruneRegistrations() drops these registrations anyway.
	 *
	 * @param array<string, array{userId: int, sessionId: int, tabId: string, lastSeen: int}> $registrations
	 * @param array<string, bool> $liveAuthorizations
	 * @param array{accessType: string, storageKey: string, scope: string, realObjectId: int, attachedObjectId: int} $scope
	 * @return array<string, array{userId: int, sessionId: int, tabId: string, lastSeen: int}>
	 */
	private function filterAuthorizedRecipients(array $registrations, array $liveAuthorizations, array $scope): array
	{
		$pendingRegistrations = [];
		foreach ($registrations as $tabId => $registration)
		{
			$authorizationKey = $this->getAuthorizationKey($registration['userId'], $registration['sessionId']);
			if (!array_key_exists($authorizationKey, $liveAuthorizations))
			{
				$pendingRegistrations[$tabId] = $registration;
			}
		}

		$recheckedRegistrations = $pendingRegistrations === []
			? []
			: $this->presenceAccessService->filterAuthorizedRegistrations($scope, $pendingRegistrations)
		;

		$authorizedRegistrations = [];
		foreach ($registrations as $tabId => $registration)
		{
			$authorizationKey = $this->getAuthorizationKey($registration['userId'], $registration['sessionId']);
			$authorized = array_key_exists($authorizationKey, $liveAuthorizations)
				? $liveAuthorizations[$authorizationKey]
				: array_key_exists($tabId, $recheckedRegistrations)
			;
			if ($authorized)
			{
				$authorizedRegistrations[$tabId] = $registration;
			}
		}

		return $authorizedRegistrations;
	}

	/**
	 * @param array<string, array{userId: int, sessionId: int, tabId: string, lastSeen: int, isConfirming: bool}> $registrations
	 * @return array{0: list<array{id: int, name: string, avatar: string, isConfirming: bool}>, 1: list<int>}
	 */
	private function buildParticipants(array $registrations): array
	{
		$this->presenceAccessService->preloadParticipants(
			array_values(array_unique(array_column($registrations, 'userId'))),
		);

		$confirmingUserIds = $this->collectConfirmingUserIds($registrations);
		$participants = [];
		$recipientIds = [];
		foreach ($registrations as $registration)
		{
			$userId = $registration['userId'];
			if (isset($participants[$userId]))
			{
				continue;
			}

			$participant = $this->presenceAccessService->resolveParticipant($userId);
			if ($participant === null)
			{
				continue;
			}

			$participants[$userId] = $participant + ['isConfirming' => isset($confirmingUserIds[$userId])];
			$recipientIds[] = $userId;
		}

		return [array_values($participants), $recipientIds];
	}

	/**
	 * A user is led by the weakest of their openings: one tab of theirs that cannot confirm itself keeps
	 * them out of the contour entirely, because that tab would go silent while the user is still here.
	 *
	 * @param array<string, array{userId: int, sessionId: int, tabId: string, lastSeen: int, isConfirming: bool}> $registrations
	 * @return array<int, true>
	 */
	private function collectConfirmingUserIds(array $registrations): array
	{
		$confirmingUserIds = [];
		foreach ($registrations as $registration)
		{
			$userId = $registration['userId'];
			$confirmingUserIds[$userId] = ($confirmingUserIds[$userId] ?? true) && $registration['isConfirming'];
		}

		return array_filter($confirmingUserIds);
	}

	/**
	 * A record stored before the contour of confirmations existed carries no flag, and neither does the
	 * registration of a tab that cannot confirm itself: both mean the same and read as `false`.
	 *
	 * @param mixed $registration
	 * @return array{userId: int, sessionId: int, tabId: string, lastSeen: int, isConfirming: bool, confirmingChangedAt: int}|null
	 */
	private function normalizeRegistration(mixed $registration): ?array
	{
		if (!is_array($registration))
		{
			return null;
		}

		$userId = $registration['userId'] ?? null;
		$sessionId = $registration['sessionId'] ?? null;
		$tabId = $registration['tabId'] ?? null;
		$lastSeen = $registration['lastSeen'] ?? null;
		if (
			!is_int($userId)
			|| $userId <= 0
			|| !is_int($sessionId)
			|| $sessionId <= 0
			|| !is_string($tabId)
			|| !preg_match('/^[A-Za-z0-9]{16,128}$/D', $tabId)
			|| !is_int($lastSeen)
			|| $lastSeen <= 0
		)
		{
			return null;
		}

		$confirmingChangedAt = $registration['confirmingChangedAt'] ?? null;

		return [
			'userId' => $userId,
			'sessionId' => $sessionId,
			'tabId' => $tabId,
			'lastSeen' => $lastSeen,
			'isConfirming' => ($registration['isConfirming'] ?? null) === true,
			'confirmingChangedAt' => is_int($confirmingChangedAt) && $confirmingChangedAt > 0 ? $confirmingChangedAt : 0,
		];
	}

	private function getErrorResult(string $code, string $message): Result
	{
		$result = new Result();
		$result->addError(new Error($message, $code));

		return $result;
	}
}
