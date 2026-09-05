<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Vibeoffice\Service;

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

	private const REGISTRATION_TTL = 90;
	// A cached verdict never outlives the registration it authorizes, so the roster gains no staleness
	// beyond REGISTRATION_TTL. A shorter TTL would expire every verdict once per heartbeat interval.
	private const AUTHORIZATION_TTL = self::REGISTRATION_TTL;
	private const STATE_REFRESH_INTERVAL = 45;
	private const HEARTBEAT_THROTTLE = 10;
	private const MAX_REGISTRATIONS_PER_SESSION = 8;
	private const MAX_REGISTRATIONS_PER_USER = 16;
	private const MAX_REGISTRATIONS_PER_SCOPE = 100;
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

	public function handle(
		string $action,
		string $presenceContext,
		int $currentUserId,
		int $clientRevision = 0,
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
			if ($action === self::ACTION_HEARTBEAT && $this->isThrottledHeartbeat($state['registrations'], $binding, $now))
			{
				return new Result();
			}

			$authorizationKey = $this->getAuthorizationKey($binding['userId'], $binding['sessionId']);
			$isCallerStateFresh = $this->isCallerStateFresh($state, $binding, $authorizationKey, $now);
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
				$previousRegistration = $state['registrations'][$binding['tabId']] ?? null;
				$state['registrations'][$binding['tabId']] = [
					'userId' => $binding['userId'],
					'sessionId' => $binding['sessionId'],
					'tabId' => $binding['tabId'],
					'lastSeen' => $now,
				];
				$shouldPublishRoster = $shouldPublishRoster
					|| !is_array($previousRegistration)
					|| $previousRegistration['userId'] !== $binding['userId']
					|| $previousRegistration['sessionId'] !== $binding['sessionId'];
				$shouldPublishRoster = $this->enforceRegistrationLimits($state['registrations'], $binding)
					|| $shouldPublishRoster;
			}

			$state['authorizations'] = $this->collectActiveAuthorizations(
				$state['registrations'],
				$state['authorizations'],
			);

			if ($shouldPublishRoster)
			{
				// The state record expires after 90 seconds, while a browser can retain the last roster.
				// A physical-time floor prevents a newly created state from restarting the revision at zero.
				$state['revision'] = max($state['revision'] + 1, ($this->revisionClock)());
			}

			// A heartbeat that neither changes the roster nor lets the caller's own entries age past
			// STATE_REFRESH_INTERVAL needs no state rewrite, so the standard client interval does not
			// turn into one storage write per beat.
			if ($shouldPublishRoster || !$isCallerStateFresh)
			{
				$stored = $this->storage->set(
					$scope['storageKey'],
					$state,
					new \DateInterval('PT90S'),
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
	 * @param list<int> $recipientIds
	 * @param array{scope: string, revision: int, participants: list<array{id: int, name: string, avatar: string}>} $snapshot
	 */
	protected function publishRoster(array $recipientIds, array $snapshot): void
	{
		$recipientIds = array_values(array_unique(array_filter(
			$recipientIds,
			static fn(int $userId): bool => $userId > 0,
		)));
		if ($recipientIds === [] || !Loader::includeModule('pull'))
		{
			return;
		}

		try
		{
			\Bitrix\Pull\Event::add($recipientIds, [
				'module_id' => 'disk',
				'command' => 'vibeofficePresence',
				'params' => $snapshot,
			], \CPullChannel::TYPE_PRIVATE);
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
	 * A repeated heartbeat of the same tab is dropped before any state rewrite or authorization work.
	 *
	 * @param array<string, mixed> $registrations
	 * @param array{sessionId: int, userId: int, tabId: string, accessType: string} $binding
	 */
	private function isThrottledHeartbeat(array $registrations, array $binding, int $now): bool
	{
		$registration = $this->normalizeRegistration($registrations[$binding['tabId']] ?? null);

		return (
			$registration !== null
			&& $registration['userId'] === $binding['userId']
			&& $registration['sessionId'] === $binding['sessionId']
			&& $now < $registration['lastSeen'] + self::HEARTBEAT_THROTTLE
		);
	}

	/**
	 * Both of the caller's own entries stay valid well past the next heartbeat, so nothing has to be
	 * persisted for them: the registration outlives REGISTRATION_TTL and the verdict AUTHORIZATION_TTL
	 * until the following write.
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
		$activeRegistrations = [];
		$verdicts = [];
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

			$authorizationKey = $this->getAuthorizationKey(
				$normalizedRegistration['userId'],
				$normalizedRegistration['sessionId'],
			);
			if (!array_key_exists($authorizationKey, $verdicts))
			{
				$verdicts[$authorizationKey] = $this->authorizeRegistration(
					$authorizationKey,
					$authorizations,
					$liveAuthorizations,
					$scope,
					$normalizedRegistration,
					$now,
				);
			}
			if (!$verdicts[$authorizationKey])
			{
				continue;
			}

			$activeRegistrations[$tabId] = $normalizedRegistration;
		}

		return $activeRegistrations;
	}

	/**
	 * Rechecking every registration on every heartbeat costs O(N^2) session loads and ACL checks per scope,
	 * so a positive verdict is reused until AUTHORIZATION_TTL expires.
	 *
	 * @param array<string, mixed> $authorizations
	 * @param array<string, bool> $liveAuthorizations
	 * @param array{accessType: string, storageKey: string, scope: string, realObjectId: int, attachedObjectId: int} $scope
	 * @param array{userId: int, sessionId: int, tabId: string, lastSeen: int} $registration
	 */
	private function authorizeRegistration(
		string $authorizationKey,
		array &$authorizations,
		array &$liveAuthorizations,
		array $scope,
		array $registration,
		int $now,
	): bool
	{
		$authorizedAt = $authorizations[$authorizationKey] ?? null;
		if (is_int($authorizedAt) && $authorizedAt <= $now && $now < $authorizedAt + self::AUTHORIZATION_TTL)
		{
			return true;
		}

		$authorized = $this->presenceAccessService->isRegistrationAuthorized($scope, $registration);
		$liveAuthorizations[$authorizationKey] = $authorized;
		if ($authorized)
		{
			$authorizations[$authorizationKey] = $now;
		}
		else
		{
			unset($authorizations[$authorizationKey]);
		}

		return $authorized;
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
	 * @param array<string, array{userId: int, sessionId: int, tabId: string, lastSeen: int}> $registrations
	 * @return array{0: list<array{id: int, name: string, avatar: string}>, 1: list<int>}
	 */
	private function buildParticipants(array $registrations): array
	{
		$this->presenceAccessService->preloadParticipants(
			array_values(array_unique(array_column($registrations, 'userId'))),
		);

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

			$participants[$userId] = $participant;
			$recipientIds[] = $userId;
		}

		return [array_values($participants), $recipientIds];
	}

	/**
	 * @param mixed $registration
	 * @return array{userId: int, sessionId: int, tabId: string, lastSeen: int}|null
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

		return [
			'userId' => $userId,
			'sessionId' => $sessionId,
			'tabId' => $tabId,
			'lastSeen' => $lastSeen,
		];
	}

	private function getErrorResult(string $code, string $message): Result
	{
		$result = new Result();
		$result->addError(new Error($message, $code));

		return $result;
	}
}
