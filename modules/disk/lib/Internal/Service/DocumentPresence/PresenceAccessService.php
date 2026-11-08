<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\DocumentPresence;

use Bitrix\Disk\AttachedObject;
use Bitrix\Disk\Document\Models\DocumentSession;
use Bitrix\Disk\Document\Models\DocumentSessionContext;
use Bitrix\Disk\File;
use Bitrix\Disk\Internal\Service\UnifiedLink\UnifiedLinkAccessService;
use Bitrix\Disk\Realtime\Channels\DocumentPresenceChannel;
use Bitrix\Disk\Ui\Avatar;
use Bitrix\Disk\User;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Loader;
use Bitrix\Main\Security\Random;
use Bitrix\Main\Security\Sign\Signer;
use Bitrix\Main\Web\Json;

/**
 * Authorization of presence for an opening of a document, whichever editor engine opened it.
 *
 * The roster is keyed by the real object, its version and the access type, but never by the engine,
 * so two users who opened the same document with different engines share a single roster.
 */
class PresenceAccessService
{
	public const ACCESS_TYPE_INTERNAL = 'internal';
	public const ACCESS_TYPE_UNIFIED = 'unified';

	/** How often an opening shows itself to the server, in milliseconds. */
	public const HEARTBEAT_INTERVAL = 300000;
	/** How often an opening confirms itself to its neighbours in the channel of its presence scope. */
	public const CONFIRMATION_INTERVAL = 30000;
	/** How long an opening counts a neighbour alive after its last confirmation: three missed ones. */
	public const CONFIRMATION_TTL = 90000;

	public const PULL_COMMAND = 'documentPresence';
	public const CONFIRMATION_PULL_COMMAND = 'documentPresenceConfirm';

	private const PRESENCE_CONTEXT_SALT = 'DISK_DOCUMENT_PRESENCE_V1';
	private const SCOPE_SALT = 'DISK_DOCUMENT_PRESENCE_SCOPE_V1';
	private const STORAGE_KEY_PREFIX = 'disk.document.presence.v1';

	private Signer $signer;
	private ?UnifiedLinkAccessService $unifiedLinkAccessService;
	/** @var array<int, User|null> Users already queried in this request; null marks a user that does not exist. */
	private array $preloadedUsers = [];
	/** @var array<int, AttachedObject|null> Attached objects already loaded in this request; null marks a missing relation. */
	private array $resolvedAttachedObjects = [];

	public function __construct(
		?Signer $signer = null,
		?UnifiedLinkAccessService $unifiedLinkAccessService = null,
	)
	{
		$this->signer = $signer ?? new Signer();
		$this->unifiedLinkAccessService = $unifiedLinkAccessService;
	}

	/**
	 * Builds the client configuration only after the server has checked the live session and rights.
	 *
	 * @return array<string, mixed>
	 */
	public function createConfig(DocumentSession $documentSession, int $userId, string $accessType): array
	{
		try
		{
			$scope = $this->resolveAuthorizedScope($documentSession, $userId, $accessType);
			if ($scope === null)
			{
				return $this->getDisabledConfig();
			}

			$pullConfig = $this->getPullConfig($userId);
			if ($pullConfig === null || !$this->isValidPullConfig($pullConfig))
			{
				return $this->getDisabledConfig();
			}

			$confirmationChannel = $this->getConfirmationChannelConfig($scope['storageKey']);

			$presenceContext = $this->signer->sign(Json::encode([
				'sessionId' => (int)$documentSession->getId(),
				'userId' => $userId,
				'tabId' => Random::getString(32),
				'accessType' => $accessType,
			]), self::PRESENCE_CONTEXT_SALT);
		}
		catch (\Throwable)
		{
			return $this->getDisabledConfig();
		}

		return [
			'enabled' => true,
			'presenceContext' => $presenceContext,
			'heartbeatInterval' => self::HEARTBEAT_INTERVAL,
			'confirmationInterval' => self::CONFIRMATION_INTERVAL,
			'confirmationTtl' => self::CONFIRMATION_TTL,
			'actions' => [
				'enter' => 'presenceEnter',
				'heartbeat' => 'presenceHeartbeat',
				'leave' => 'presenceLeave',
			],
			'pullConfig' => $pullConfig,
			'confirmationChannel' => $confirmationChannel,
			'moduleId' => 'disk',
			'command' => self::PULL_COMMAND,
			'confirmationCommand' => self::CONFIRMATION_PULL_COMMAND,
			'scope' => $scope['scope'],
		];
	}

	/**
	 * @return array{sessionId: int, userId: int, tabId: string, accessType: string}|null
	 */
	public function verifyPresenceContext(string $presenceContext): ?array
	{
		if ($presenceContext === '')
		{
			return null;
		}

		try
		{
			$payload = Json::decode($this->signer->unsign($presenceContext, self::PRESENCE_CONTEXT_SALT));
		}
		catch (\Throwable)
		{
			return null;
		}

		if (!is_array($payload))
		{
			return null;
		}

		$sessionId = $this->normalizePositiveInt($payload['sessionId'] ?? null);
		$userId = $this->normalizePositiveInt($payload['userId'] ?? null);
		$tabId = $payload['tabId'] ?? null;
		$accessType = $payload['accessType'] ?? null;
		if (
			$sessionId === null
			|| $userId === null
			|| !is_string($tabId)
			|| !preg_match('/^[A-Za-z0-9]{16,128}$/D', $tabId)
			|| !is_string($accessType)
			|| !$this->isSupportedAccessType($accessType)
		)
		{
			return null;
		}

		return [
			'sessionId' => $sessionId,
			'userId' => $userId,
			'tabId' => $tabId,
			'accessType' => $accessType,
		];
	}

	/**
	 * @param array{sessionId: int, userId: int, tabId: string, accessType: string} $binding
	 * @return array{accessType: string, storageKey: string, scope: string, realObjectId: int, attachedObjectId: int}|null
	 */
	public function resolveAuthorizedScopeFromBinding(array $binding, int $currentUserId): ?array
	{
		if ($currentUserId <= 0 || $binding['userId'] !== $currentUserId)
		{
			return null;
		}

		try
		{
			// Eager loading is not an option here: DocumentSessionTable declares no ORM references, so any
			// `with` on this model throws and every presence request would be denied. getObject() below
			// resolves the file through the reference loader instead.
			$documentSession = DocumentSession::loadById($binding['sessionId']);
		}
		catch (\Throwable)
		{
			return null;
		}

		if (!$documentSession instanceof DocumentSession)
		{
			return null;
		}

		return $this->resolveAuthorizedScope($documentSession, $currentUserId, $binding['accessType']);
	}

	/**
	 * Resolves the scope for dropping the caller's own registration, which has to work after the session
	 * was deactivated: the terminal save deactivates document sessions before the clients are notified, so
	 * a leave request always arrives for a non-active session. Skipping the liveness and read checks is
	 * safe because the operation only narrows the roster: the key is derived from the same signed context
	 * that was issued to an authorized participant, and PresenceService::removeRegistration() additionally
	 * matches userId, sessionId and tabId of the entry it deletes.
	 *
	 * @param array{sessionId: int, userId: int, tabId: string, accessType: string} $binding
	 * @return array{accessType: string, storageKey: string, scope: string, realObjectId: int, attachedObjectId: int}|null
	 */
	public function resolveScopeForReleaseFromBinding(array $binding, int $currentUserId): ?array
	{
		if (
			$currentUserId <= 0
			|| $binding['userId'] !== $currentUserId
			|| !$this->isSupportedAccessType($binding['accessType'])
		)
		{
			return null;
		}

		try
		{
			// Eager loading is not an option here: DocumentSessionTable declares no ORM references, so any
			// `with` on this model throws. getObject() below resolves the file through the reference loader.
			$documentSession = DocumentSession::loadById($binding['sessionId']);
			if (
				!$documentSession instanceof DocumentSession
				|| (int)$documentSession->getId() <= 0
				|| !$documentSession->belongsToUser($currentUserId)
			)
			{
				return null;
			}

			$context = $documentSession->getContext();
			if (!$context instanceof DocumentSessionContext || (int)$context->getExternalLinkId() > 0)
			{
				return null;
			}

			// Keep the original binding before DocumentSessionContext clears a missing relation.
			$attachedObjectId = (int)$context->getAttachedObjectId();
			$file = $documentSession->getObject();
			if (!$file instanceof File)
			{
				return null;
			}

			return $this->buildScope(
				$file,
				$attachedObjectId,
				$binding['accessType'],
				(int)$documentSession->getVersionId(),
			);
		}
		catch (\Throwable)
		{
			return null;
		}
	}

	/**
	 * @return array{accessType: string, storageKey: string, scope: string, realObjectId: int, attachedObjectId: int}|null
	 */
	public function resolveAuthorizedScope(DocumentSession $documentSession, int $userId, string $accessType): ?array
	{
		if (
			$userId <= 0
			|| (int)$documentSession->getId() <= 0
			|| !$this->isSupportedAccessType($accessType)
			|| !$documentSession->isActive()
			|| !$documentSession->belongsToUser($userId)
		)
		{
			return null;
		}

		try
		{
			$context = $documentSession->getContext();
			if (!$context instanceof DocumentSessionContext || (int)$context->getExternalLinkId() > 0)
			{
				return null;
			}

			// Keep the original binding before DocumentSessionContext clears a missing relation.
			$attachedObjectId = (int)$context->getAttachedObjectId();
			$attachedObject = $this->resolveAttachedObject($context);
			// The scope is keyed by the attached object, so the wider link-level access must not stand in for its rights.
			if (
				$attachedObjectId > 0
				&& (!$attachedObject || !$this->canReadAttachedObject($attachedObject, $userId))
			)
			{
				return null;
			}

			$file = $this->resolveSessionFile($documentSession, $attachedObject);
			if (!$file instanceof File)
			{
				return null;
			}
			if ($accessType === self::ACCESS_TYPE_INTERNAL)
			{
				if (!$this->canReadInternally($file, $attachedObject, $userId))
				{
					return null;
				}
			}
			elseif (!$this->canReadThroughUnifiedLink($file, $attachedObject, $userId))
			{
				return null;
			}

			return $this->buildScope(
				$file,
				$attachedObjectId,
				$accessType,
				(int)$documentSession->getVersionId(),
			);
		}
		catch (\Throwable)
		{
			return null;
		}
	}

	/**
	 * Both scope paths share this builder so that a release resolves exactly the same storage key as the
	 * regular one and never touches another roster.
	 *
	 * @return array{accessType: string, storageKey: string, scope: string, realObjectId: int, attachedObjectId: int}|null
	 */
	private function buildScope(File $file, int $attachedObjectId, string $accessType, int $versionId): ?array
	{
		$realObjectId = (int)$file->getRealObjectId();
		if ($realObjectId <= 0)
		{
			return null;
		}

		// A session of a historical version must not share the roster with the current content.
		$storageKey = implode('.', [
			self::STORAGE_KEY_PREFIX,
			$accessType,
			$realObjectId,
			$attachedObjectId,
			$versionId,
		]);

		return [
			'accessType' => $accessType,
			'storageKey' => $storageKey,
			'scope' => $this->signer->getSignature($storageKey, self::SCOPE_SALT),
			'realObjectId' => $realObjectId,
			'attachedObjectId' => $attachedObjectId,
		];
	}

	/**
	 * The only form the registrations of a scope are authorized by: neither the recheck of the recipients of a
	 * roster nor the one inside the scope lock may load a document session per participant tab. Every
	 * registration of a scope resolves to the same file, attached object, version and access type, so the batch
	 * needs one session query, one user query, one attached object, one file per session object and one read
	 * check per distinct user.
	 *
	 * @param array{accessType: string, storageKey: string, scope: string, realObjectId: int, attachedObjectId: int} $scope
	 * @param array<array-key, array{userId?: mixed, sessionId?: mixed, tabId?: mixed}> $registrations
	 * @return array<array-key, array{userId?: mixed, sessionId?: mixed, tabId?: mixed}> Authorized registrations with their keys.
	 */
	public function filterAuthorizedRegistrations(array $scope, array $registrations): array
	{
		if (!$this->isSupportedAccessType($scope['accessType']))
		{
			return [];
		}

		$bindings = $this->normalizeRegistrationBindings($registrations);
		if ($bindings === [])
		{
			return [];
		}

		// The unified read check below resolves its user through User::loadById(), so the batch warms them up first.
		$this->preloadUsersForAccessType(array_column($bindings, 'userId'), $scope['accessType']);

		try
		{
			$documentSessions = $this->loadDocumentSessions(array_column($bindings, 'sessionId'));
		}
		catch (\Throwable)
		{
			return [];
		}

		$authorizedRegistrations = [];
		$attachedObjects = [];
		$files = [];
		$readVerdicts = [];
		foreach ($bindings as $key => $binding)
		{
			try
			{
				$authorized = $this->isBindingAuthorized(
					$scope,
					$binding,
					$documentSessions[$binding['sessionId']] ?? null,
					$attachedObjects,
					$files,
					$readVerdicts,
				);
			}
			catch (\Throwable)
			{
				$authorized = false;
			}

			if ($authorized)
			{
				$authorizedRegistrations[$key] = $registrations[$key];
			}
		}

		return $authorizedRegistrations;
	}

	/**
	 * @param array{accessType: string, storageKey: string, scope: string, realObjectId: int, attachedObjectId: int} $scope
	 * @param array{userId: int, sessionId: int} $binding
	 * @param array<int, AttachedObject|null> $attachedObjects Attached objects already resolved for this batch.
	 * @param array<int, File|null> $files Objects already resolved for the sessions of this batch.
	 * @param array<string, bool> $readVerdicts Read rights already checked for a user on one of those objects.
	 */
	private function isBindingAuthorized(
		array $scope,
		array $binding,
		?DocumentSession $documentSession,
		array &$attachedObjects,
		array &$files,
		array &$readVerdicts,
	): bool
	{
		if (
			$documentSession === null
			|| (int)$documentSession->getId() <= 0
			|| !$documentSession->isActive()
			|| !$documentSession->belongsToUser($binding['userId'])
		)
		{
			return false;
		}

		$context = $documentSession->getContext();
		if (!$context instanceof DocumentSessionContext || (int)$context->getExternalLinkId() > 0)
		{
			return false;
		}

		// Keep the original binding before DocumentSessionContext clears a missing relation.
		$attachedObjectId = (int)$context->getAttachedObjectId();
		// A matching storage key pins the attached object of the scope, so the whole batch shares this one.
		if (!array_key_exists($attachedObjectId, $attachedObjects))
		{
			$attachedObjects[$attachedObjectId] = $this->resolveAttachedObject($context);
		}

		$attachedObject = $attachedObjects[$attachedObjectId];
		$objectId = (int)$documentSession->getObjectId();
		if (!array_key_exists($objectId, $files))
		{
			$files[$objectId] = $this->resolveSessionFile($documentSession, $attachedObject);
		}

		$file = $files[$objectId];
		if (!$file instanceof File)
		{
			return false;
		}

		$resolvedScope = $this->buildScope(
			$file,
			$attachedObjectId,
			$scope['accessType'],
			(int)$documentSession->getVersionId(),
		);
		if ($resolvedScope === null || !hash_equals($scope['storageKey'], $resolvedScope['storageKey']))
		{
			return false;
		}

		// A matching storage key pins the object and the attached object, so one read check per user answers
		// for every tab of that user.
		$readVerdictKey = $objectId . ':' . $binding['userId'];
		if (!array_key_exists($readVerdictKey, $readVerdicts))
		{
			$readVerdicts[$readVerdictKey] = $this->canReadInScope(
				$attachedObject,
				$file,
				$attachedObjectId,
				$scope['accessType'],
				$binding['userId'],
			);
		}

		return $readVerdicts[$readVerdictKey];
	}

	private function canReadInScope(
		?AttachedObject $attachedObject,
		File $file,
		int $attachedObjectId,
		string $accessType,
		int $userId,
	): bool
	{
		// The scope is keyed by the attached object, so the wider link-level access must not stand in for its rights.
		if ($attachedObjectId > 0 && (!$attachedObject || !$this->canReadAttachedObject($attachedObject, $userId)))
		{
			return false;
		}

		return $accessType === self::ACCESS_TYPE_INTERNAL
			? $this->canReadInternally($file, $attachedObject, $userId)
			: $this->canReadThroughUnifiedLink($file, $attachedObject, $userId)
		;
	}

	/**
	 * An attached object is loaded together with its file, so a session bound to one does not have to load the
	 * same file again. The identity check keeps the scope keyed by the object of the session itself: the storage
	 * key and its signature are derived from that object, so a file of any other object must never stand in.
	 */
	private function resolveSessionFile(DocumentSession $documentSession, ?AttachedObject $attachedObject): ?File
	{
		$objectId = (int)$documentSession->getObjectId();
		$attachedFile = $attachedObject?->getFile();
		if ($objectId > 0 && $attachedFile instanceof File && (int)$attachedFile->getId() === $objectId)
		{
			return $attachedFile;
		}

		$file = $documentSession->getObject();

		return $file instanceof File ? $file : null;
	}

	/**
	 * @param array<array-key, array{userId?: mixed, sessionId?: mixed, tabId?: mixed}> $registrations
	 * @return array<array-key, array{userId: int, sessionId: int}>
	 */
	private function normalizeRegistrationBindings(array $registrations): array
	{
		$bindings = [];
		foreach ($registrations as $key => $registration)
		{
			if (!is_array($registration))
			{
				continue;
			}

			$userId = $this->normalizePositiveInt($registration['userId'] ?? null);
			$sessionId = $this->normalizePositiveInt($registration['sessionId'] ?? null);
			$tabId = $registration['tabId'] ?? null;
			if (
				$userId === null
				|| $sessionId === null
				|| !is_string($tabId)
				|| !preg_match('/^[A-Za-z0-9]{16,128}$/D', $tabId)
			)
			{
				continue;
			}

			$bindings[$key] = ['userId' => $userId, 'sessionId' => $sessionId];
		}

		return $bindings;
	}

	/**
	 * @param list<int> $sessionIds
	 * @return array<int, DocumentSession>
	 */
	protected function loadDocumentSessions(array $sessionIds): array
	{
		// Eager loading is not an option here: DocumentSessionTable declares no ORM references, so any `with`
		// on this model throws. getObject() resolves the file through the reference loader instead.
		$documentSessions = [];
		foreach (DocumentSession::loadBatchById(array_values(array_unique($sessionIds))) as $documentSession)
		{
			if ($documentSession instanceof DocumentSession)
			{
				$documentSessions[(int)$documentSession->getId()] = $documentSession;
			}
		}

		return $documentSessions;
	}

	/**
	 * Warms the user cache with a single query so that a roster of N participants does not run N queries: the
	 * unified access check and the participant profiles go through User::loadById(), which reads the cache
	 * filled here. The models are the ones loadById() builds itself, so a preloaded user is not narrower.
	 *
	 * @param list<int> $userIds
	 * @return array<int, User> Models of the requested users that exist.
	 */
	public function preloadUsers(array $userIds): array
	{
		$userIds = $this->normalizeUserIds($userIds);
		$unknownUserIds = array_values(array_diff($userIds, array_keys($this->preloadedUsers)));
		if ($unknownUserIds !== [])
		{
			// Remember the whole request even if it brings nothing back, so a repeat adds no second query.
			$this->preloadedUsers += array_fill_keys($unknownUserIds, null);

			try
			{
				foreach (User::loadBatchById($unknownUserIds) as $user)
				{
					$this->preloadedUsers[(int)$user->getId()] = $user;
				}
			}
			catch (\Throwable)
			{
			}
		}

		return array_filter(array_intersect_key($this->preloadedUsers, array_flip($userIds)));
	}

	/**
	 * Warms the users of a list together with whatever the read check of the access type asks of them beyond
	 * the model itself: the unified check asks every candidate whether he is an employee of the portal, and a
	 * model answers that with a query of its own, {@see User::isIntranetUser()}. Warming the flag for the whole
	 * list keeps it at one query per list instead of one per candidate.
	 *
	 * @param array<array-key, int> $userIds
	 */
	private function preloadUsersForAccessType(array $userIds, string $accessType): void
	{
		$users = $this->preloadUsers($userIds);
		if ($accessType !== self::ACCESS_TYPE_UNIFIED || $users === [])
		{
			return;
		}

		try
		{
			User::preloadIntranetFlags(array_keys($users));
		}
		catch (\Throwable)
		{
		}
	}

	/**
	 * Warms both the users of a roster and their avatars with a single query each.
	 *
	 * @param list<int> $userIds
	 */
	public function preloadParticipants(array $userIds): void
	{
		$users = $this->preloadUsers($userIds);
		if ($users === [])
		{
			return;
		}

		try
		{
			Avatar::preload(array_map(
				static fn(User $user): int => (int)$user->getPersonalPhoto(),
				$users,
			));
		}
		catch (\Throwable)
		{
		}
	}

	/**
	 * @param array<array-key, int> $userIds
	 * @return list<int>
	 */
	private function normalizeUserIds(array $userIds): array
	{
		return array_values(array_unique(array_filter(
			$userIds,
			static fn(int $userId): bool => $userId > 0,
		)));
	}

	/**
	 * The read check a roster recipient passes, applied to candidates that hold no registration of their
	 * own: an active document session alone does not make a user visible in the roster.
	 *
	 * @param list<int> $userIds
	 * @return list<int>
	 */
	public function filterReadable(
		array $userIds,
		File $file,
		?AttachedObject $attachedObject,
		string $accessType,
	): array
	{
		$userIds = $this->normalizeUserIds($userIds);
		if ($userIds === [] || !$this->isSupportedAccessType($accessType))
		{
			return [];
		}

		// The unified read check below resolves its user through User::loadById(), so the whole list is warmed up first.
		$this->preloadUsersForAccessType($userIds, $accessType);
		$attachedObjectId = (int)$attachedObject?->getId();

		$readableUserIds = [];
		foreach ($userIds as $userId)
		{
			try
			{
				$canRead = $this->canReadInScope($attachedObject, $file, $attachedObjectId, $accessType, $userId);
			}
			catch (\Throwable)
			{
				$canRead = false;
			}

			if ($canRead)
			{
				$readableUserIds[] = $userId;
			}
		}

		return $readableUserIds;
	}

	/**
	 * @param list<int> $userIds
	 * @return list<array{id: int, name: string, avatar: string}>
	 */
	public function resolveParticipants(array $userIds): array
	{
		$userIds = $this->normalizeUserIds($userIds);
		$this->preloadParticipants($userIds);

		$participants = [];
		foreach ($userIds as $userId)
		{
			$participant = $this->resolveParticipant($userId);
			if ($participant !== null)
			{
				$participants[] = $participant;
			}
		}

		return $participants;
	}

	/**
	 * @return array{id: int, name: string, avatar: string}|null
	 */
	public function resolveParticipant(int $userId): ?array
	{
		if ($userId <= 0)
		{
			return null;
		}

		try
		{
			$user = User::getById($userId);
		}
		catch (\Throwable)
		{
			return null;
		}

		if (!$user || (int)$user->getId() !== $userId)
		{
			return null;
		}

		return [
			'id' => $userId,
			'name' => $user->getFormattedName(),
			'avatar' => (string)($user->getAvatarSrc() ?? ''),
		];
	}

	/**
	 * @return array<string, mixed>|null
	 */
	protected function getPullConfig(int $userId): ?array
	{
		if ($userId <= 0 || !Loader::includeModule('pull'))
		{
			return null;
		}

		try
		{
			$pullConfig = \Bitrix\Pull\Config::get([
				'USER_ID' => $userId,
				'JSON' => true,
			]);
		}
		catch (\Throwable)
		{
			return null;
		}

		return is_array($pullConfig) ? $pullConfig : null;
	}

	/**
	 * The channel the openings confirm themselves to each other in. It is keyed by the scope of the
	 * presence and travels in this configuration alone, so whoever hears a confirmation is an opening the
	 * roster of that very scope is served to - the public channel of the object, listened to by every
	 * opening of the editor, carries no identity of a user and must not start to.
	 *
	 * Unlike the private channel, this one is a convenience and not a condition: an opening that cannot
	 * subscribe to it keeps its presence and simply receives no confirmations.
	 *
	 * @return array<string, mixed>|null
	 */
	protected function getConfirmationChannelConfig(string $storageKey): ?array
	{
		if ($storageKey === '' || !Loader::includeModule('pull'))
		{
			return null;
		}

		try
		{
			$confirmationChannel = \Bitrix\Pull\Config::get([
				'CHANNEL' => (new DocumentPresenceChannel($storageKey))->getPullModel(),
				'JSON' => true,
			]);
		}
		catch (\Throwable)
		{
			return null;
		}

		return is_array($confirmationChannel) && $confirmationChannel !== [] ? $confirmationChannel : null;
	}

	/**
	 * @param array<string, mixed> $pullConfig
	 */
	private function isValidPullConfig(array $pullConfig): bool
	{
		$channels = $pullConfig['channels'] ?? null;
		$privateChannel = is_array($channels) ? ($channels['private'] ?? null) : null;

		return (
			isset($pullConfig['server'], $pullConfig['api'], $pullConfig['publicChannels'])
			&& is_array($pullConfig['server'])
			&& $pullConfig['server'] !== []
			&& is_array($pullConfig['api'])
			&& $pullConfig['api'] !== []
			&& is_array($pullConfig['publicChannels'])
			&& is_array($privateChannel)
			&& isset($privateChannel['id'])
			&& is_string($privateChannel['id'])
			&& $privateChannel['id'] !== ''
		);
	}

	private function canReadInternally(File $file, ?AttachedObject $attachedObject, int $userId): bool
	{
		if ($attachedObject)
		{
			// Every caller has already required the read right on this attached object from the very user
			// being checked here.
			return true;
		}

		$storage = $file->getStorage();
		if (!$storage)
		{
			return false;
		}

		$securityContext = $storage->getSecurityContext($userId);

		return $securityContext && $file->canRead($securityContext);
	}

	/**
	 * {@see DocumentSession::getContext()} builds a new context on every call and every context loads the
	 * attached object anew, while one request asks the same opening about it several times over. The model
	 * is the one the context builds itself, and the right on it is asked per user further on, so sharing it
	 * changes no verdict.
	 */
	public function resolveAttachedObject(DocumentSessionContext $context): ?AttachedObject
	{
		$attachedObjectId = (int)$context->getAttachedObjectId();
		if ($attachedObjectId <= 0)
		{
			return null;
		}

		if (!array_key_exists($attachedObjectId, $this->resolvedAttachedObjects))
		{
			// Remember a missing relation too, so a broken binding costs one query rather than one per ask.
			$this->resolvedAttachedObjects[$attachedObjectId] = $context->getAttachedObject();
		}

		return $this->resolvedAttachedObjects[$attachedObjectId];
	}

	/**
	 * The right that keys a roster to an attached object: whoever the roster is built for has to hold it,
	 * not only whoever it lists. {@see InitialRosterService} asks it of the requester on its own, because
	 * its roster is not bound by the scope this service resolves.
	 */
	public function canReadAttachedObject(AttachedObject $attachedObject, int $userId): bool
	{
		return $attachedObject->canRead($userId);
	}

	private function canReadThroughUnifiedLink(File $file, ?AttachedObject $attachedObject, int $userId): bool
	{
		try
		{
			$accessService = $this->unifiedLinkAccessService
				?? ServiceLocator::getInstance()->get(UnifiedLinkAccessService::class);

			return $accessService->check($file, $attachedObject, $userId)->canRead();
		}
		catch (\Throwable)
		{
			return false;
		}
	}

	private function isSupportedAccessType(string $accessType): bool
	{
		return in_array($accessType, [self::ACCESS_TYPE_INTERNAL, self::ACCESS_TYPE_UNIFIED], true);
	}

	private function normalizePositiveInt(mixed $value): ?int
	{
		if (is_int($value))
		{
			return $value > 0 ? $value : null;
		}
		if (!is_string($value) || !ctype_digit($value))
		{
			return null;
		}

		$normalized = (int)$value;

		return $normalized > 0 ? $normalized : null;
	}

	/**
	 * @return array{enabled: false}
	 */
	private function getDisabledConfig(): array
	{
		return ['enabled' => false];
	}
}
