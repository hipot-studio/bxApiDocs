<?php

namespace Bitrix\Call;

use Bitrix\Im\Dialog;
use Bitrix\Im\Model\AliasTable;
use Bitrix\Call\Model\CallTable;
use Bitrix\Call\Model\CallUserTable;
use Bitrix\Main\Application;
use Bitrix\Main\EventManager;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\Event;
use Bitrix\Main\ORM;
use Bitrix\Main\UserTable;
use Bitrix\Main\Web\JWT;
use Bitrix\Main\Error;
use Bitrix\Main\ErrorCollection;
use Bitrix\Call\Rest\Auth;
use Bitrix\Call\Integration\EntityFactory;
use Bitrix\Call\Integration\EntityType;

class Call
{
	public const
		STATE_NEW = 'new',
		STATE_INVITING = 'inviting',
		STATE_ANSWERED = 'answered',
		STATE_FINISHED = 'finished'
	;

	public const
		TYPE_INSTANT = 1,
		TYPE_PERMANENT = 2,
		TYPE_LANGE = 3
	;

	public const
		SCHEME_CLASSIC = 1,
		SCHEME_JWT = 2
	;

	public const
		PROVIDER_PLAIN = 'Plain',
		PROVIDER_BITRIX = 'Bitrix',
		PROVIDER_VOXIMPLANT = 'Voximplant'
	;

	public const PROVIDERS = [
		self::PROVIDER_PLAIN,
		self::PROVIDER_BITRIX,
		self::PROVIDER_VOXIMPLANT,
	];

	protected $id;
	protected $type;
	protected int $scheme;
	protected $initiatorId;
	protected ?int $actionUserId = null;
	protected bool $silentFinish = false;
	protected $isPublic = false;
	protected $publicId;
	protected $provider;
	protected $entityType;
	protected $entityId;
	protected $parentId;
	protected $parentUuid;
	protected $state;
	protected ?string $finishPrevState = null;
	/** @var DateTime|null */
	protected $startDate;
	/** @var DateTime|null */
	protected $endDate;
	protected $logUrl;
	protected $chatId;
	protected $uuid;
	protected $secretKey;
	protected $endpoint;

	/**
	 * Current record status
	 */
	protected bool $enableAudioRecord = false;

	/**
	 * Record will be analyzed with AI
	 */
	protected bool $enableAiAnalyze = false;

	protected ?\Bitrix\Call\Integration\AbstractEntity $associatedEntity = null;

	/** @var CallUser[] */
	protected $users;
	protected $userData;

	protected ?Signaling $signaling = null;

	protected ?ErrorCollection $errorCollection = null;

	/**
	 * Use one of the named constructors
	 */
	protected function __construct()
	{
	}

	/**
	 * Single source of truth for the provider allowlist: the module handles the
	 * providers of self::PROVIDERS only, everything else comes from a broken client.
	 */
	public static function isProviderSupported(string $provider): bool
	{
		return in_array($provider, self::PROVIDERS, true);
	}

	/**
	 * @return int
	 */
	public function getId(): int
	{
		return (int)$this->id;
	}

	/**
	 * @return int
	 */
	public function getType(): int
	{
		return (int)$this->type;
	}

	/**
	 * @return int
	 */
	public function getScheme(): int
	{
		return $this->scheme;
	}

	/**
	 * @return string
	 */
	public function getProvider(): string
	{
		return $this->provider;
	}

	/**
	 * @return int
	 */
	public function getInitiatorId(): int
	{
		return $this->initiatorId;
	}

	/**
	 * @return int|null
	 */
	public function getActionUserId(): ?int
	{
		return $this->actionUserId;
	}

	//region Errors

	/**
	 * Add multiple errors
	 * @param Error[] $errors
	 */
	public function addErrors(array $errors): void
	{
		if (!$this->errorCollection instanceof ErrorCollection)
		{
			$this->errorCollection = new ErrorCollection();
		}
		$this->errorCollection->add($errors);
	}

	/**
	 * @return Error[]
	 */
	public function getErrors(): array
	{
		if ($this->errorCollection instanceof ErrorCollection)
		{
			return $this->errorCollection->toArray();
		}

		return [];
	}

	/**
	 * Upends stack of errors.
	 * @param Error $error Error message object.
	 * @return void
	 */
	public function addError(Error $error): void
	{
		if (!$this->errorCollection instanceof ErrorCollection)
		{
			$this->errorCollection = new ErrorCollection();
		}
		$this->errorCollection->add([$error]);
	}

	/**
	 * Tells true if error have happened.
	 * @return boolean
	 */
	public function hasErrors(): bool
	{
		if ($this->errorCollection instanceof ErrorCollection)
		{
			return !$this->errorCollection->isEmpty();
		}

		return false;
	}

	//endregion

	//region Users

	public function setActionUserId(int $byUserId): self
	{
		$this->actionUserId = $byUserId;
		return $this;
	}

	/**
	 * Marks finish() as silent clean-up of a stuck call.
	 * Integrations on state change (e.g. chat messages) should skip side effects.
	 */
	public function setSilentFinish(bool $silent = true): self
	{
		$this->silentFinish = $silent;
		return $this;
	}

	public function isSilentFinish(): bool
	{
		return $this->silentFinish;
	}

	/**
	 * @param int $userId
	 * @return CallUser|null
	 */
	public function getUser($userId): ?CallUser
	{
		$this->loadUsers();
		return isset($this->users[$userId]) ? $this->users[$userId] : null;
	}

	/**
	 * Whether an empty invite list on a repeated invite is a legitimate "no one left to
	 * call" no-op rather than an error. Returns true only when there is at least one
	 * requested recipient and every requested id is already a participant of this call
	 * AND in a terminal state (DECLINED / BUSY). Any other reason for an empty invite
	 * list — empty / extranet-filtered input, invalid ids, or users that could not be
	 * added (capacity) — is NOT terminal and must still surface the empty_users error.
	 *
	 * @param int[] $userIds requested recipient ids
	 */
	public function areAllRequestedUsersTerminal(array $userIds): bool
	{
		$userIds = array_values(array_unique(array_map('intval', $userIds)));
		if ($userIds === [])
		{
			return false;
		}

		foreach ($userIds as $userId)
		{
			if ($userId <= 0)
			{
				return false;
			}

			$callUser = $this->getUser($userId);
			if (!$callUser || !$callUser->isTerminalState())
			{
				return false;
			}
		}

		return true;
	}

	/**
	 * Returns arrays of ids of the users, currently participating in the call.
	 * @return int[]
	 */
	public function getUsers(): array
	{
		$this->loadUsers();
		return array_keys($this->users);
	}

	/**
	 * Returns arrays of the users, currently participating in the call.
	 * @return CallUser[]
	 */
	public function getCallUsers(): array
	{
		$this->loadUsers();
		return $this->users;
	}

	/**
	 * Returns arrays of information about the users currently participating in the call.
	 * @param int[] $users
	 * @return array
	 */
	public function getUserData(array $users = []): array
	{
		$usersCount = count($users);
		$usersById = array_flip($users);

		if ($usersCount && isset($this->userData))
		{
			$intersection = array_intersect_key($this->userData, $usersById);
			if ($usersCount !== count($intersection))
			{
				$usersWithoutData = array_diff_key($usersById, $intersection);
				$userData = $this->prepareUserData(array_keys($usersWithoutData));
				$this->userData = array_replace($this->userData, $userData);
			}
		}
		elseif ($usersCount && !isset($this->userData))
		{
			$this->userData = $this->prepareUserData($users);
		}
		else
		{
			$this->userData = $this->prepareUserData($this->getUsers());
		}

		return $this->userData;
	}

	/**
	 * @param int[] $users
	 * @return array
	 */
	public function prepareUserData(array $users): array
	{
		$userData = Util::getUsers($users);
		$userRoles = $this->getUserRoles($users);
		foreach ($userData as $userId => &$user)
		{
			$user['role'] = $userRoles[$userId];
		}

		return $userData;
	}

	/**
	 * Returns user's roles in the call.
	 *
	 * @param int[] $users
	 * @return array
	 */
	public function getUserRoles(array $users = []): array
	{
		if (empty($users))
		{
			$users = $this->getUsers();
		}
		$userRoles = [];

		$chatOwnerId = (int)$this->getAssociatedEntity()?->getOwnerId();
		$chatManagerIds = $this->getAssociatedEntity()?->getManagerIds() ?? [];
		foreach ($users as $userId)
		{
			$userId = (int)$userId;
			$userRoles[$userId] = match (true)
			{
				$userId === $chatOwnerId => 'ADMIN',
				in_array($userId, $chatManagerIds, true) => 'MANAGER',
				default => 'USER',
			};
		}

		return $userRoles;
	}

	/**
	 * Return true if a user is the part of the call.
	 *
	 * @param int $userId Id of the user.
	 * @return bool
	 */
	public function hasUser($userId): bool
	{
		$this->loadUsers();
		return isset($this->users[$userId]);
	}

	/**
	 * Adds new user to the call.
	 *
	 * @deprecated prefer {@see self::addUsers()} for batches — avoids N per-user
	 *             INSERTs and N per-user onUserAdd integration callbacks on the
	 *             startCall hot path.
	 *
	 * @param int $newUserId
	 * @return CallUser|null
	 */
	public function addUser($newUserId): ?CallUser
	{
		$newUserId = (int)$newUserId;
		$added = $this->addUsers([$newUserId]);
		if ($added === [])
		{
			$this->loadUsers();
			return $this->users[$newUserId] ?? null;
		}
		return $this->users[$newUserId];
	}

	/**
	 * Batched counterpart of {@see self::addUser()}.
	 *
	 * Inserts all missing users in a single SQL statement, then fires
	 * associatedEntity->onUsersAdd once for the inserted subset. Respects
	 * {@see self::getMaxUsers()} — the tail of the input is silently dropped
	 * when the call is already at capacity.
	 *
	 * @param int[] $newUserIds
	 * @return int[] users actually inserted (those already present or overflowing are excluded)
	 */
	public function addUsers(array $newUserIds): array
	{
		$this->loadUsers();

		$toInsert = [];
		foreach ($newUserIds as $id)
		{
			$id = (int)$id;
			if ($id <= 0 || isset($this->users[$id]) || isset($toInsert[$id]))
			{
				continue;
			}
			$toInsert[$id] = true;
		}
		$toInsert = array_keys($toInsert);
		if ($toInsert === [])
		{
			return [];
		}

		$capacity = $this->getMaxUsers() - count($this->users);
		if ($capacity <= 0)
		{
			return [];
		}
		if (count($toInsert) > $capacity)
		{
			$toInsert = array_slice($toInsert, 0, $capacity);
		}

		$this->bulkInsertCallUsers($toInsert, CallUser::STATE_IDLE);

		foreach ($toInsert as $uid)
		{
			$this->users[$uid] = CallUser::create([
				'CALL_ID' => $this->id,
				'USER_ID' => $uid,
				'STATE' => CallUser::STATE_IDLE,
				'LAST_SEEN' => null,
			]);
		}
		unset($this->userData);

		if ($this->associatedEntity)
		{
			$this->associatedEntity->onUsersAdd($toInsert);
		}

		return $toInsert;
	}

	/**
	 * Single INSERT IGNORE for N (CALL_ID, USER_ID, STATE) rows.
	 *
	 * Portable across MySQL/PgSQL via {@see \Bitrix\Main\DB\SqlHelper::getInsertIgnore()}.
	 * Mirrors the batch shape of ORM\Data\AddStrategy\InsertIgnore — avoids
	 * DataManager::addMulti, which fires onBefore/after events per row and
	 * has no ignore-on-conflict variant exposed.
	 *
	 * @internal Use {@see self::addUsers()} or higher-level helpers; this
	 *           method only inserts rows and does NOT touch the in-memory
	 *           $this->users map.
	 *
	 * @param int[] $userIds
	 */
	private function bulkInsertCallUsers(array $userIds, string $state): void
	{
		if ($userIds === [])
		{
			return;
		}

		$connection = Application::getConnection();
		$sqlHelper = $connection->getSqlHelper();
		$tableName = CallUserTable::getTableName();

		$sharedColumns = null;
		$sqlValues = [];
		foreach ($userIds as $uid)
		{
			$row = [
				'CALL_ID' => $this->id,
				'USER_ID' => (int)$uid,
				'STATE' => $state,
				'LAST_SEEN' => null,
			];
			[$columns, $values] = $sqlHelper->prepareInsert($tableName, $row, true);
			if ($sharedColumns === null)
			{
				$sharedColumns = $columns;
			}
			$sqlValues[] = '(' . implode(', ', $values) . ')';
		}

		$sql = $sqlHelper->getInsertIgnore(
			$tableName,
			' (' . implode(', ', $sharedColumns) . ')',
			' VALUES ' . implode(', ', $sqlValues),
		);
		$connection->queryExecute($sql);
	}

	/**
	 * Bulk-transitions multiple users to STATE_CALLING in one UPDATE.
	 * STATE_CALLING transition:
	 *   - sets STATE = 'calling' and refreshes LAST_SEEN to now()
	 *   - skips users already in STATE_READY (they have answered)
	 *   - fires OnCallUserStateChange per affected user so listeners (e.g.
	 *
	 * @param int[] $userIds
	 * @return int[] users actually transitioned to CALLING
	 */
	public function markUsersCalling(array $userIds): array
	{
		$this->loadUsers();

		$now = new DateTime();
		$targets = [];
		$oldStates = [];
		foreach ($userIds as $uid)
		{
			$uid = (int)$uid;
			$callUser = $this->users[$uid] ?? null;
			if (!$callUser || $callUser->getState() === CallUser::STATE_READY)
			{
				continue;
			}
			$targets[] = $uid;
			$oldStates[$uid] = $callUser->getState();
		}
		if ($targets === [])
		{
			return [];
		}

		$entity = CallUserTable::getEntity();
		$connection = Application::getConnection();
		$sqlHelper = $connection->getSqlHelper();
		$tableName = CallUserTable::getTableName();

		[$updateClause] = $sqlHelper->prepareUpdate($tableName, [
			'STATE' => CallUser::STATE_CALLING,
			'LAST_SEEN' => $now,
		]);
		$whereClause = ORM\Query\Query::buildFilterSql($entity, [
			'=CALL_ID' => $this->id,
			'@USER_ID' => $targets,
			'!=STATE' => CallUser::STATE_READY,
		]);

		if ($updateClause === '' || $whereClause === '')
		{
			return [];
		}

		$connection->queryExecute("UPDATE {$tableName} SET {$updateClause} WHERE {$whereClause}");

		// Race-with-answerAction guard
		$transitioned = [];
		$rows = CallUserTable::getList([
			'select' => ['USER_ID'],
			'filter' => [
				'=CALL_ID' => $this->id,
				'@USER_ID' => $targets,
				'=STATE' => CallUser::STATE_CALLING,
			],
		])->fetchAll();
		foreach ($rows as $row)
		{
			$transitioned[(int)$row['USER_ID']] = true;
		}

		// Collect state changes for deferred event dispatch
		$changes = [];
		$applied = [];
		foreach ($targets as $uid)
		{
			if (!isset($transitioned[$uid]))
			{
				// !=STATE_READY filter, leave the in-memory CallUser as-is so it stays consistent with the DB
				continue;
			}

			$this->users[$uid]->setFields([
				'STATE' => CallUser::STATE_CALLING,
				'LAST_SEEN' => $now,
			]);
			$applied[] = $uid;

			// OnCallUserStateChange contract is "state actually changed":
			// suppress for users whose snapshot already matches the target.
			if ($oldStates[$uid] === CallUser::STATE_CALLING)
			{
				continue;
			}

			$changes[] = [
				'callId' => $this->id,
				'userId' => $uid,
				'oldState' => $oldStates[$uid],
				'newState' => CallUser::STATE_CALLING,
			];
		}

		if ($changes !== [])
		{
			Application::getInstance()->addBackgroundJob([EventHandler::class, 'dispatchUserStateChangeEventsBatch'], [$changes]);
		}

		return $applied;
	}

	/**
	 * Bulk transition to {@see CallUser::STATE_READY}: one UPDATE grouped by
	 * the IS_MOBILE flag (1–2 statements vs N pairs of UPDATEs in the
	 * legacy per-user path), in-memory mirroring, and one
	 * `OnCallUserStateChange` event per affected user. Sets STATE='ready',
	 * LAST_SEEN=NOW(), FIRST_JOINED=COALESCE(FIRST_JOINED, NOW()) so a user
	 * who briefly drops and reconnects keeps the original join timestamp.
	 *
	 * Users absent from the in-memory roster (`getUser` returns null) are
	 * silently skipped — they would no-op in the legacy path too.
	 *
	 * @param array<int, bool> $usersWithMobile [userId => isMobile]
	 * @return int[] users actually transitioned
	 */
	public function setUsersStateReady(array $usersWithMobile): array
	{
		$this->loadUsers();

		$now = new DateTime();
		$mobileGroups = ['Y' => [], 'N' => []];
		$mobileFlagByUid = [];
		$oldStates = [];
		$preservedFirstJoined = [];
		foreach ($usersWithMobile as $uid => $isMobile)
		{
			$uid = (int)$uid;
			$callUser = $this->users[$uid] ?? null;
			if (!$callUser)
			{
				continue;
			}
			$flag = $isMobile ? 'Y' : 'N';
			$mobileGroups[$flag][] = $uid;
			$mobileFlagByUid[$uid] = $flag;
			$oldStates[$uid] = $callUser->getState();
			$preservedFirstJoined[$uid] = $callUser->getFirstJoined();
		}
		if ($mobileGroups['Y'] === [] && $mobileGroups['N'] === [])
		{
			return [];
		}

		$entity = CallUserTable::getEntity();
		$connection = Application::getConnection();
		$sqlHelper = $connection->getSqlHelper();
		$tableName = CallUserTable::getTableName();
		$nowSql = $sqlHelper->getCurrentDateTimeFunction();
		$firstJoinedColumn = $sqlHelper->quote('FIRST_JOINED');

		$transitioned = [];
		foreach ($mobileGroups as $flag => $userIds)
		{
			if ($userIds === [])
			{
				continue;
			}

			[$updateClause] = $sqlHelper->prepareUpdate($tableName, [
				'STATE' => CallUser::STATE_READY,
				'LAST_SEEN' => $now,
				'IS_MOBILE' => $flag,
			]);
			$updateClause .= ", {$firstJoinedColumn} = COALESCE({$firstJoinedColumn}, {$nowSql})";

			$whereClause = ORM\Query\Query::buildFilterSql($entity, [
				'=CALL_ID' => $this->id,
				'@USER_ID' => $userIds,
			]);
			if ($updateClause === '' || $whereClause === '')
			{
				continue;
			}

			$connection->queryExecute("UPDATE {$tableName} SET {$updateClause} WHERE {$whereClause}");
			array_push($transitioned, ...$userIds);
		}
		if ($transitioned === [])
		{
			return [];
		}

		$eventManager = EventManager::getInstance();
		foreach ($transitioned as $uid)
		{
			$this->users[$uid]->setFields([
				'STATE' => CallUser::STATE_READY,
				'LAST_SEEN' => $now,
				'FIRST_JOINED' => $preservedFirstJoined[$uid] ?: $now,
				'IS_MOBILE' => $mobileFlagByUid[$uid],
			]);

			// OnCallUserStateChange contract is "state actually changed":
			// reconnect of an already-READY peer refreshes LAST_SEEN/IS_MOBILE
			// (intentional heartbeat) but does not warrant a state-change event.
			if ($oldStates[$uid] === CallUser::STATE_READY)
			{
				continue;
			}

			$eventManager->send(new Event(
				'im',
				'OnCallUserStateChange',
				[
					'callId' => $this->id,
					'userId' => $uid,
					'oldState' => $oldStates[$uid],
					'newState' => CallUser::STATE_READY,
				],
			));
		}

		return $transitioned;
	}

	/**
	 * Bulk transition to {@see CallUser::STATE_IDLE}: one UPDATE, in-memory
	 * mirroring, and one `OnCallUserStateChange` event per user. Used by
	 * `userStatusAction` for `disconnectedUsers` instead of a per-user
	 * `updateState` + `updateLastSeen` pair (2 ORM operations × N).
	 *
	 * @param int[] $userIds
	 * @return int[] users actually transitioned
	 */
	public function setUsersStateIdle(array $userIds): array
	{
		$this->loadUsers();

		$now = new DateTime();
		$targets = [];
		$oldStates = [];
		foreach ($userIds as $uid)
		{
			$uid = (int)$uid;
			$callUser = $this->users[$uid] ?? null;
			if (!$callUser)
			{
				continue;
			}
			$targets[] = $uid;
			$oldStates[$uid] = $callUser->getState();
		}
		if ($targets === [])
		{
			return [];
		}

		$entity = CallUserTable::getEntity();
		$connection = Application::getConnection();
		$sqlHelper = $connection->getSqlHelper();
		$tableName = CallUserTable::getTableName();

		[$updateClause] = $sqlHelper->prepareUpdate($tableName, [
			'STATE' => CallUser::STATE_IDLE,
			'LAST_SEEN' => $now,
		]);
		$whereClause = ORM\Query\Query::buildFilterSql($entity, [
			'=CALL_ID' => $this->id,
			'@USER_ID' => $targets,
		]);

		if ($updateClause === '' || $whereClause === '')
		{
			return [];
		}

		$connection->queryExecute("UPDATE {$tableName} SET {$updateClause} WHERE {$whereClause}");

		$eventManager = EventManager::getInstance();
		foreach ($targets as $uid)
		{
			$this->users[$uid]->setFields([
				'STATE' => CallUser::STATE_IDLE,
				'LAST_SEEN' => $now,
			]);

			// OnCallUserStateChange contract is "state actually changed":
			// suppress for users whose snapshot already matches the target.
			if ($oldStates[$uid] === CallUser::STATE_IDLE)
			{
				continue;
			}

			$eventManager->send(new Event(
				'im',
				'OnCallUserStateChange',
				[
					'callId' => $this->id,
					'userId' => $uid,
					'oldState' => $oldStates[$uid],
					'newState' => CallUser::STATE_IDLE,
				],
			));
		}

		return $targets;
	}

	/**
	 * Batched counterpart of {@see self::checkAccess()}. Users already on the
	 * call's roster are accepted in O(1) via an in-memory flip; the rare
	 * out-of-roster subset falls back to the per-user
	 * `getAssociatedEntity()->checkAccess()` path.
	 *
	 * @param int[] $userIds
	 * @return int[] subset of $userIds that have access (order preserved)
	 */
	public function checkAccessBatch(array $userIds): array
	{
		if ($userIds === [])
		{
			return [];
		}

		$members = array_flip(array_map('intval', $this->getUsers()));
		$entity = $this->getAssociatedEntity();

		$allowed = [];
		foreach ($userIds as $uid)
		{
			$uid = (int)$uid;
			if (isset($members[$uid]))
			{
				$allowed[] = $uid;
				continue;
			}
			if ($entity?->checkAccess($uid))
			{
				$allowed[] = $uid;
			}
		}

		return $allowed;
	}

	/**
	 * Injects pre-fetched `b_call_user` rows into `$this->users` so a
	 * subsequent {@see self::loadUsers()} short-circuits without hitting the
	 * DB. Used by cache-rebuild paths (e.g. {@see \Bitrix\Call\Cache\ActiveCallsCache})
	 * that already SELECTed every CallUser row in one batched query and want
	 * the per-call instance to consume them without re-issuing per-call
	 * SELECTs.
	 *
	 * Each row must follow the {@see CallUserTable::getList()} shape (USER_ID,
	 * CALL_ID, STATE, LAST_SEEN, FIRST_JOINED, IS_MOBILE, SHARED_SCREEN,
	 * RECORDED). Rows whose CALL_ID does not match this instance are
	 * silently ignored — defends the rebuild path against accidentally
	 * pushing rows from a different call into the bucket.
	 *
	 * @param array<int, array> $rows
	 */
	public function setPreloadedUsers(array $rows): self
	{
		$this->users = [];
		foreach ($rows as $row)
		{
			$userId = (int)($row['USER_ID'] ?? 0);
			$callId = (int)($row['CALL_ID'] ?? 0);
			if ($userId <= 0 || ($callId !== 0 && $callId !== (int)$this->id))
			{
				continue;
			}
			$this->users[$userId] = CallUser::create($row);
		}
		unset($this->userData);
		return $this;
	}

	public function removeUser($userId): void
	{
		$this->loadUsers();
		if($this->users[$userId])
		{
			CallUser::delete($this->id, $userId);
			unset($this->users[$userId]);
			unset($this->userData[$userId]);
		}
	}

	/**
	 * Call is considered active if it has at least:
	 *  - one user in ready state
	 *  - another user in ready or calling state
	 *
	 * @param bool $mustHaveLiveConversation
	 * @return bool
	 */
	public function hasActiveUsers(bool $mustHaveLiveConversation = true): bool
	{
		$this->loadUsers();
		$states = [];
		$readyUserIds = [];
		foreach ($this->users as $user)
		{
			$userState = $user->getState();
			$states[$userState] = isset($states[$userState]) ? $states[$userState] + 1 : 1;
			if ($userState === CallUser::STATE_READY)
			{
				$readyUserIds[] = $user->getUserId();
			}
		}

		if (in_array($this->type, [static::TYPE_PERMANENT, static::TYPE_LANGE], true))
		{
			$readyCount = $states[CallUser::STATE_READY] ?? 0;
			if ($readyCount >= 2)
			{
				return true;
			}

			if ($readyCount !== 1)
			{
				return false;
			}

			// Exactly one READY user — keep the room alive only if they are
			// privileged (initiator, chat owner / ADMIN, chat manager / MANAGER).
			$roles = $this->getUserRoles($readyUserIds);
			$userId = $readyUserIds[0];

			return $userId === $this->initiatorId
				|| in_array($roles[$userId] ?? null, ['ADMIN', 'MANAGER'], true);
		}

		if (!$mustHaveLiveConversation)
		{
			$ready = $states[CallUser::STATE_READY] ?? 0;
			$calling = $states[CallUser::STATE_CALLING] ?? 0;
			$unavailable = $states[CallUser::STATE_UNAVAILABLE] ?? 0;

			return $ready >= 1 && ($ready + $calling + $unavailable >= 2);
		}

		return ($states[CallUser::STATE_READY] ?? 0) >= 2
			|| (($states[CallUser::STATE_READY] ?? 0) >= 1 && ($states[CallUser::STATE_CALLING] ?? 0) >= 1);
	}

	/**
	 * Returns true when at least one participant is in STATE_READY (i.e. the host
	 * has already connected). Used by the guest join flow to decide whether a call
	 * is worth joining without requiring the >=2 participants rule of hasActiveUsers.
	 */
	public function hasReadyUsers(): bool
	{
		$this->loadUsers();
		foreach ($this->users as $user)
		{
			if ($user->getState() === CallUser::STATE_READY)
			{
				return true;
			}
		}

		return false;
	}

	//endregion

	public function getSignaling(): Signaling
	{
		if (is_null($this->signaling))
		{
			$this->signaling = new Signaling($this);
		}

		return $this->signaling;
	}

	/**
	 * @return \Bitrix\Call\Integration\AbstractEntity|\Bitrix\Call\Integration\Chat|null
	 */
	public function getAssociatedEntity(): ?\Bitrix\Call\Integration\AbstractEntity
	{
		return $this->associatedEntity;
	}

	/**
	 * Whether this is a genuine 1-1 ringing call — the single scope predicate for
	 * device-scoped decline. Canonical source of truth for registration, the decline
	 * gate, startPush, finish cleanup and the client-facing isDeviceScoped flag, so
	 * those paths can never drift apart. A call qualifies iff it is TYPE_INSTANT
	 * (excludes a private chat created as a permanent call) AND its associated entity
	 * is a private chat (excludes group TYPE_INSTANT calls).
	 */
	public function isDeviceScopedRinging(): bool
	{
		$entity = $this->getAssociatedEntity();

		return $this->getType() === self::TYPE_INSTANT
			&& $entity instanceof \Bitrix\Call\Integration\Chat
			&& $entity->isPrivateChat();
	}

	/**
	 * @param string $entityType
	 * @param int $entityId
	 * @return void
	 * @throws ArgumentException
	 */
	public function setAssociatedEntity($entityType, $entityId): void
	{
		$entity = EntityFactory::createEntity($this, $entityType, $entityId);
		if (!$entity)
		{
			throw new ArgumentException("Unknown entity " . $entityType . "; " . $entityId);
		}

		$this->associatedEntity = $entity;
		$this->entityType = $entityType;
		$this->entityId = $entityId;
		if ($entityType == EntityType::CHAT)
		{
			$this->chatId = $entity->getChatId();
		}
		$this->save();

		$this->getSignaling()->sendAssociatedEntityReplaced($this->getCurrentUserId());
	}

	/**
	 * Returns true if specified user has access to the call.
	 *
	 * @param int $userId Id of the user.
	 * @return bool
	 */
	public function checkAccess($userId): bool
	{
		if (in_array($userId, $this->getUsers()))
		{
			return true;
		}
		if ($this->getAssociatedEntity()?->checkAccess($userId))
		{
			return true;
		}
		return false;
	}

	/**
	 * Closed-form membership probe: checks a single `(CALL_ID, USER_ID)` row in
	 * `b_call_user` without materializing the whole roster via {@see self::loadUsers()}.
	 *
	 * @param int $userId Id of the user.
	 * @return bool
	 */
	public function hasUserClosedForm(int $userId): bool
	{
		if ($userId <= 0)
		{
			return false;
		}

		// Already-materialized roster: answer from memory, no extra query.
		if (is_array($this->users))
		{
			return isset($this->users[$userId]);
		}

		return CallUserTable::getRow([
			'select' => ['USER_ID'],
			'filter' => [
				'=CALL_ID' => $this->getId(),
				'=USER_ID' => $userId,
			],
		]) !== null;
	}

	/**
	 * Closed-form counterpart of {@see self::checkAccess()} for a single user.
	 *
	 * Preserves the exact access semantics — "call member OR associated-entity
	 * access" — but performs the membership check as a targeted single-row lookup
	 * instead of loading the full roster. Use on read/access paths that only need
	 * to authorize one user.
	 *
	 * @param int $userId Id of the user.
	 * @return bool
	 */
	public function checkAccessClosedForm(int $userId): bool
	{
		if ($this->hasUserClosedForm($userId))
		{
			return true;
		}

		return (bool)($this->getAssociatedEntity()?->checkAccess($userId));
	}

	/**
	 * Targeted single-user read without materializing the whole roster.
	 * Mirrors how {@see self::loadUsers()} builds a CallUser from a full
	 * `b_call_user` row, but fetches only the requested `(CALL_ID, USER_ID)` row.
	 * Use on read paths (e.g. getUserState) that need one participant's state,
	 * not the whole roster.
	 *
	 * @param int $userId Id of the user.
	 * @return CallUser|null
	 */
	public function getUserClosedForm(int $userId): ?CallUser
	{
		// Already-materialized roster: reuse it, no extra query.
		if (is_array($this->users))
		{
			return $this->users[$userId] ?? null;
		}

		$row = CallUserTable::getList([
			'filter' => [
				'=CALL_ID' => $this->getId(),
				'=USER_ID' => $userId,
			],
			'limit' => 1,
		])->fetch();

		return $row ? CallUser::create($row) : null;
	}

	/**
	 * @return string
	 */
	public function getState(): string
	{
		return $this->state;
	}

	/**
	 * @return int|null
	 */
	public function getParentId(): ?int
	{
		return $this->parentId;
	}

	/**
	 * @return string|null
	 */
	public function getParentUuid(): ?string
	{
		return $this->parentUuid;
	}

	/**
	 * Returns id of the chat, associated with the call.
	 *
	 * @return int
	 */
	public function getChatId(): int
	{
		return $this->chatId;
	}

	public function getUuid()
	{
		return $this->uuid;
	}

	public function getSecretKey()
	{
		return $this->secretKey;
	}

	public function getEndpoint()
	{
		return $this->endpoint;
	}

	/**
	 * Returns date of the call start.
	 *
	 * @return DateTime|null
	 */
	public function getStartDate(): ?DateTime
	{
		return $this->startDate;
	}

	/**
	 * Returns date of the call end (if there is one).
	 *
	 * @return DateTime|null
	 */
	public function getEndDate(): ?DateTime
	{
		return $this->endDate;
	}

	/**
	 * Returns call duration.
	 * @return int
	 */
	public function getDuration(): int
	{
		if ($this->startDate)
		{
			$end = $this->endDate ?? new DateTime();
			return $end->getTimestamp() - $this->startDate->getTimestamp();
		}
		return -1;
	}

	/**
	 * Do need to record call.
	 * @return bool
	 */
	public function autoStartRecording(): bool
	{
		// by settings or tariif
		$enable = false;

		Loader::includeModule('call');

		if (
			\Bitrix\Call\Integration\AI\CallAISettings::isCallAIEnable()
			&& \Bitrix\Call\Integration\AI\CallAISettings::isAutoStartRecordingEnable()
		)
		{
			// by user limit
			$minUserCount = (int)\Bitrix\Call\Integration\AI\CallAISettings::getRecordMinUsers();
			if ($minUserCount > 0)
			{
				$userCount = $this->getUserCount();
				if ($userCount && $userCount >= $minUserCount)
				{
					$enable = true;
				}
			}
		}

		return $enable;
	}

	public function getUserCount(): int
	{
		$userCount = 0;
		if ($this->associatedEntity)
		{
			$userCount = count($this->associatedEntity->getUsers());
		}
		if (!$userCount && $this->id)
		{
			$this->loadUsers();
			$userCount = count($this->users);
		}

		return $userCount;
	}

	/**
	 * Record call.
	 * @return bool
	 */
	public function isAudioRecordEnabled(): bool
	{
		return $this->enableAudioRecord;
	}

	/**
	 * Do need to record call.
	 * @return self
	 */
	public function enableAudioRecord(): self
	{
		$this->enableAudioRecord = true;
		return $this;
	}

	/**
	 * Disable record call.
	 * @return self
	 */
	public function disableAudioRecord(): self
	{
		$this->enableAudioRecord = false;
		return $this;
	}

	/**
	 * Analyze call with AI enabled.
	 * @return bool
	 */
	public function isAiAnalyzeEnabled(): bool
	{
		return $this->enableAiAnalyze;
	}

	/**
	 * Do AI analyze.
	 * @return self
	 */
	public function enableAiAnalyze(): self
	{
		$this->enableAiAnalyze = true;
		return $this;
	}

	/**
	 * Disable AI analyze.
	 * @return self
	 */
	public function disableAiAnalyze(): self
	{
		$this->enableAiAnalyze = false;
		return $this;
	}

	/**
	 * @deprecated Use {@see self::sendInviteUsers()} — it routes by scheme and keeps busy-filter parity across JWT and classic transports.
	 */
	public function inviteUsers(int $senderId, array $toUserIds, $isLegacyMobile, $video = false, $sendPush = true, bool $isRepeated = false): void
	{
		$this->sendInviteUsers(
			senderId: $senderId,
			toUserIds: $toUserIds,
			isLegacyMobile: $isLegacyMobile,
			video: $video,
			sendPush: $sendPush,
			isRepeated: $isRepeated,
		);
	}

	public function sendInviteUsers(
		int $senderId,
		array $toUserIds,
		$isLegacyMobile,
		$video = false,
		$sendPush = true,
		string $sendMode = Signaling::MODE_ALL,
		bool $isRepeated = false,
		?array $usersInOtherCalls = null,
		?int $ttl = null
	): void
	{
		$usersInOtherCalls ??= CallFactory::filterUsersInActiveCalls($toUserIds, $this->getId());

		// Scheme routing keeps legacy classic web/desktop clients working —
		// engine_legacy.js filters pull payload by SCHEME==classic and expects
		// the legacy fields (publicIds/userData/users/invitedUsers). Switching
		// classic calls to sendCallInviteBatch would silently drop incoming UI
		// on those clients. Mobile push is busy-filtered in both branches now.
		if ($this->scheme === self::SCHEME_JWT)
		{
			$this->getSignaling()->sendCallInviteBatch(
				senderId: $senderId,
				toUserIds: $toUserIds,
				isLegacyMobile: $isLegacyMobile,
				video: $video,
				sendPush: $sendPush,
				sendMode: $sendMode,
				isRepeated: $isRepeated,
				usersInOtherCalls: $usersInOtherCalls,
				ttl: $ttl,
			);
			return;
		}

		$this->getSignaling()->sendInvite(
			senderId: $senderId,
			toUserIds: $toUserIds,
			isLegacyMobile: $isLegacyMobile,
			video: $video,
			sendPush: $sendPush,
			isRepeated: $isRepeated,
			usersInOtherCalls: $usersInOtherCalls,
			ttl: $ttl,
		);
	}

	/**
	 * @param string $state
	 */
	public function updateState($state): bool
	{
		if ($this->state == $state)
		{
			return false;
		}
		$prevState = $this->state;
		$this->state = $state;
		$updateResult = CallTable::updateState($this->getId(), $state);
		if (!$updateResult)
		{
			return false;
		}

		if ($this->associatedEntity)
		{
			$this->associatedEntity->onStateChange($state, $prevState);
		}

		return true;
	}

	public function setLogUrl(string $logUrl): void
	{
		$this->logUrl = $logUrl;
	}

	public function setEndpoint($endpoint): void
	{
		$this->endpoint = $endpoint;
	}

	/**
	 * Facade preserved for every caller other than the finish controllers
	 * (Recent cleanup, agents, CallController callbacks, sibling teardown in
	 * terminateAllCallsInChat, onVoximplantConferenceFinished, ...).
	 */
	public function finish(): void
	{
		if ($this->commitFinish())
		{
			$this->finishSideEffects();
		}
	}

	/**
	 * Atomic FINISHED transition — the only work that must run under the
	 * call_state lock. Sets endDate and flips STATE to FINISHED via
	 * `UPDATE ... AND STATE != FINISHED`.
	 *
	 * @return bool true only when this call performed the transition; a
	 *         concurrent finish that already committed returns false (no-op),
	 *         guaranteeing exactly-once side effects. On a lost race the local
	 *         endDate/state are re-synced from the persisted row so a serialized
	 *         response never carries an uncommitted timestamp.
	 */
	public function commitFinish(): bool
	{
		if ($this->endDate instanceof DateTime)
		{
			return false;
		}

		// Set endDate before the transition so the deferred onStateChange() and
		// getCallDuration() (fired in finishSideEffects) read a populated value.
		$this->endDate = new DateTime();
		$prevState = $this->state;

		// Atomic CAS ONLY — no entity side effect. commitFinish() runs under the
		// call_state lock, so the heavy associatedEntity->onStateChange() (chat
		// "call finished" message) is NOT fired here via updateState(); it is
		// deferred to finishSideEffects() (off-lock). This mirrors updateState()'s
		// pure DB CAS without its onStateChange() call.
		if (!CallTable::updateState($this->getId(), static::STATE_FINISHED))
		{
			// Lost the race: a concurrent finish already committed FINISHED. Re-sync
			// state/endDate from the persisted row so a caller serializing this
			// object via toArray() reflects the winner's timestamp, not our local one.
			// Rehydrate WITHOUT initCall() — it would re-fire onCallStarted for a
			// classic/plain call (see loadWithoutInit()).
			$freshCall = ($this->getId()) ? static::loadWithoutInit((int)$this->getId()) : null;
			if ($freshCall instanceof self)
			{
				$this->state = $freshCall->getState();
				$this->endDate = $freshCall->getEndDate();
			}
			else
			{
				$this->endDate = null;
			}

			return false;
		}

		$this->state = static::STATE_FINISHED;
		$this->finishPrevState = $prevState;

		return true;
	}

	/**
	 * Off-lock finish work. Runs strictly after commitFinish() has written the
	 * FINISHED state, so every listener and background job observes a persisted
	 * finished call. Delivery of sendFinish() stays synchronous but off-clock
	 * (outside the lock); heavy stat aggregation and chat sibling teardown are
	 * deferred to background jobs.
	 */
	public function finishSideEffects(): void
	{
		if ($this->getId() && $this->isDeviceScopedRinging())
		{
			// Purge the ringing-device registry for this private 1-1 call. Only
			// device-scoped (private TYPE_INSTANT) calls ever populate it, so group
			// finishes skip the otherwise pointless DELETE ... KEY IN (...) over the
			// full roster. Best-effort: the registry is TTL-evicted anyway, and it
			// must never abort the exactly-once finish teardown that follows
			// (finishOnController / participant updates / Call::finish broadcast) —
			// a storage failure here would otherwise leave the call FINISHED with
			// clients still ringing and no way to replay the one-shot side effects.
			try
			{
				(new RingingDeviceRegistry())->deleteForCall((int)$this->getId(), $this->getUsers());
			}
			catch (\Throwable $e)
			{
				Application::getInstance()->getExceptionHandler()->writeToLog($e);
			}
		}

		$controllerResult = $this->finishOnController();
		if (!$controllerResult->isSuccess() && $this->getId())
		{
			// The room teardown on the media controller failed, but the call is already
			// committed FINISHED — a repeat finish() takes the fast-path and never re-runs
			// this teardown, so the room would leak. Re-attempt off-clock; finishCall() is
			// keyed by UUID and idempotent, so the retry cannot double-finish.
			Application::getInstance()->addBackgroundJob(
				[static::class, 'runRetryFinishOnController'],
				[(int)$this->getId()],
				Application::JOB_PRIORITY_LOW,
			);
		}

		$this->loadUsers();

		// Deferred from commitFinish(): the entity state-change side effect (for a
		// chat — the "call finished" IM message) is a heavy synchronous write and
		// runs here, off the call_state lock. It must precede the CALLING→IDLE
		// transitions below, because the message text depends on participants'
		// finish-time states (e.g. PlainCall not-answered/declined).
		if ($this->associatedEntity)
		{
			$this->associatedEntity->onStateChange(static::STATE_FINISHED, $this->finishPrevState);
		}

		$callingUserIds = [];
		$unavailableUserIds = [];
		foreach ($this->users as $callUser)
		{
			if ($callUser->getState() === CallUser::STATE_CALLING)
			{
				$callingUserIds[] = (int)$callUser->getUserId();
			}
			elseif ($callUser->getState() === CallUser::STATE_UNAVAILABLE)
			{
				$unavailableUserIds[] = (int)$callUser->getUserId();
			}
		}

		$callId = $this->getId();

		// Settle participant states in the DB BEFORE broadcasting finish: a client
		// that receives the Call::finish Pull and immediately queries participant
		// states must not observe still-ringing CALLING rows (the per-user path used
		// to flip them before sendFinish()).
		$transitionedCallingUserIds = [];
		if ($callingUserIds !== [] && $callId)
		{
			// Compare-and-set on the source state: this runs off the call_state lock,
			// so a concurrent decline/hangup/answer may have already moved a user to a
			// terminal state — only flip rows still in CALLING, never clobber that.
			// The returned ids are the users this UPDATE actually moved CALLING→IDLE.
			$transitionedCallingUserIds = CallUserTable::transitionState(
				$callId,
				$callingUserIds,
				CallUser::STATE_IDLE,
				CallUser::STATE_CALLING,
			);

			// Keep the in-memory roster consistent with the bulk UPDATE above —
			// only for the users actually transitioned.
			foreach ($transitionedCallingUserIds as $callingUserId)
			{
				$this->users[$callingUserId]?->setStateInMemory(CallUser::STATE_IDLE);
			}
		}

		$this->getSignaling()->sendFinish();

		if ($callId)
		{
			Application::getInstance()->addBackgroundJob(
				[static::class, 'runSaveStat'],
				[$callId],
				Application::JOB_PRIORITY_LOW,
			);
		}

		// Replay OnCallUserStateChange off-clock for both finish cohorts in a single
		// batch — both become a "missed" b_call_userlog row via CallLogService:
		//   - CALLING→IDLE            — participants who were still ringing;
		//   - UNAVAILABLE→UNAVAILABLE — participants who were never reached.
		// Emit the 'calling:idle' change ONLY for users this finish actually moved
		// CALLING→IDLE (transitionState return), not the whole pre-lock snapshot: a
		// user concurrently answered (CALLING→READY→IDLE) would otherwise be logged
		// as "missed" even though they were reached.
		$changes = [];
		foreach ($transitionedCallingUserIds as $callingUserId)
		{
			$changes[] = [
				'callId' => $callId,
				'userId' => $callingUserId,
				'oldState' => CallUser::STATE_CALLING,
				'newState' => CallUser::STATE_IDLE,
			];
		}
		foreach ($unavailableUserIds as $unavailableUserId)
		{
			$changes[] = [
				'callId' => $callId,
				'userId' => $unavailableUserId,
				'oldState' => CallUser::STATE_UNAVAILABLE,
				'newState' => CallUser::STATE_UNAVAILABLE,
			];
		}

		if ($changes !== [] && $callId)
		{
			Application::getInstance()->addBackgroundJob(
				[EventHandler::class, 'dispatchUserStateChangeEventsBatch'],
				[$changes],
				Application::JOB_PRIORITY_NORMAL,
			);
		}

		$this->fireCallFinishedEvent();
	}

	/**
	 * Hook for provider-specific room teardown on the media controller. Returns the
	 * teardown Result so finishSideEffects() can retry a failed controller call instead
	 * of silently leaving the room open. The base call has no controller room.
	 */
	protected function finishOnController(): \Bitrix\Main\Result
	{
		return new \Bitrix\Main\Result();
	}

	/**
	 * Background-job entry point: best-effort retry of the media-controller room teardown
	 * when {@see self::finishOnController()} failed during finishSideEffects (e.g. the
	 * controller was briefly unavailable). Because the committed FINISHED state makes a
	 * repeat finish() take the fast-path — never re-running the teardown — the room would
	 * otherwise leak. finishCall() is keyed by UUID and idempotent, so this retry cannot
	 * double-finish. Runs after the response is flushed — must stay public static and accept
	 * primitives only (no Call instance). A durable outbox with bounded retries is out of scope.
	 *
	 * @internal
	 */
	public static function runRetryFinishOnController(int $callId): void
	{
		if ($callId <= 0)
		{
			return;
		}

		$call = self::loadWithoutInit($callId);
		if (!$call instanceof Call)
		{
			return;
		}

		$result = $call->finishOnController();
		if (!$result->isSuccess())
		{
			// Still failing after the retry — surface it so a leaked controller room is
			// observable in the log rather than silently lost (the original finding).
			Application::getInstance()->getExceptionHandler()->writeToLog(
				new \Bitrix\Main\SystemException(
					'call: finishOnController retry failed for call ' . $callId . ': '
					. implode('; ', $result->getErrorMessages())
				)
			);
		}
	}

	/**
	 * Background-job entry point for {@see self::saveStat()}. Runs after the
	 * HTTP response is flushed — must stay public static and accept primitives
	 * only (no Call instance), re-reading the call by id so the stats reflect
	 * the persisted finished state.
	 *
	 * @internal
	 */
	public static function runSaveStat(int $callId): void
	{
		if ($callId <= 0)
		{
			return;
		}

		$call = self::loadWithoutInit($callId);
		if (!$call instanceof Call)
		{
			return;
		}

		// Rehydration reconstructs the call without users; saveStat() needs
		// $this->users to be an array (count()/iteration), so load them here.
		$call->loadUsers();
		$call->saveStat();
	}

	/**
	 * @event call:onCallStarted
	 * @return Event
	 */
	protected function fireCallStartedEvent(): Event
	{
		$event = new Event('call', 'onCallStarted', ['call' => $this]);
		$event->send();

		return $event;
	}

	/**
	 * @event call:onCallFinished
	 * @return Event
	 */
	protected function fireCallFinishedEvent(): Event
	{
		$event = new Event('call', 'onCallFinished', ['call' => $this]);
		$event->send();

		return $event;
	}

	public function getConnectionData(int $userId): ?array
	{
		return null;
	}

	public function toArray($currentUserId = 0, $withSecrets = false): array
	{
		$result = [
			'ID' => $this->id,
			'TYPE' => $this->type,
			'SCHEME' => $this->scheme,
			'INITIATOR_ID' => $this->initiatorId,
			'IS_PUBLIC' => $this->isPublic ? 'Y' : 'N',
			'PUBLIC_ID' => $this->publicId,
			'PROVIDER' => $this->provider,
			'ENTITY_TYPE' => $this->entityType,
			'ENTITY_ID' => $this->entityId,
			'PARENT_ID' => $this->parentId,
			'PARENT_UUID' => $this->parentUuid,
			'STATE' => $this->state,
			'START_DATE' => $this->startDate,
			'END_DATE' => $this->endDate,
			'LOG_URL' => $this->logUrl,
			'CHAT_ID' => $this->chatId,
			'ASSOCIATED_ENTITY' => ($this->associatedEntity) ? $this->associatedEntity->toArray($currentUserId) : [],
			'UUID' => $this->uuid,
			'ENDPOINT' => $this->endpoint,
			'RECORD_AUDIO' => $this->enableAudioRecord,
			'AI_ANALYZE' => $this->enableAiAnalyze,
		];
		if ($withSecrets)
		{
			$result['SECRET_KEY'] = $this->secretKey;
		}

		return $result;
	}

	public function save(): void
	{
		$fields = $this->toArray(0, true);
		unset($fields['ID']);

		if (!$this->id)
		{
			$insertResult = CallTable::add($fields);
			$this->id = $insertResult->getId();
		}
		else
		{
			// STATE and END_DATE on an existing call are owned exclusively by the
			// atomic transition (CallTable::updateState via updateState()/commitFinish()).
			// A full-row save() must never write them back: a stale in-memory instance
			// (e.g. STATE=inviting) would otherwise resurrect a call already committed
			// FINISHED by a concurrent lock-free path (lost update). INSERT keeps them -
			// a new row sets its initial STATE/END_DATE intentionally.
			unset($fields['STATE'], $fields['END_DATE']);
			CallTable::update($this->id, $fields);
		}
	}

	public function makeClone($newProvider = null): Call
	{
		$callFields = $this->toArray();
		$callFields['ID'] = null;
		$callFields['PUBLIC_ID'] = randString(10);
		$callFields['STATE'] = static::STATE_NEW;
		$callFields['PROVIDER'] = $newProvider ?? $callFields['PROVIDER'];
		$callFields['PARENT_ID'] = $this->id;

		$instance = CallFactory::createWithArray($callFields['PROVIDER'], $callFields);
		$instance->save();

		$instance->users = [];
		foreach ($this->getUsers() as $userId)
		{
			$instance->users[$userId] = CallUser::create([
				'CALL_ID' => $instance->id,
				'USER_ID' => $userId,
				'STATE' => $instance->users[$userId] ? $instance->users[$userId]->getState() : CallUser::STATE_IDLE,
				'LAST_SEEN' => null
			]);
			$instance->users[$userId]->save();
		}

		return $instance;
	}

	public function createChildCall(
		string $newUuid,
		string $entityId,
		?string $newProvider = null,
		?int $scheme = null,
		?int $newInitiator = null,
	): Call
	{
		$callFields = $this->toArray();
		$callFields['ID'] = null;
		$callFields['UUID'] = $newUuid;
		$callFields['PUBLIC_ID'] = randString(10);
		$callFields['STATE'] = static::STATE_NEW;
		$callFields['PROVIDER'] = $newProvider ?? $callFields['PROVIDER'];
		$callFields['PARENT_ID'] = $this->id;
		$callFields['PARENT_UUID'] = $this->uuid;
		if ($scheme)
		{
			$callFields['SCHEME'] = $scheme;
		}

		if ($newInitiator)
		{
			$callFields['INITIATOR_ID'] = $newInitiator;
		}

		$instance = self::createCallInstance($callFields);

		$instance->associatedEntity = EntityFactory::createEntity($instance, EntityType::CHAT, $entityId);
		$instance->chatId = (int)$instance->associatedEntity->getChatId();
		$instance->entityId = $entityId;

		$instance->save();

		$instance->associatedEntity->onCallCreate();

		$instance->users = [];
		foreach ($this->getUsers() as $userId)
		{
			$instance->users[$userId] = CallUser::create([
				'CALL_ID' => $instance->id,
				'USER_ID' => $userId,
				'STATE' => $instance->users[$userId] ? $instance->users[$userId]->getState() : CallUser::STATE_IDLE,
				'LAST_SEEN' => null
			]);
			$instance->users[$userId]->save();
		}

		return $instance;
	}

	protected function loadUsers(): void
	{
		if (is_array($this->users))
		{
			return;
		}

		$this->users = [];

		$cursor = CallUserTable::getList(array(
			'filter' => array(
				'=CALL_ID' => $this->id
			)
		));

		while($row = $cursor->fetch())
		{
			$this->users[$row['USER_ID']] = CallUser::create($row);
		}
	}

	protected function saveStat()
	{
		$callLength = 0;
		if ($this->startDate instanceof DateTime && $this->endDate instanceof DateTime)
		{
			$callLength = $this->endDate->getTimestamp() - $this->startDate->getTimestamp();
		}
		$userCountChat = count($this->users);

		$usersActive = 0;
		$mobileUsers = 0;
		$externalUsers = 0;
		$screenShared = false;
		$recorded = false;
		$authTypes = UserTable::getList([
			'select' => ['ID', 'EXTERNAL_AUTH_ID'],
			'filter' => ['=ID' => $this->getUsers()]
		])->fetchAll();
		$authTypes = array_column($authTypes, 'EXTERNAL_AUTH_ID', 'ID');
		foreach ($this->users as $userId => $user)
		{
			if ($user->getLastSeen() != null)
			{
				$usersActive++;
			}
			if ($user->isUaMobile())
			{
				$mobileUsers++;
			}
			if ($authTypes[$userId] === Auth::AUTH_TYPE)
			{
				$externalUsers++;
				if ($user->getFirstJoined())
				{
					$userLateness = $user->getFirstJoined()->getTimestamp() - $this->startDate->getTimestamp();
					AddEventToStatFile("im", "im_call_finish", $this->id, $userLateness, "user_lateness", $userId);
				}
			}
			if ($user->wasRecorded())
			{
				$recorded = true;
			}
			if ($user->wasScreenShared())
			{
				$screenShared = true;
			}
		}

		$chatType = null;
		$finishStatus = 'normal';
		if ($this->entityType === EntityType::CHAT)
		{
			if(is_numeric($this->entityId))
			{
				$chatType = 'private';
				// private chat, entity id === other user id
				$otherUserState =
					$this->getUser($this->entityId)
						? $this->getUser($this->entityId)->getState()
						: ''
				;

				if ($otherUserState == CallUser::STATE_DECLINED)
				{
					$finishStatus = 'declined';
				}
				else if ($otherUserState == CallUser::STATE_BUSY)
				{
					$finishStatus = 'busy';
				}
				else if ($otherUserState == CallUser::STATE_UNAVAILABLE || $otherUserState == CallUser::STATE_CALLING)
				{
					$finishStatus = 'unavailable';
				}
			}
			else
			{
				$chatId = Dialog::getChatId($this->entityId);
				$isVideoConf = (bool)AliasTable::getRow([
					'filter' => ['=ENTITY_ID' => $chatId, '=ENTITY_TYPE' => \Bitrix\Im\Alias::ENTITY_TYPE_VIDEOCONF]
				]);
				$chatType = 'group';
			}
		}

		if ($callLength > 30 && $finishStatus === 'normal')
		{
			\Bitrix\Call\Limit::incrementCounter(\Bitrix\Call\Limit::COUNTER_CALL_SUCCESS);
		}

		AddEventToStatFile("im", "im_call_finish", $this->id, $userCountChat, "user_count_chat", 0);
		AddEventToStatFile("im", "im_call_finish", $this->id, $usersActive, "user_count_call", 0);
		AddEventToStatFile("im", "im_call_finish", $this->id, $mobileUsers, "user_count_mobile", 0);
		AddEventToStatFile("im", "im_call_finish", $this->id, $externalUsers, "user_count_external", 0);
		AddEventToStatFile("im", "im_call_finish", $this->id, $callLength, "call_length", 0);
		AddEventToStatFile("im", "im_call_finish", $this->id, ($screenShared ? "Y" : "N"), "screen_shared", 0);
		AddEventToStatFile("im", "im_call_finish", $this->id, ($recorded ? "Y" : "N"), "recorded", 0);
		if($chatType)
		{
			AddEventToStatFile("im","im_call_finish", $this->id, $chatType, "chat_type", 0);
		}
		if (isset($isVideoConf))
		{
			AddEventToStatFile("im","im_call_finish", $this->id, ($isVideoConf ? "Y" : "N"), "is_videoconf", 0);
		}
		AddEventToStatFile("im","im_call_finish", $this->id, $finishStatus, "status", 0);
	}

	public function getMaxUsers(): int
	{
		return Settings::getMaxParticipants();
	}

	public function getLogToken(int $userId = 0, int $ttl = 3600) : string
	{
		$userId = $userId ?: $this->getCurrentUserId();
		if(!$userId)
		{
			return  '';
		}

		$portalId = parse_url(Library::getPortalPublicUrl(), PHP_URL_HOST);
		if (empty($portalId))
		{
			return '';
		}

		$secret = Option::get('call', 'call_log_secret');
		if (empty($secret))
		{
			return '';
		}

		return JWT::encode(
			[
				'prt' => $portalId,
				'call' => $this->getId(),
				'usr' => $userId,
				'exp' => (new DateTime())->getTimestamp() + $ttl
			],
			$secret
		);
	}

	/**
	 * @deprecated use {@see \Bitrix\Call\Settings::getMaxParticipants} instead
	 */
	public static function getMaxParticipants(): int
	{
		return Settings::getMaxParticipants();
	}

	/**
	 * @deprecated use {@see \Bitrix\Call\Settings::getMaxCallServerParticipants} instead
	 */
	public static function getMaxCallServerParticipants(): int
	{
		return Settings::getMaxCallServerParticipants();
	}

	/**
	 * Use this constructor only for creating new calls
	 */
	public static function createWithEntity(
		int $type,
		string $provider,
		string $entityType,
		string $entityId,
		int $initiatorId,
		?string $callUuid,
		?int $scheme = null
	): Call
	{
		$instance = new static();
		$instance->type = $type;
		$instance->initiatorId = $initiatorId;
		$instance->provider = $provider;
		$instance->entityType = $entityType;
		$instance->entityId = $entityId;
		$instance->uuid = !$callUuid && $provider === self::PROVIDER_PLAIN ? Util::generateUUID() : $callUuid;
		$instance->startDate = new DateTime();
		$instance->publicId = randString(10);
		$instance->state = static::STATE_NEW;

		Loader::includeModule('call');

		if ($scheme && in_array($scheme, [self::SCHEME_CLASSIC, self::SCHEME_JWT], true))
		{
			$instance->scheme = $scheme;
		}
		else
		{
			$instance->scheme = self::SCHEME_CLASSIC;
			if (
				\Bitrix\Call\Settings::isNewCallsEnabled()
				&& (
					$instance->provider === self::PROVIDER_BITRIX
					|| ($instance->provider === self::PROVIDER_PLAIN && \Bitrix\Call\Settings::isPlainCallsUseNewScheme())
				)
			)
			{
				$instance->scheme = self::SCHEME_JWT;
			}
		}

		$instance->associatedEntity = EntityFactory::createEntity($instance, $entityType, $entityId);
		$instance->chatId = (int)$instance->associatedEntity->getChatId();

		$instance->enableAudioRecord = $instance->autoStartRecording();
		$instance->enableAiAnalyze = $instance->enableAudioRecord;

		$instance->save();

		// todo: remove when the calls are supported in the mobile
		$instance->associatedEntity->onCallCreate();

		$instance->users = [];
		$entityUserIds = [];
		foreach ($instance->associatedEntity->getUsers() as $userId)
		{
			$userId = (int)$userId;
			if ($userId > 0 && !isset($entityUserIds[$userId]))
			{
				$entityUserIds[$userId] = true;
			}
		}
		$entityUserIds = array_keys($entityUserIds);

		// Single INSERT IGNORE for the whole chat membership instead of N
		// per-user CallUserTable::merge() calls — for chat with 142 users
		// this collapses 142 INSERTs into 1.
		if ($entityUserIds !== [])
		{
			$instance->bulkInsertCallUsers($entityUserIds, CallUser::STATE_UNAVAILABLE);
			foreach ($entityUserIds as $userId)
			{
				$instance->users[$userId] = CallUser::create([
					'CALL_ID' => $instance->id,
					'USER_ID' => $userId,
					'STATE' => CallUser::STATE_UNAVAILABLE,
					'LAST_SEEN' => null,
				]);
			}
		}

		$instance->initCall();

		self::sendCreateCallEvent($instance);

		return $instance;
	}

	protected static function sendCreateCallEvent(Call $instance): void
	{
		$event = new Event(
			'im',
			'onCallCreate',
			[
				'id' => $instance->id,
				'type' => $instance->type,
				'scheme' => $instance->scheme,
				'initiatorId' => $instance->initiatorId,
				'provider' => $instance->provider,
				'entityType' => $instance->entityType,
				'entityId' => $instance->entityId,
				'startDate' => $instance->startDate,
				'publicId' => $instance->publicId,
				'chatId' => $instance->chatId,
			]
		);
		$event->send();
	}

	protected function initCall(): void
	{
		if (!Loader::includeModule('call'))
		{
			return;
		}

		if ($this->scheme === self::SCHEME_JWT)
		{
			return;
		}

		$this->fireCallStartedEvent();
	}

	/**
	 * Creates new instance of the Call with values from the database.
	 *
	 * @param array $fields Call fields
	 * @return Call
	 */
	public static function createWithArray(array $fields): Call
	{
		$instance = self::createCallInstance($fields);

		$instance->initCall();

		return $instance;
	}

	/**
	 * Creates new instance of the Call with values from the database.
	 *
	 * @param array $fields Call fields
	 * @return Call
	 */
	public static function createCallInstance(array $fields): Call
	{
		$instance = new static();

		$instance->id = $fields['ID'];
		$instance->type = (int)$fields['TYPE'];
		$instance->initiatorId = (int)$fields['INITIATOR_ID'];
		$instance->isPublic = $fields['IS_PUBLIC'];
		$instance->publicId = $fields['PUBLIC_ID'];
		$instance->provider = $fields['PROVIDER'];
		$instance->entityType = $fields['ENTITY_TYPE'];
		$instance->entityId = $fields['ENTITY_ID'];
		$instance->startDate = isset($fields['START_DATE']) && $fields['START_DATE'] instanceof DateTime ? $fields['START_DATE'] : null;
		$instance->endDate = isset($fields['END_DATE']) && $fields['END_DATE'] instanceof DateTime ? $fields['END_DATE'] : null;
		$instance->parentId = (int)$fields['PARENT_ID'] ?: null;
		$instance->parentUuid = $fields['PARENT_UUID'] ?: null;
		$instance->state = $fields['STATE'];
		$instance->logUrl = $fields['LOG_URL'];
		$instance->chatId = (int)$fields['CHAT_ID'];
		$instance->uuid = $fields['UUID'];
		$instance->secretKey = $fields['SECRET_KEY'];
		$instance->endpoint = $fields['ENDPOINT'];

		$instance->scheme = self::SCHEME_CLASSIC;

		Loader::includeModule('call');

		if (isset($fields['SCHEME']))
		{
			$instance->scheme = (int)($fields['SCHEME'] ?: self::SCHEME_CLASSIC);
		}
		elseif (
			\Bitrix\Call\Settings::isNewCallsEnabled()
			&& (
				$instance->provider === self::PROVIDER_BITRIX
				|| ($instance->provider === self::PROVIDER_PLAIN && \Bitrix\Call\Settings::isPlainCallsUseNewScheme())
			)
		)
		{
			$instance->scheme = self::SCHEME_JWT;
		}

		if ($instance->entityType && $instance->entityId)
		{
			$instance->associatedEntity = EntityFactory::createEntity($instance, $instance->entityType, $instance->entityId);
		}

		if (isset($fields['RECORD_AUDIO']))
		{
			$instance->enableAudioRecord = ($fields['RECORD_AUDIO'] === 'Y');
		}
		else
		{
			$instance->enableAudioRecord = $instance->autoStartRecording();
		}

		if (isset($fields['AI_ANALYZE']))
		{
			$instance->enableAiAnalyze = ($fields['AI_ANALYZE'] === 'Y');
		}
		else
		{
			$instance->enableAiAnalyze = $instance->enableAudioRecord;
		}

		return $instance;
	}

	public static function loadWithId($id): ?Call
	{
		$row = CallTable::getRowById($id);
		if (is_array($row))
		{
			return static::createWithArray($row);
		}

		return null;
	}

	/**
	 * Rehydrate a call from its persisted row WITHOUT running initCall().
	 */
	protected static function loadWithoutInit(int $id): ?Call
	{
		$row = CallTable::getRowById($id);
		if (!is_array($row))
		{
			return null;
		}

		return CallFactory::getCallInstance((string)$row['PROVIDER'], $row);
	}

	public static function loadWithUuid($uuid): ?Call
	{
		$row = CallTable::getList([
			'select' => array_keys(CallTable::getEntity()->getScalarFields()),
			'filter' => ['=UUID' => $uuid],
			'limit' => 1,
		])->fetch();
		if (is_array($row))
		{
			return static::createWithArray($row);
		}

		return null;
	}

	/**
	 * @deprecated use {@see \Bitrix\Call\Settings::isCallServerEnabled} instead
	 */
	public static function isCallServerEnabled(): bool
	{
		return Settings::isCallServerEnabled();
	}

	protected function getCurrentUserId() : int
	{
		return $GLOBALS['USER'] ? (int)$GLOBALS['USER']->getId() : 0;
	}

	public static function onVoximplantConferenceFinished(Event $event): void
	{
		$callId = $event->getParameter('CONFERENCE_CALL_ID');
		$logUrl = $event->getParameter('LOG_URL');
		if (!$logUrl)
		{
			return;
		}

		$call = Call::loadWithId($callId);
		if (!$call)
		{
			return;
		}
		$call->finish();
		$call->setLogUrl($logUrl);
		$call->save();
	}
}
