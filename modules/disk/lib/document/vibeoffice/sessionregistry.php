<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Vibeoffice;

use Bitrix\Disk\Document\Models\DocumentService;
use Bitrix\Disk\Document\Models\DocumentSession;
use Bitrix\Disk\Document\Models\DocumentSessionTable;
use Bitrix\Disk\Document\Vibeoffice\Models\DocumentStateTable;
use Bitrix\Disk\Document\Vibeoffice\Models\PlatformSessionTable;
use Bitrix\Disk\File;
use Bitrix\Disk\Internals\FileTable;
use Bitrix\Main\Application;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Error;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\UuidGenerator;

class SessionRegistry
{
	/** A refusal of the registry the open path cannot repeat its way out of. */
	public const ERROR_CODE_FAILED = 'vibeoffice_registry_failed';

	/**
	 * A refusal the document is merely busy with: the metadata of the open could not be written
	 * while a save of the same document held the session context. Before the retry cycle such a
	 * write was best-effort and never failed an open, so it is answered with the screen of the
	 * branch of saving instead of the generic failure.
	 */
	public const ERROR_CODE_BUSY = 'vibeoffice_registry_busy';

	/** The local budget of an unresolved attempt ran out; the attempt is retained, not replaced. */
	public const ERROR_CODE_OPEN_EXPIRED = 'open_operation_expired';

	/**
	 * The latest attempt of the local session is not resolved: a send of it may still reach the platform,
	 * it was stopped, or its reopen belongs to another caller. No new identity and no new send is made.
	 */
	public const ERROR_CODE_OPEN_UNRESOLVED = 'open_operation_unresolved';

	private const MAX_EVENT_SESSIONS = 2000;
	/**
	 * The bound of attempt states one local session keeps in its CONTEXT: visitors of a public link share
	 * it, and the column must not outgrow its type (TEXT of MySQL) whatever their number.
	 */
	private const MAX_OPEN_ATTEMPTS = 64;
	private const OPEN_TOKEN_MAX_AGE_SECONDS = 7 * 24 * 3600;
	private const PARTICIPANT_PATTERN = '/^[a-f0-9]{64}$/D';

	private ?ClientOriginProviderInterface $clientOriginProvider;
	/** @var \WeakMap<Result, array>|null */
	private ?\WeakMap $openRefusalDiagnostics = null;

	public function __construct(
		private readonly DocumentStateRepository $stateRepository = new DocumentStateRepository(),
		?ClientOriginProviderInterface $clientOriginProvider = null,
	)
	{
		$this->clientOriginProvider = $clientOriginProvider;
	}

	public function getOpenRowsBySessionIds(array $documentSessionIds): Result
	{
		$ids = [];
		foreach ($documentSessionIds as $id)
		{
			if (is_int($id) && $id > 0)
			{
				$ids[$id] = $id;
			}
		}
		$rowsBySessionId = array_fill_keys($ids, []);
		if (!$ids)
		{
			return (new Result())->setData(['rowsBySessionId' => $rowsBySessionId]);
		}

		try
		{
			if (DocumentStateRepository::hasOpenTransaction())
			{
				return $this->failure('An open transaction prevents a current registry read.');
			}
			$pool = Application::getInstance()->getConnectionPool();
			$pool->useMasterOnly(true);
			try
			{
				$connection = Application::getConnection();
				foreach (array_chunk($ids, 300) as $chunk)
				{
					$query = PlatformSessionTable::query()
						->setSelect(SharedEditingSchema::sessionFields())
						->whereIn('DOCUMENT_SESSION_ID', $chunk)
					;
					$rows = $connection->query($query->getQuery());
					while ($row = $rows->fetch())
					{
						$id = (int)$row['DOCUMENT_SESSION_ID'];
						if (!array_key_exists($id, $rowsBySessionId))
						{
							return $this->failure('Invalid registry session binding.');
						}
						$rowsBySessionId[$id][] = $row;
					}
				}
			}
			finally
			{
				$pool->useMasterOnly(false);
			}
		}
		catch (\Throwable)
		{
			return $this->failure('The current registry state is unavailable.');
		}

		return (new Result())->setData(['rowsBySessionId' => $rowsBySessionId]);
	}

	public static function findMatchingBinding(DocumentSession $session): ?array
	{
		if ((int)$session->getId() <= 0 || !SharedEditingSchema::isReady())
		{
			return null;
		}

		$rows = Models\PlatformSessionTable::getList([
			'select' => SharedEditingSchema::sessionFields(),
			'filter' => ['=DOCUMENT_SESSION_ID' => (int)$session->getId()],
			'order' => ['ID' => 'DESC'],
		])->fetchAll();
		foreach ($rows as $row)
		{
			if (SharedSessionScope::matchesBinding($session, $row))
			{
				return $row;
			}
		}

		return null;
	}

	public static function hasSharedBindingRecord(DocumentSession $session): bool
	{
		if ((int)$session->getId() <= 0 || !SharedEditingSchema::isReady())
		{
			return false;
		}

		$rows = Models\PlatformSessionTable::getList([
			'select' => ['ACCESS_SOURCE_TYPE', 'PLATFORM_USER_ID', 'ACCESS_CONTEXT'],
			'filter' => ['=DOCUMENT_SESSION_ID' => (int)$session->getId()],
			'limit' => 50,
		])->fetchAll();
		foreach ($rows as $row)
		{
			if (($row['ACCESS_SOURCE_TYPE'] ?? null) !== null
				|| ($row['PLATFORM_USER_ID'] ?? null) !== null
				|| ($row['ACCESS_CONTEXT'] ?? null) !== null)
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * Returns 'internal-unified' or 'native' when the session has a matching v1 binding that
	 * requires own edit authorization, or null when no own edit authorization is required.
	 */
	public static function requiredOwnEditAuthorization(DocumentSession $session): ?string
	{
		$row = self::findMatchingBinding($session);
		if ($row === null || !in_array($row['ACCESS_SOURCE_TYPE'] ?? null, ['internal', 'unified'], true))
		{
			return null;
		}
		$access = SharedSessionScope::access($row);
		$confirmations = $access['context']['confirmationFingerprints'] ?? [];
		$hasUnifiedLinkFingerprint = is_array($confirmations) && array_key_exists('unifiedLink', $confirmations);
		if (($row['ACCESS_SOURCE_TYPE'] ?? null) === 'unified' && !$hasUnifiedLinkFingerprint)
		{
			$linkId = (int)($row['ACCESS_SOURCE_ID'] ?? 0);
			$link = $linkId > 0 ? \Bitrix\Disk\ExternalLink::loadById($linkId) : null;
			$opening = $link
				? \Bitrix\Disk\Internal\Service\UnifiedLink\ShortLinkOpening::resolve((string)$link->getShortCode())
				: null;
			if ($opening !== null
				&& (int)$opening->getExternalLink()->getId() === $linkId
				&& $opening->allowsEditBySource())
			{
				return null;
			}
		}

		return $hasUnifiedLinkFingerprint ? 'internal-unified' : 'native';
	}

	/**
	 * Resume an ambiguous live attempt of the same local session, or reserve a new one.
	 *
	 * The data of a success is ['openToken' => string, 'clientOrigin' => string|null]: the origin is
	 * the snapshot taken with the token, never the current setting.
	 *
	 * The participant ({@see self::PARTICIPANT_PATTERN}) names whose identity the open carries when several
	 * visitors share one local session, as the guests of a public link do: each of them resumes and is gated
	 * by its own latest attempt only. Without it the latest attempt of the whole session is the gate.
	 */
	public function beginOrResumeOpen(DocumentSession $session, string $hostDocKey, ?string $participant = null): Result
	{
		return $this->reserveOpen(
			$session,
			$hostDocKey,
			static fn(DocumentSession $fresh): bool => $fresh->isActive(),
			participant: $participant,
		);
	}

	/**
	 * Reserve once before the HTTP retry loop. No network calls may run inside repository locks.
	 * An unresolved attempt of the session is resumed instead of competing with a new identity.
	 */
	public function beginOpen(DocumentSession $session, string $hostDocKey, ?string $participant = null): Result
	{
		return $this->reserveOpen(
			$session,
			$hostDocKey,
			static fn(DocumentSession $fresh): bool => !$fresh->isNonActive(),
			participant: $participant,
		);
	}

	/**
	 * The one reopen a trusted 409 open_session_closed grants: the owner issued by the finalization of the
	 * closed attempt is presented and consumed together with the new token.
	 */
	public function beginReopen(
		DocumentSession $session,
		string $hostDocKey,
		string $owner,
		?string $participant = null,
	): Result
	{
		return $this->reserveOpen(
			$session,
			$hostDocKey,
			static fn(DocumentSession $fresh): bool => $fresh->isActive(),
			$owner,
			$participant,
		);
	}

	/**
	 * @param callable(DocumentSession): bool $isOpenable
	 */
	private function reserveOpen(
		DocumentSession $session,
		string $hostDocKey,
		callable $isOpenable,
		?string $owner = null,
		?string $participant = null,
	): Result
	{
		if (!$session->getId() || HostDocumentKey::resolve($session) !== $hostDocKey)
		{
			return $this->failure('Invalid Vibeoffice open binding.');
		}
		if ($participant !== null && preg_match(self::PARTICIPANT_PATTERN, $participant) !== 1)
		{
			return $this->failure('Invalid Vibeoffice open participant.');
		}

		$binding = $this->stateRepository->ensureSessionState($session);
		if (!$binding->isSuccess())
		{
			return $binding;
		}
		if ($session->isEdit())
		{
			$state = $this->stateRepository->getByKey($hostDocKey);
			if ($state && !$this->stateRepository->isInCurrentNamespace($state))
			{
				return $this->failure('Vibeoffice platform changed.', 'vibeoffice_namespace_changed');
			}
		}

		return $this->stateRepository->withLockedDocument(
			$hostDocKey,
			function (array $state) use ($session, $hostDocKey, $isOpenable, $owner, $participant): Result {
				if ((int)$state['SESSION_TYPE'] === DocumentSession::TYPE_EDIT && (int)$state['VERSION_ID'] === 0
					&& ((int)($state['EDIT_SCOPE_VERSION'] ?? 0) !== 0
						|| $this->stateRepository->isLegacyEditBlocked((int)$state['OBJECT_ID'])))
				{
					return $this->failure('A fresh editing admission is required.', self::ERROR_CODE_BUSY);
				}
				$query = DocumentSessionTable::query()->setSelect(['*'])->where('ID', $session->getId());
				$row = $this->fetchLocked($query);
				$fresh = $row ? DocumentSession::buildFromArray($row) : null;
				if (!$fresh || !$isOpenable($fresh) || HostDocumentKey::resolve($fresh) !== $hostDocKey)
				{
					return $this->failure('Vibeoffice session is no longer active.');
				}

				$vo = DocumentSessionManager::extractVoMetadata($fresh);
				if (OpenAttemptState::isFloorCorrupt($vo))
				{
					return $this->unresolved('Vibeoffice open attempt floor is unknown.');
				}
				$latest = $this->resolveLatestAttempt($vo, $participant);
				if ($latest === false)
				{
					return $this->unresolved('Vibeoffice open participants are unknown.');
				}
				$pending = $this->selectPending($hostDocKey, (int)$fresh->getId(), $vo, $participant);
				if ($owner === null && $latest !== null)
				{
					$recovered = $this->tryRecoverClosedOpen($fresh, $hostDocKey, $vo, $latest, $pending, $participant);
					if ($recovered !== null)
					{
						return $recovered;
					}
				}
				$replacing = false;
				if ($pending)
				{
					if ($owner !== null || !$this->canReplaceExpiredUnsentOpen($fresh, $pending, $vo))
					{
						return $owner === null
							? $this->resumePendingOpen($fresh, $pending, $vo)
							: $this->unresolved('Vibeoffice reopen found an unresolved attempt.')
						;
					}
					$delete = PlatformSessionTable::delete($pending['ID']);
					if (!$delete->isSuccess())
					{
						return (new Result())->addErrors($delete->getErrors());
					}
					$replacing = true;
				}

				if ($replacing && ($latest['token'] ?? null) !== $pending['OPEN_TOKEN'])
				{
					return $this->unresolved('Vibeoffice open attempt is not resolved.');
				}
				$gate = $this->checkLastAttempt($vo, $owner, $latest);
				if (!$gate->isSuccess())
				{
					if ($replacing)
					{
						return $gate;
					}
					$bound = $owner === null && $latest !== null
						? $this->findReplayableBoundAttempt($fresh, $hostDocKey, $vo, $latest)
						: null
					;

					return $bound === null ? $gate : $this->resumeBoundOpen($bound, $vo);
				}

				if ($owner !== null)
				{
					$generation = $this->validateOpenGeneration($fresh);
					if (!$generation->isSuccess())
					{
						return $generation;
					}
				}

				return $this->reserveNewOpen(
					$fresh, $hostDocKey, $vo, $gate->getData()['consume'], $participant,
					sessionRowLocked: $owner !== null,
				);
			},
		);
	}

	private function tryRecoverClosedOpen(
		DocumentSession $fresh,
		string $hostDocKey,
		array $vo,
		array $latest,
		?array $pending,
		?string $participant,
	): ?Result
	{
		$token = $latest['token'];
		if (
			$token === null
			|| $this->normalizeUuid($token) === null
			|| !$fresh->isActive()
			|| ($pending !== null && $pending['OPEN_TOKEN'] !== $token)
			|| (
				(int)$fresh->getExternalLinkId() > 0
				&& ($participant === null || (OpenAttemptState::readParticipantMap($vo)[$participant] ?? null) !== $token)
			)
		)
		{
			return null;
		}

		$state = $this->readAttemptState($vo, $token);
		if (!$this->isRecoverableClosedState($state))
		{
			return null;
		}

		$binding = $this->fetchLocked(PlatformSessionTable::query()
			->setSelect(['ID', 'OPEN_TOKEN', 'STATUS', 'API_SESSION_ID', 'HOST_DOC_KEY', 'DOCUMENT_SESSION_ID', 'CREATE_TIME'])
			->where('OPEN_TOKEN', $token));
		$isPending = $binding && $binding['STATUS'] === PlatformSessionTable::STATUS_PENDING
			&& $binding['API_SESSION_ID'] === null;
		if ($binding && (
			$binding['HOST_DOC_KEY'] !== $hostDocKey
			|| (int)$binding['DOCUMENT_SESSION_ID'] !== (int)$fresh->getId()
			|| (!$isPending && (
				!in_array($binding['STATUS'], [
					PlatformSessionTable::STATUS_ACTIVE, PlatformSessionTable::STATUS_TERMINATED,
				], true)
				|| $this->normalizeUuid($binding['API_SESSION_ID']) === null
			))
		))
		{
			return $this->unresolved('Vibeoffice closed open binding does not match.');
		}
		if ($state['outstanding'] > 0)
		{
			if (!$binding)
			{
				return $this->unresolved('Vibeoffice closed open binding is missing.');
			}
			if (
				!$binding['CREATE_TIME'] instanceof DateTime
				|| time() - $binding['CREATE_TIME']->getTimestamp() >= self::OPEN_TOKEN_MAX_AGE_SECONDS
			)
			{
				return $this->expiredAttemptRefusal($binding, $binding['STATUS'], $vo);
			}
		}

		$generation = $this->validateOpenGeneration($fresh);
		if (!$generation->isSuccess())
		{
			return $generation;
		}
		if ($isPending)
		{
			$delete = PlatformSessionTable::delete($binding['ID']);
			if (!$delete->isSuccess())
			{
				return (new Result())->addErrors($delete->getErrors());
			}
		}

		return $this->reserveNewOpen(
			$fresh, $hostDocKey, $vo, $token, $participant, $state['outstanding'], sessionRowLocked: true,
		);
	}

	private function isRecoverableClosedState(mixed $state): bool
	{
		return OpenAttemptState::isValid($state)
			&& $state['stop']
			&& $state['candidate'] === OpenAttemptState::CANDIDATE_CLOSED
			&& $state['fingerprint'] !== null
			&& !$state['consumed']
			&& ($state['finalized'] ? is_string($state['owner']) : $state['outstanding'] > 0)
		;
	}

	private function validateOpenGeneration(DocumentSession $session): Result
	{
		if (!$this->stateRepository->isCurrentOpenGeneration($session))
		{
			return $this->failure('Vibeoffice open generation changed.', 'open_identity_mismatch');
		}

		return new Result();
	}

	/**
	 * The pending attempt the open resumes: without a participant the latest one of the session; with it
	 * the own latest attempt of the participant, else an attempt no participant is known for (legacy).
	 * Pending attempts of other participants are theirs alone. Each of them is a single locked row. A pending
	 * row above the floor of the session whose state was given up by the bound is never resumed.
	 */
	private function selectPending(string $hostDocKey, int $sessionId, array $vo, ?string $participant): ?array
	{
		$floor = OpenAttemptState::readFloor($vo);
		$query = static fn(): Query => PlatformSessionTable::query()
			->setSelect(['ID', 'OPEN_TOKEN', 'CREATE_TIME', 'API_SESSION_ID'])
			->where('HOST_DOC_KEY', $hostDocKey)
			->where('DOCUMENT_SESSION_ID', $sessionId)
			->where('STATUS', PlatformSessionTable::STATUS_PENDING)
			->whereNotNull('OPEN_TOKEN')
			->setOrder(['ID' => 'DESC'])
			->setLimit(1)
		;
		if ($participant === null)
		{
			$latest = $query();
			if ($floor !== null)
			{
				$resumable = Query::filter()->logic('or')->where('ID', '<', $floor);
				$stated = array_map('strval', array_keys(OpenAttemptState::readMap($vo) ?? []));
				if ($stated !== [])
				{
					$resumable->whereIn('OPEN_TOKEN', $stated);
				}
				$latest->where($resumable);
			}

			return $this->fetchLocked($latest);
		}
		$participants = OpenAttemptState::readParticipantMap($vo) ?? [];
		$own = $participants[$participant] ?? null;
		if (is_string($own) && $own !== '')
		{
			$row = $this->fetchLocked($query()->where('OPEN_TOKEN', $own));
			if ($row)
			{
				return $row;
			}
		}
		$legacy = $query();
		if ($floor !== null)
		{
			$legacy->where('ID', '<', $floor);
		}
		$known = array_map('strval', array_keys(OpenAttemptState::participantTokenSet($participants)));
		if ($known !== [])
		{
			$legacy->whereNotIn('OPEN_TOKEN', $known);
		}

		return $this->fetchLocked($legacy);
	}

	private function canReplaceExpiredUnsentOpen(DocumentSession $fresh, array $pending, array $vo): bool
	{
		if (
			!$fresh->isActive()
			|| $pending['API_SESSION_ID'] !== null
			|| !$pending['CREATE_TIME'] instanceof DateTime
			|| time() - $pending['CREATE_TIME']->getTimestamp() < self::OPEN_TOKEN_MAX_AGE_SECONDS
		)
		{
			return false;
		}
		$attempts = OpenAttemptState::readMap($vo);
		$state = $attempts[(string)$pending['OPEN_TOKEN']] ?? null;
		$initialState = OpenAttemptState::create();
		if (!is_array($state) || count($state) !== count($initialState))
		{
			return false;
		}
		foreach ($initialState as $field => $value)
		{
			if (!array_key_exists($field, $state) || $state[$field] !== $value)
			{
				return false;
			}
		}

		return true;
	}

	/**
	 * The latest attempt the gate of the open checks: the own latest attempt of a known participant; for a
	 * participant new to the session nothing (null) when the latest attempt of the session is another
	 * participant's, the latest attempt of the session otherwise. False when the participants are unknown.
	 *
	 * @return array{token: ?string, present: bool}|false|null
	 */
	private function resolveLatestAttempt(array $vo, ?string $participant): array|false|null
	{
		$session = [
			'token' => OpenAttemptState::readLastToken($vo),
			'present' => array_key_exists(DocumentSessionManager::CONTEXT_VO_LAST_OPEN_TOKEN_KEY, $vo),
		];
		if ($participant === null)
		{
			return $session;
		}
		$participants = OpenAttemptState::readParticipantMap($vo);
		if ($participants === null)
		{
			return false;
		}
		if (array_key_exists($participant, $participants))
		{
			$token = $participants[$participant];

			return ['token' => is_string($token) && $token !== '' ? $token : null, 'present' => true];
		}

		$known = OpenAttemptState::participantTokenSet($participants);

		return $session['token'] !== null && isset($known[$session['token']])
			? null
			: $session
		;
	}

	/**
	 * Gate of the latest attempt, checked before any new identity even when no pending row is left: its
	 * debt, its stop and its reopen owner cannot be bypassed. The data names the token whose owner (or
	 * expired finalization) the new reservation consumes.
	 *
	 * @param array{token: ?string, present: bool}|null $latest null for a participant new to the session
	 */
	private function checkLastAttempt(array $vo, ?string $owner, ?array $latest): Result
	{
		$map = OpenAttemptState::readMap($vo);
		$lastToken = $latest['token'] ?? null;
		$hasLastKey = $latest['present'] ?? false;
		if (!$hasLastKey && $map !== null)
		{
			return $owner === null
				? (new Result())->setData(['consume' => null])
				: $this->unresolved('Vibeoffice reopen owner is unknown.')
			;
		}
		$state = $map !== null && $lastToken !== null ? ($map[$lastToken] ?? null) : null;
		if (
			!OpenAttemptState::isValid($state)
			|| $state['outstanding'] > 0
			|| ($state['stop'] && !$state['finalized'])
		)
		{
			return $this->unresolved('Vibeoffice open attempt is not resolved.');
		}
		if (!$state['finalized'])
		{
			return $owner === null
				? (new Result())->setData(['consume' => null])
				: $this->unresolved('Vibeoffice reopen owner is unknown.')
			;
		}
		if ($state['consumed'])
		{
			return $this->unresolved('Vibeoffice open attempt was already reopened.');
		}
		if ($state['candidate'] === OpenAttemptState::CANDIDATE_CLOSED)
		{
			return $owner !== null && $state['owner'] !== null && hash_equals($state['owner'], $owner)
				? (new Result())->setData(['consume' => $lastToken])
				: $this->unresolved('Vibeoffice reopen belongs to another caller.')
			;
		}

		// A remote expiry ends the call that saw it; only the next independent open takes the owner.
		return $owner === null
			? (new Result())->setData(['consume' => $lastToken])
			: $this->unresolved('Vibeoffice reopen owner is unknown.')
		;
	}

	/**
	 * The outcome of a sent token is unknown, so the token is kept for good: a replay before the
	 * local budget runs out repeats its snapshot, after it the open is refused without a new identity.
	 */
	private function resumePendingOpen(DocumentSession $fresh, array $pending, array $vo): Result
	{
		if (
			$pending['CREATE_TIME'] instanceof DateTime
			&& time() - $pending['CREATE_TIME']->getTimestamp() >= self::OPEN_TOKEN_MAX_AGE_SECONDS
		)
		{
			return $this->expiredAttemptRefusal($pending, PlatformSessionTable::STATUS_PENDING, $vo);
		}

		$token = (string)$pending['OPEN_TOKEN'];
		$state = $this->readAttemptState($vo, $token);
		if ($state === false || (is_array($state) && $state['stop']))
		{
			return $this->attemptRefusal(
				'Vibeoffice open attempt is stopped or its state is unknown.',
				self::ERROR_CODE_OPEN_UNRESOLVED,
				$state === false ? 'attempt_state_unknown' : 'attempt_stopped',
				PlatformSessionTable::STATUS_PENDING,
				$state,
			);
		}
		$origins = $vo[DocumentSessionManager::CONTEXT_VO_CLIENT_ORIGINS_KEY] ?? null;
		$origin = is_array($origins) && is_string($origins[$token] ?? null) ? $origins[$token] : null;

		return (new Result())->setData(['openToken' => $token, 'clientOrigin' => $origin]);
	}

	/**
	 * The latest attempt of the local session that was answered with a config but still owes sends (an
	 * answer over an untrusted transport writes nothing off): it stays the identity of the session, so a
	 * reload replays its token instead of creating a new one. Null for anything else.
	 */
	private function findReplayableBoundAttempt(
		DocumentSession $fresh,
		string $hostDocKey,
		array $vo,
		array $latest,
	): ?array
	{
		$token = $latest['token'];
		$state = $token === null ? false : $this->readAttemptState($vo, $token);
		if (
			!is_array($state)
			|| $state['outstanding'] === 0
			|| $state['stop']
			|| $state['finalized']
			|| $state['fingerprint'] === null
		)
		{
			return null;
		}
		$row = $this->fetchLocked(PlatformSessionTable::query()
			->setSelect(['ID', 'OPEN_TOKEN', 'CREATE_TIME', 'API_SESSION_ID'])
			->where('OPEN_TOKEN', $token)
			->where('HOST_DOC_KEY', $hostDocKey)
			->where('DOCUMENT_SESSION_ID', $fresh->getId())
			->where('STATUS', PlatformSessionTable::STATUS_ACTIVE)
			->whereNotNull('API_SESSION_ID'));

		return $row ?: null;
	}

	/**
	 * Replays the bound token with its own snapshot: the same identity, the same local budget as a pending
	 * replay, and every send is admitted against the fingerprint of the first one.
	 */
	private function resumeBoundOpen(array $bound, array $vo): Result
	{
		if (
			!$bound['CREATE_TIME'] instanceof DateTime
			|| time() - $bound['CREATE_TIME']->getTimestamp() >= self::OPEN_TOKEN_MAX_AGE_SECONDS
		)
		{
			return $this->expiredAttemptRefusal($bound, PlatformSessionTable::STATUS_ACTIVE, $vo);
		}
		$token = (string)$bound['OPEN_TOKEN'];
		$origins = $vo[DocumentSessionManager::CONTEXT_VO_CLIENT_ORIGINS_KEY] ?? null;
		$origin = is_array($origins) && is_string($origins[$token] ?? null) ? $origins[$token] : null;

		return (new Result())->setData(['openToken' => $token, 'clientOrigin' => $origin]);
	}

	/** Reserves a new identity only within the repository's own durable admission transaction. */
	public function reserveSharedOpenLocked(DocumentSession $session, array $state, array $access): Result
	{
		$key = (string)($state['HOST_DOC_KEY'] ?? '');
		if (!$this->stateRepository->isSharedAdmissionLocked($key, $access)
			|| (int)($state['EDIT_SCOPE_VERSION'] ?? 0) !== 1
			|| (int)($state['OBJECT_ID'] ?? 0) !== (int)$session->getObjectId()
			|| !$session->isEdit() || $session->isVersion() || !$session->isActive()
			|| HostDocumentKey::resolve($session) !== $key)
		{
			return $this->failure('A durable editing admission is required.', self::ERROR_CODE_BUSY);
		}
		$fresh = $this->fetchLockedSession((int)$session->getId());
		if (!$fresh || HostDocumentKey::resolve($fresh) !== $key)
		{
			return $this->failure('Invalid editing channel.');
		}
		$vo = DocumentSessionManager::extractVoMetadata($fresh);
		$participant = $access['contextHash'];
		$latest = $this->resolveLatestAttempt($vo, $participant);
		if ($latest === false || OpenAttemptState::isFloorCorrupt($vo))
		{
			return $this->unresolved('Vibeoffice open participants are unknown.');
		}
		$gate = $this->checkLastAttempt($vo, null, $latest);
		if (!$gate->isSuccess())
		{
			return $gate;
		}
		if (!SharedEditingUsage::isPersisted())
		{
			return $this->failure('Could not persist editing maintenance state.', self::ERROR_CODE_BUSY);
		}
		$subject = 'vp1_' . bin2hex(random_bytes(16));
		$expiresAt = $access['expiresAt'];
		if ($expiresAt !== null)
		{
			$expiresAt = clone $expiresAt;
			$expiresAt->setTimeZone(new \DateTimeZone('UTC'));
		}
		$reserved = $this->reserveNewOpen($fresh, $key, $vo, $gate->getData()['consume'], $participant, identity: [
			'PLATFORM_USER_ID' => $subject, 'ACTOR_USER_ID' => $access['actorUserId'],
			'ACCESS_SOURCE_TYPE' => $access['sourceType'], 'ACCESS_SOURCE_ID' => $access['sourceId'],
			'ACCESS_CONTEXT_HASH' => $access['contextHash'],
			'ACCESS_CONTEXT' => \Bitrix\Main\Web\Json::encode($access['context']),
			'ACCESS_EXPIRES_AT' => $expiresAt, 'ACCESS_REVOKE_STATE' => 0,
		]);
		if ($reserved->isSuccess())
		{
			$reserved->setData($reserved->getData() + ['subject' => $subject]);
		}
		return $reserved;
	}

	private function reserveNewOpen(
		DocumentSession $fresh,
		string $hostDocKey,
		array $vo,
		?string $consume,
		?string $participant,
		?int $closedRecoveryOutstanding = null,
		bool $sessionRowLocked = false,
		array $identity = [],
	): Result
	{
		$liveRows = $this->findLiveRows(array_keys(OpenAttemptState::readMap($vo) ?? []));
		$liveTokens = array_keys($liveRows);
		$refusal = null;
		$evicted = [];
		$origin = $this->getClientOriginProvider()->getOrigin();
		$token = UuidGenerator::generateV4();
		$add = PlatformSessionTable::add($identity + [
			'HOST_DOC_KEY' => $hostDocKey,
			'DOCUMENT_SESSION_ID' => $fresh->getId(),
			'OPEN_TOKEN' => $token,
			'STATUS' => PlatformSessionTable::STATUS_PENDING,
		]);
		if (!$add->isSuccess())
		{
			return (new Result())->addErrors($add->getErrors());
		}
		// A failed snapshot fails the whole reservation: the transaction drops the row with it.
		$manager = new DocumentSessionManager();
		if (!$manager->persistClientOriginSnapshot($fresh, $token, $origin, $sessionRowLocked))
		{
			return $this->failure('Could not persist Vibeoffice origin snapshot.', self::ERROR_CODE_BUSY);
		}
		$rowId = (int)$add->getId();
		$recorded = $manager->persistOpenAttemptTransition(
			$fresh,
			function (array $vo) use (
				$token,
				$rowId,
				$consume,
				$participant,
				$closedRecoveryOutstanding,
				$liveTokens,
				&$refusal,
				&$evicted,
			): ?array {
				$evicted = [];
				$map = OpenAttemptState::readMap($vo);
				$participants = OpenAttemptState::readParticipantMap($vo);
				if ($map === null || $participants === null || array_key_exists($token, $map))
				{
					return null;
				}
				if ($consume !== null)
				{
					$previous = $map[$consume] ?? null;
					if (
						!OpenAttemptState::isValid($previous)
						|| $previous['consumed']
						|| ($this->resolveLatestAttempt($vo, $participant)['token'] ?? null) !== $consume
					)
					{
						return null;
					}
					if ($closedRecoveryOutstanding !== null)
					{
						if (!$this->isRecoverableClosedState($previous) || $previous['outstanding'] !== $closedRecoveryOutstanding)
						{
							return null;
						}
						$previous['outstanding'] = 0;
						$previous['finalized'] = true;
					}
					elseif (!$previous['finalized'])
					{
						return null;
					}
					$previous['consumed'] = true;
					$map[$consume] = $previous;
				}
				$map[$token] = OpenAttemptState::create();
				if ($participant !== null)
				{
					$participants[$participant] = $token;
				}

				// The attempt consumed right now stays readable until the next reservation.
				$kept = $liveTokens;
				if ($consume !== null)
				{
					$kept[] = $consume;
				}
				if (!array_key_exists(DocumentSessionManager::CONTEXT_VO_OPEN_ATTEMPT_FLOOR_KEY, $vo))
				{
					$vo[DocumentSessionManager::CONTEXT_VO_OPEN_ATTEMPT_FLOOR_KEY] = $rowId;
				}
				elseif (OpenAttemptState::isFloorCorrupt($vo))
				{
					$refusal = 'Vibeoffice open attempt floor is unknown.';

					return null;
				}
				$vo = $this->boundAttempts(
					$this->pruneResolvedAttempts($vo, $map, $participants, $token, $kept),
					array_diff($liveTokens, [$token, $consume]),
					$evicted,
				);
				if ($vo === null)
				{
					$refusal = 'Vibeoffice local session holds too many unresolved open attempts.';
				}

				return $vo;
			},
			sessionRowLocked: $sessionRowLocked,
		);
		if ($refusal !== null)
		{
			return $this->unresolved($refusal);
		}
		if (!$recorded)
		{
			return $this->failure('Could not persist Vibeoffice open attempt state.', self::ERROR_CODE_BUSY);
		}
		// The rows were locked by findLiveRows after the session row, so the deletion takes no new lock.
		foreach ($evicted as $evictedToken)
		{
			$evictedRow = $liveRows[$evictedToken] ?? null;
			if ($evictedRow === null || $evictedRow['STATUS'] !== PlatformSessionTable::STATUS_PENDING)
			{
				continue;
			}
			$delete = PlatformSessionTable::delete($evictedRow['ID']);
			if (!$delete->isSuccess())
			{
				return (new Result())->addErrors($delete->getErrors());
			}
		}

		$data = ['openToken' => $token, 'clientOrigin' => $origin];
		if ($closedRecoveryOutstanding !== null)
		{
			$data['closedRecoveryOutstanding'] = $closedRecoveryOutstanding;
		}

		return (new Result())->setData($data);
	}

	/**
	 * Writes the new latest attempt and drops attempts nothing can reach any more, so the vo metadata stays
	 * bounded by what is still alive: an attempt without debt, stop or owner pending, that is neither the
	 * latest of the session or of a participant nor held by a pending or active registry row, goes together
	 * with its origin snapshot. A participant whose latest attempt is resolved that way is forgotten: its
	 * next open is a new identity, as it would be anyway. Anything unknown or in debt stays.
	 *
	 * @param string[] $liveTokens tokens of pending and active registry rows, and any other token to keep
	 */
	private function pruneResolvedAttempts(
		array $vo,
		array $map,
		array $participants,
		string $token,
		array $liveTokens,
	): array
	{
		$live = array_fill_keys($liveTokens, true);
		$live[$token] = true;
		$isResolved = static fn(mixed $attempt): bool => !isset($live[$attempt])
			&& OpenAttemptState::isValid($map[$attempt] ?? null)
			&& !OpenAttemptState::hasDebt($map[$attempt])
		;
		foreach ($participants as $participant => $attempt)
		{
			if (is_string($attempt) && $isResolved($attempt))
			{
				unset($participants[$participant]);
			}
		}
		$kept = $live + array_fill_keys(array_filter($participants, 'is_string'), true);
		$origins = $vo[DocumentSessionManager::CONTEXT_VO_CLIENT_ORIGINS_KEY] ?? null;
		foreach (array_keys($map) as $attempt)
		{
			$attempt = (string)$attempt;
			if (!isset($kept[$attempt]) && $isResolved($attempt))
			{
				unset($map[$attempt]);
				if (is_array($origins))
				{
					unset($origins[$attempt]);
				}
			}
		}

		$vo[DocumentSessionManager::CONTEXT_VO_OPEN_ATTEMPTS_KEY] = $map;
		$vo[DocumentSessionManager::CONTEXT_VO_LAST_OPEN_TOKEN_KEY] = $token;
		$participantsKey = DocumentSessionManager::CONTEXT_VO_PARTICIPANT_OPEN_TOKENS_KEY;
		if ($participants !== [] || array_key_exists($participantsKey, $vo))
		{
			$vo[$participantsKey] = $participants;
		}
		if (is_array($origins))
		{
			$vo[DocumentSessionManager::CONTEXT_VO_CLIENT_ORIGINS_KEY] = $origins;
		}

		return $vo;
	}

	/**
	 * Keeps at most {@see self::MAX_OPEN_ATTEMPTS} attempt states. Over the bound the oldest states without
	 * debt go first, with the participants they are the latest attempt of: such a state owes no send, and its
	 * participant opens with a new identity. The caller drops the pending row of a state given up that way: no
	 * send of it is outstanding, so it protects nothing. A consumed closure with no latest pointer can
	 * also be dropped, keeping any UUID binding. A state with debt is never dropped.
	 *
	 * @param string[] $evictable tokens of pending and active registry rows
	 * @param string[] $evicted receives the tokens whose states were given up
	 */
	private function boundAttempts(array $vo, array $evictable, array &$evicted = []): ?array
	{
		$map = $vo[DocumentSessionManager::CONTEXT_VO_OPEN_ATTEMPTS_KEY];
		if (count($map) <= self::MAX_OPEN_ATTEMPTS)
		{
			return $vo;
		}
		$evictable = array_fill_keys($evictable, true);
		$participantsKey = DocumentSessionManager::CONTEXT_VO_PARTICIPANT_OPEN_TOKENS_KEY;
		$participants = is_array($vo[$participantsKey] ?? null) ? $vo[$participantsKey] : [];
		$byToken = [];
		foreach ($participants as $participant => $attempt)
		{
			if (is_string($attempt))
			{
				$byToken[$attempt][] = $participant;
			}
		}
		$origins = $vo[DocumentSessionManager::CONTEXT_VO_CLIENT_ORIGINS_KEY] ?? null;
		foreach (array_keys($map) as $attempt)
		{
			if (count($map) <= self::MAX_OPEN_ATTEMPTS)
			{
				break;
			}
			$attempt = (string)$attempt;
			$state = $map[$attempt];
			if (!OpenAttemptState::isValid($state) || OpenAttemptState::hasDebt($state))
			{
				continue;
			}
			$canDropClosed = $state['candidate'] === OpenAttemptState::CANDIDATE_CLOSED
				&& $state['finalized']
				&& $state['consumed']
				&& !isset($byToken[$attempt])
				&& $attempt !== OpenAttemptState::readLastToken($vo);
			if (!$canDropClosed && ($state['stop'] || !isset($evictable[$attempt])))
			{
				continue;
			}
			unset($map[$attempt]);
			$evicted[] = $attempt;
			if (is_array($origins))
			{
				unset($origins[$attempt]);
			}
			foreach ($byToken[$attempt] ?? [] as $participant)
			{
				unset($participants[$participant]);
			}
		}
		if (count($map) > self::MAX_OPEN_ATTEMPTS)
		{
			return null;
		}
		$vo[DocumentSessionManager::CONTEXT_VO_OPEN_ATTEMPTS_KEY] = $map;
		if (array_key_exists($participantsKey, $vo))
		{
			$vo[$participantsKey] = $participants;
		}
		if (is_array($origins))
		{
			$vo[DocumentSessionManager::CONTEXT_VO_CLIENT_ORIGINS_KEY] = $origins;
		}

		return $vo;
	}

	/**
	 * Locked pending or active registry rows (ID and STATUS) of the given tokens, by token.
	 *
	 * @param array<int|string> $tokens
	 * @return array<string, array>
	 */
	private function findLiveRows(array $tokens): array
	{
		$tokens = array_values(array_filter(array_map('strval', $tokens), static fn(string $t): bool => $t !== ''));
		$live = [];
		foreach (array_chunk($tokens, 500) as $chunk)
		{
			$rows = $this->queryLocked(PlatformSessionTable::query()
				->setSelect(['ID', 'OPEN_TOKEN', 'STATUS'])
				->whereIn('OPEN_TOKEN', $chunk)
				->whereIn('STATUS', [PlatformSessionTable::STATUS_PENDING, PlatformSessionTable::STATUS_ACTIVE]));
			foreach ($rows as $row)
			{
				$live[(string)$row['OPEN_TOKEN']] = $row;
			}
		}

		return $live;
	}

	/**
	 * Admits one send of the token: under the document lock the attempt must still be pending, inside its
	 * local budget and not stopped; the first send binds the fingerprint, every later one must repeat it
	 * while a send is outstanding or the token is bound. A pending attempt without debt rebinds it.
	 * The outstanding counter is committed before the caller may touch the network.
	 */
	/** Restores the committed admission and fixes the first wire body before any send right. */
	public function prepareSharedOpen(DocumentSession $session, array $body): Result
	{
		if (DocumentStateRepository::hasOpenTransaction() || DocumentStateRepository::holdsAnyLock())
		{
			return $this->failure('An opening requires its own transaction.', self::ERROR_CODE_BUSY);
		}
		$key = HostDocumentKey::resolve($session);
		$result = $this->stateRepository->withLockedDocument($key, function (array $state, ?File $lockedFile) use ($session, $body): Result {
			$fresh = $this->fetchLockedSession((int)$session->getId());
			if (SharedSessionScope::version($state) !== 1 || !$fresh
				|| $fresh->getExternalHash() !== $session->getExternalHash())
			{
				return $this->failure('Invalid shared opening binding.');
			}
			$vo = DocumentSessionManager::extractVoMetadata($fresh);
			$token = OpenAttemptState::readLastToken($vo);
			$row = $token ? $this->fetchLocked(PlatformSessionTable::query()
				->setSelect(SharedEditingSchema::sessionFields())->where('OPEN_TOKEN', $token)) : null;
			if (!$row || !SharedSessionScope::matchesBinding($fresh, $row))
			{
				return $this->failure('Invalid shared opening attempt.');
			}
			$checked = $this->checkSharedAccessLocked($row, $state, $lockedFile);
			if (!$checked->isSuccess() || !($checked->getData()['allowed'] ?? false))
			{
				return $checked;
			}
			$origins = $vo[DocumentSessionManager::CONTEXT_VO_CLIENT_ORIGINS_KEY] ?? [];
			if (!is_array($origins) || !array_key_exists($token, $origins))
			{
				return $this->unresolved('Missing opening origin.');
			}
			$body['user']['id'] = $row['PLATFORM_USER_ID'];
			$body['host_open_id'] = $token;
			unset($body['grant']['expires_at'], $body['client']);
			if ($row['ACCESS_EXPIRES_AT'] instanceof DateTime)
			{
				$body['grant']['expires_at'] = gmdate('Y-m-d\TH:i:s\Z', $row['ACCESS_EXPIRES_AT']->getTimestamp());
			}
			if (is_string($origins[$token]))
			{
				$body['client']['origin'] = $origins[$token];
			}
			$bodies = $vo[SharedOpenRequest::CONTEXT_KEY] ?? [];
			if (!is_array($bodies) || count($bodies) > SharedOpenRequest::MAX_ATTEMPTS)
			{
				return $this->unresolved('Invalid opening request snapshots.');
			}
			if (array_key_exists($token, $bodies))
			{
				$saved = $bodies[$token];
				if (!is_array($saved) || ($saved['version'] ?? null) !== 1 || !is_array($saved['body'] ?? null)
					|| !SharedOpenRequest::isValid($saved['body'])
					|| $saved['body']['host_open_id'] !== $token
					|| $saved['body']['user']['id'] !== $body['user']['id']
					|| ($saved['body']['grant'] ?? null) !== $body['grant']
					|| ($saved['body']['document']['permissions'] ?? null) !== $body['document']['permissions'])
				{
					return $this->unresolved('The opening request cannot be replayed.');
				}
				$body = $saved['body'];
			}
			else
			{
				$attempt = $this->readAttemptState($vo, $token);
				if (!is_array($attempt) || $attempt['fingerprint'] !== null
					|| count($bodies) >= SharedOpenRequest::MAX_ATTEMPTS || !SharedOpenRequest::isValid($body))
				{
					return $this->unresolved('The opening request cannot be recorded.');
				}
				$bodies[$token] = ['version' => 1, 'body' => $body];
				if (!(new DocumentSessionManager())->mergeVoMetadataLocked($fresh,
					static function (array $current) use ($bodies): array {
						$current[SharedOpenRequest::CONTEXT_KEY] = $bodies;

						return $current;
					}))
				{
					return $this->unresolved('Could not persist the opening request.');
				}
			}

			return (new Result())->setData(['allowed' => true, 'openToken' => $token,
				'clientOrigin' => $origins[$token], 'body' => $body]);
		});

		return $this->publicSharedResult($result);
	}

	public function validateSharedConfig(string $openToken): Result
	{
		// A public denial must never cause an enclosing transaction to undo the recorded revoke.
		if (DocumentStateRepository::hasOpenTransaction() || DocumentStateRepository::holdsAnyLock())
		{
			return $this->failure('Config validation requires its own transaction.', self::ERROR_CODE_BUSY);
		}
		$result = $this->withOpenToken($openToken, function (array $row, array $state, ?File $lockedFile): Result {
			return $state && SharedSessionScope::version($state) === 1
				? $this->checkSharedAccessLocked($row, $state, $lockedFile)
				: $this->failure('Invalid shared configuration.');
		});

		return $this->publicSharedResult($result);
	}

	private function publicSharedResult(Result $result): Result
	{
		return $result->isSuccess() && ($result->getData()['allowed'] ?? true) === false
			? $this->failure('The editing source is unavailable.', 'vibeoffice_access_denied') : $result;
	}

	/** A successful denied outcome commits its pending revoke; callers expose errors after commit. */
	private function checkSharedAccessLocked(array $row, array $state, ?File $file): Result
	{
		$denied = (new Result())->setData(['allowed' => false]);
		$session = $this->fetchLockedSession((int)$row['DOCUMENT_SESSION_ID']);
		$access = SharedSessionScope::access($row);
		$checker = new SharedEditingAccess();
		if (!$session || !$access || !SharedSessionScope::matchesBinding($session, $row)
			|| !$checker->isCurrentVisitor($access))
		{
			return $denied;
		}
		$verified = $file ? $checker->revalidate($file, $access) : null;
		$fresh = $verified?->getData() ?? [];
		if ((int)$row['ACCESS_REVOKE_STATE'] !== 0
			|| !in_array($row['STATUS'], [PlatformSessionTable::STATUS_PENDING, PlatformSessionTable::STATUS_ACTIVE], true)
			|| !$session->isActive())
		{
			return $denied;
		}
		$expiry = $access['expiresAt']?->getTimestamp();
		$freshExpiry = ($fresh['expiresAt'] ?? null)?->getTimestamp();
		if (!$file || $file->isDeleted() || !$verified?->isSuccess()
			|| ($fresh['contextHash'] ?? null) !== $access['contextHash']
			|| ($freshExpiry !== null && ($expiry === null || $freshExpiry < $expiry))
			|| (int)$state['CONFLICTED'] !== 0
			|| !$this->stateRepository->isCurrentGeneration($session, $file))
		{
			$updated = PlatformSessionTable::update($row['ID'], [
				'ACCESS_REVOKE_STATE' => 1, 'REVOKE_RETRY_AT' => new DateTime(), 'UPDATE_TIME' => new DateTime(),
			] + $this->requestedTimeFields($row));

			return $updated->isSuccess() ? $denied : (new Result())->addErrors($updated->getErrors());
		}

		return (new Result())->setData(['allowed' => true]);
	}

	public function admitOpenSend(string $openToken, string $fingerprint): Result
	{
		if (preg_match('/^[a-f0-9]{64}$/D', $fingerprint) !== 1)
		{
			return $this->unresolved('Invalid Vibeoffice open fingerprint.');
		}

		$outerTransaction = DocumentStateRepository::hasOpenTransaction() || DocumentStateRepository::holdsAnyLock();
		$result = $this->withOpenToken($openToken, function (array $row, array $document, ?File $lockedFile) use (
			$openToken, $fingerprint, $outerTransaction,
		): Result {
			if ($document && (int)$document['SESSION_TYPE'] === DocumentSession::TYPE_EDIT
				&& (int)$document['VERSION_ID'] === 0
				&& (int)($document['EDIT_SCOPE_VERSION'] ?? 0) === 0
				&& $this->stateRepository->hasSharedEditMarker((int)$document['OBJECT_ID']))
			{
				return $this->failure('The legacy editing room is closed.', self::ERROR_CODE_BUSY);
			}
			$shared = $document && SharedSessionScope::version($document) === 1;
			if ($document && !in_array(SharedSessionScope::version($document), [0, 1], true))
			{
				return $this->failure('Unknown editing scope version.');
			}
			if ($shared)
			{
				if ($outerTransaction)
				{
					return $this->failure('Send admission requires its own transaction.', self::ERROR_CODE_BUSY);
				}
				$checked = $this->checkSharedAccessLocked($row, $document, $lockedFile);
				if (!$checked->isSuccess() || !$checked->getData()['allowed'])
				{
					return $checked;
				}
			}
			$status = $row['STATUS'];
			$bound = $status === PlatformSessionTable::STATUS_ACTIVE && $row['API_SESSION_ID'] !== null;
			if ($status !== PlatformSessionTable::STATUS_PENDING && !$bound)
			{
				return $this->attemptRefusal(
					'Vibeoffice open attempt is no longer pending.',
					self::ERROR_CODE_OPEN_UNRESOLVED,
					'attempt_not_openable',
					$status,
				);
			}
			if (
				!$row['CREATE_TIME'] instanceof DateTime
				|| time() - $row['CREATE_TIME']->getTimestamp() >= self::OPEN_TOKEN_MAX_AGE_SECONDS
			)
			{
				return $this->expiredAttemptRefusal($row, $status);
			}
			$session = $this->fetchLockedSession((int)$row['DOCUMENT_SESSION_ID']);
			if (!$session || !$session->isActive())
			{
				return $this->attemptRefusal(
					'Vibeoffice session is no longer active.',
					self::ERROR_CODE_FAILED,
					'session_inactive',
					$status,
				);
			}
			$sessionVo = DocumentSessionManager::extractVoMetadata($session);
			$state = $this->readAttemptState($sessionVo, $openToken);
			if ($state === false || ($state === null && !OpenAttemptState::isLegacyRow($sessionVo, (int)$row['ID'])))
			{
				return $this->attemptRefusal(
					'Vibeoffice open attempt state is unknown.',
					self::ERROR_CODE_OPEN_UNRESOLVED,
					'attempt_state_unknown',
					$status,
				);
			}
			$firstSend = $status === PlatformSessionTable::STATUS_PENDING
				&& is_array($state)
				&& !$state['stop']
				&& $state['fingerprint'] === null
				&& $state['outstanding'] === 0
			;
			if ($firstSend)
			{
				$generation = $this->validateOpenGeneration($session);
				if (!$generation->isSuccess())
				{
					return $generation;
				}
			}
			// A bound token is replayed only as the latest attempt of its session, and only once its first
			// send bound the fingerprint: anything else would be a send no reservation stands for.
			if ($bound && (
				!is_array($state)
				|| $state['fingerprint'] === null
				|| $state['finalized']
				|| !OpenAttemptState::isLatestToken($sessionVo, $openToken)
			))
			{
				return $this->attemptRefusal(
					'Vibeoffice open attempt is no longer pending.',
					self::ERROR_CODE_OPEN_UNRESOLVED,
					'attempt_not_openable',
					$status,
					$state,
				);
			}
			$right = new OpenSendRight(
				$openToken,
				(string)$row['HOST_DOC_KEY'],
				(int)$session->getId(),
				$state !== null,
			);
			if ($state === null)
			{
				$invalidated = $this->stateRepository->invalidateTerminalProof((string)$row['HOST_DOC_KEY']);

				return $invalidated->isSuccess() ? (new Result())->setData(['right' => $right]) : $invalidated;
			}

			$refusal = null;
			$transitionFailure = null;
			$admitted = (new DocumentSessionManager())->persistOpenAttemptTransition(
				$session,
				function (array $vo) use ($openToken, $fingerprint, $bound, $status, $shared, &$refusal): ?array {
					$state = $this->readAttemptState($vo, $openToken);
					if (!is_array($state))
					{
						$refusal = $this->attemptRefusal(
							'Vibeoffice open attempt state is unknown.',
							self::ERROR_CODE_OPEN_UNRESOLVED,
							'attempt_state_unknown',
							$status,
						);

						return null;
					}
					if ($state['stop'] || $state['outstanding'] >= OpenAttemptState::MAX_OUTSTANDING)
					{
						$refusal = $this->attemptRefusal(
							'Vibeoffice open attempt admits no more sends.',
							self::ERROR_CODE_OPEN_UNRESOLVED,
							$state['stop'] ? 'attempt_stopped' : 'outstanding_limit_reached',
							$status,
							$state,
						);

						return null;
					}
					// A pending attempt without debt owes no send, so a changed request may follow it under the
					// same token and origin snapshot; while a send is outstanding the first fingerprint binds.
					if (
						$state['fingerprint'] !== null
						&& !hash_equals($state['fingerprint'], $fingerprint)
						&& ($shared || $bound || $state['outstanding'] > 0)
					)
					{
						$refusal = $this->attemptRefusal(
							'Vibeoffice open request differs from the first send of its token.',
							self::ERROR_CODE_OPEN_UNRESOLVED,
							'fingerprint_mismatch',
							$status,
							$state,
							fingerprintMatches: false,
						);

						return null;
					}
					$state['fingerprint'] = $fingerprint;
					$state['outstanding']++;
					$vo[DocumentSessionManager::CONTEXT_VO_OPEN_ATTEMPTS_KEY][$openToken] = $state;

					return $vo;
				},
				$transitionFailure,
				sessionRowLocked: $firstSend,
			);
			if (!$admitted)
			{
				return $refusal ?? $this->attemptRefusal(
					'Could not admit Vibeoffice open send.',
					self::ERROR_CODE_BUSY,
					$transitionFailure ?? 'registry_transition_failed',
					$status,
				);
			}

			$invalidated = $this->stateRepository->invalidateTerminalProof((string)$row['HOST_DOC_KEY']);

			return $invalidated->isSuccess() ? (new Result())->setData(['right' => $right]) : $invalidated;
		});

		return $this->publicSharedResult($result);
	}

	/**
	 * The once-finalizer of an admitted send. The right is consumed before the first DB access, so a
	 * failed or unknown commit is never repeated: the debt then stays for good.
	 *
	 * A trusted completed transport of this very send, or a proof that it never left the host, writes off
	 * its own debt; a trusted allow pair also stops the attempt. Once stopped with no debt left, the attempt
	 * is finalized in the same transaction: its pending row is dropped, and a closed attempt issues the
	 * single owner of its reopen.
	 *
	 * The data carries configAllowed (the attempt was not stopped before this answer), finalized, candidate,
	 * owner and sessionIds of the finalization.
	 *
	 * A success over an untrusted transport keeps the opening compatible: it writes off the debt of its own
	 * send unless the attempt is stopped, so it never stops, finalizes or grants a reopen.
	 *
	 * @param string|null $candidate {@see OpenAttemptState::CANDIDATE_CLOSED} or CANDIDATE_EXPIRED of a
	 *        trusted allow pair, null otherwise
	 */
	public function settleOpenSend(
		OpenSendRight $right,
		bool $writeOff,
		?string $candidate = null,
		bool $untrustedSuccess = false,
	): Result
	{
		if (!$right->consume())
		{
			return $this->failure('Vibeoffice open send was already settled.');
		}
		$settled = [
			'configAllowed' => true,
			'finalized' => false,
			'candidate' => OpenAttemptState::CANDIDATE_NONE,
			'owner' => null,
			'sessionIds' => [],
		];
		if (!$right->isAccounted())
		{
			return (new Result())->setData($settled);
		}
		if ($candidate !== null && $candidate !== OpenAttemptState::CANDIDATE_CLOSED
			&& $candidate !== OpenAttemptState::CANDIDATE_EXPIRED)
		{
			return $this->failure('Invalid Vibeoffice open outcome.');
		}

		return $this->stateRepository->withLockedDocument(
			$right->getHostDocKey(),
			function (array $document) use ($right, $writeOff, $candidate, $untrustedSuccess, $settled): Result {
				$binding = SharedSessionScope::version($document) === 1
					? $this->fetchLocked(PlatformSessionTable::query()
						->setSelect(['ID', 'ACCESS_REVOKE_STATE'])->where('OPEN_TOKEN', $right->getOpenToken()))
					: null;
				$revoked = $binding && (int)$binding['ACCESS_REVOKE_STATE'] !== 0;
				$session = $this->fetchLockedSession($right->getDocumentSessionId());
				if (!$session)
				{
					return $this->unresolved('Vibeoffice open attempt carrier is missing.');
				}
				$token = $right->getOpenToken();
				$transition = null;
				$applied = (new DocumentSessionManager())->persistOpenAttemptTransition(
					$session,
					function (array $vo) use ($token, $writeOff, $candidate, $untrustedSuccess, $revoked, &$transition): ?array {
						$state = $this->readAttemptState($vo, $token);
						if (!is_array($state))
						{
							return null;
						}
						$before = $state;
						$writeOff = $writeOff || ($untrustedSuccess && $candidate === null && !$state['stop']);
						if ($writeOff && $state['outstanding'] > 0)
						{
							$state['outstanding']--;
						}
						if ($candidate !== null && !$state['finalized'])
						{
							$state['stop'] = true;
							$state['candidate'] = OpenAttemptState::raiseCandidate($state['candidate'], $candidate);
						}
						if ($state['stop'] && $state['outstanding'] === 0 && !$state['finalized'])
						{
							$state['finalized'] = true;
							if (!$revoked && $state['candidate'] === OpenAttemptState::CANDIDATE_CLOSED)
							{
								$state['owner'] = bin2hex(random_bytes(16));
							}
						}
						$transition = [$before, $state];
						$vo[DocumentSessionManager::CONTEXT_VO_OPEN_ATTEMPTS_KEY][$token] = $state;

						return $vo;
					},
				);
				if (!$applied || $transition === null)
				{
					return $this->unresolved('Vibeoffice open attempt state could not be settled.');
				}
				[$before, $after] = $transition;
				$settled['revoked'] = $revoked;
				$settled['configAllowed'] = !$revoked && !$before['stop'];
				if ($revoked && (int)$binding['ACCESS_REVOKE_STATE'] === 1)
				{
					$retry = PlatformSessionTable::update($binding['ID'], ['REVOKE_RETRY_AT' => new DateTime()]);
					if (!$retry->isSuccess())
					{
						return (new Result())->addErrors($retry->getErrors());
					}
				}
				$settled['candidate'] = $after['candidate'];
				if ($before['finalized'] || !$after['finalized'])
				{
					return (new Result())->setData($settled);
				}

				$settled['finalized'] = true;
				$settled['owner'] = $after['owner'];
				$pending = $this->fetchLocked(PlatformSessionTable::query()
					->setSelect(array_values(array_intersect(['ID', 'STATUS', 'ACCESS_REVOKE_STATE'],
						SharedEditingSchema::sessionFields())))
					->where('OPEN_TOKEN', $token));
				if ($pending && $pending['STATUS'] === PlatformSessionTable::STATUS_PENDING
					&& (int)($pending['ACCESS_REVOKE_STATE'] ?? 0) === 0)
				{
					$delete = PlatformSessionTable::delete($pending['ID']);
					if (!$delete->isSuccess())
					{
						return (new Result())->addErrors($delete->getErrors());
					}
				}
				$settled['sessionIds'] = $this->eligibleSessionIds([$right->getDocumentSessionId()]);

				return (new Result())->setData($settled);
			},
		);
	}

	/**
	 * The state of the token: an array for an attempt reserved with a state, null for a legacy attempt
	 * reserved before it, false when the state is missing or corrupt although it must exist.
	 */
	private function readAttemptState(array $vo, string $token): array|false|null
	{
		$map = OpenAttemptState::readMap($vo);
		if ($map === null)
		{
			return false;
		}
		if (!array_key_exists($token, $map))
		{
			return OpenAttemptState::readLastToken($vo) === $token ? false : null;
		}

		return OpenAttemptState::isValid($map[$token]) ? $map[$token] : false;
	}

	private function fetchLockedSession(int $sessionId): ?DocumentSession
	{
		$row = $this->fetchLocked(DocumentSessionTable::query()->setSelect(['*'])->where('ID', $sessionId));

		return $row ? DocumentSession::buildFromArray($row) : null;
	}

	private function getClientOriginProvider(): ClientOriginProviderInterface
	{
		return $this->clientOriginProvider ??= new ConfiguredClientOriginProvider(
			ServiceLocator::getInstance()->get('disk.vibeofficeConfiguration'),
		);
	}

	/** Returns terminal=true and eligible sessionIds when a close won the race with open. */
	public function completeOpen(string $openToken, array $metadata): Result
	{
		$uuid = $this->normalizeUuid($metadata['session_id'] ?? null);
		$editorKey = $metadata['editor_key'] ?? null;
		$rev = $metadata['rev'] ?? null;
		if (!$uuid || !$this->isEditorKey($editorKey) || !$this->isRevision($rev))
		{
			return $this->failure('Invalid Vibeoffice open metadata.');
		}

		return $this->withOpenToken($openToken, function (array $pending, array $document, ?File $lockedFile) use ($uuid, $editorKey, $rev): Result {
			$hostDocKey = $pending['HOST_DOC_KEY'];
			$sessionId = (int)$pending['DOCUMENT_SESSION_ID'];
			$shared = $document && SharedSessionScope::version($document) === 1;
			$revoked = $shared && (int)$pending['ACCESS_REVOKE_STATE'] !== 0;
			if (!in_array($pending['STATUS'], [
				PlatformSessionTable::STATUS_PENDING, PlatformSessionTable::STATUS_REFUSED,
			], true) && !($revoked && $pending['API_SESSION_ID'] === null))
			{
				if ($pending['API_SESSION_ID'] !== $uuid)
				{
					return $this->failure('Vibeoffice open was already completed with another UUID.');
				}

				return $this->completionResult($pending, $sessionId);
			}

			$query = DocumentSessionTable::query()->setSelect(['*'])->where('ID', $sessionId);
			$sessionRow = $this->fetchLocked($query);
			$session = $sessionRow ? DocumentSession::buildFromArray($sessionRow) : null;
			if ((!$session || !$session->isActive()) && !$revoked)
			{
				return $this->failure('Vibeoffice session is no longer active.');
			}

			$known = $this->findUuid($uuid);
			if ($known && (
				$known['HOST_DOC_KEY'] !== $hostDocKey
				|| ((int)$known['DOCUMENT_SESSION_ID'] > 0 && (int)$known['DOCUMENT_SESSION_ID'] !== $sessionId)
			))
			{
				return $this->failure('Vibeoffice UUID is already bound to another session.');
			}

			$identity = [];
			if ($shared)
			{
				$access = SharedSessionScope::access($pending);
				if (($session && !SharedSessionScope::matchesBinding($session, $pending))
					|| !$access || ($access['context']['objectId'] ?? null) !== (int)$document['OBJECT_ID'])
				{
					return $this->failure('Invalid shared completion binding.');
				}
				foreach (['PLATFORM_USER_ID', 'ACTOR_USER_ID', 'ACCESS_SOURCE_TYPE', 'ACCESS_SOURCE_ID',
					'ACCESS_CONTEXT_HASH', 'ACCESS_CONTEXT', 'ACCESS_EXPIRES_AT'] as $field)
				{
					$identity[$field] = $pending[$field];
					$knownValue = $known[$field] ?? null;
					$ownValue = $pending[$field];
					if ($knownValue instanceof DateTime)
					{
						$knownValue = $knownValue->getTimestamp();
					}
					if ($ownValue instanceof DateTime)
					{
						$ownValue = $ownValue->getTimestamp();
					}
					if ($knownValue !== null && $knownValue !== $ownValue)
					{
						return $this->failure('Vibeoffice UUID belongs to another access source.');
					}
				}
				if ($known && $known['OPEN_TOKEN'] !== null && $known['OPEN_TOKEN'] !== $pending['OPEN_TOKEN'])
				{
					return $this->failure('Vibeoffice UUID belongs to another opening.');
				}
				$ban = max((int)$pending['ACCESS_REVOKE_STATE'], (int)($known['ACCESS_REVOKE_STATE'] ?? 0));
				$attempt = $session
					? $this->readAttemptState(DocumentSessionManager::extractVoMetadata($session), $pending['OPEN_TOKEN'])
					: false;
				$identity['ACCESS_REVOKE_STATE'] = $ban === 2
					&& is_array($attempt) && $attempt['outstanding'] > 0 ? 1 : $ban;
				$identity['REVOKE_RETRY_AT'] = $identity['ACCESS_REVOKE_STATE'] === 1 ? new DateTime() : null;
				if (SharedEditingSchema::hasRevokeTelemetry())
				{
					$times = array_filter([$pending['REVOKE_REQUESTED_AT'] ?? null, $known['REVOKE_REQUESTED_AT'] ?? null],
						static fn($value): bool => $value instanceof DateTime);
					usort($times, static fn(DateTime $a, DateTime $b): int => $a->getTimestamp() <=> $b->getTimestamp());
					$identity['REVOKE_REQUESTED_AT'] = $times[0] ?? null;
				}
			}
			$terminal = ($shared && (int)$identity['ACCESS_REVOKE_STATE'] === 2)
				|| $pending['STATUS'] === PlatformSessionTable::STATUS_TERMINATED
				|| ($known && $known['STATUS'] === PlatformSessionTable::STATUS_TERMINATED)
				|| $this->isEditorGenerationTerminated($hostDocKey, $editorKey, $rev);
			$fields = $identity + [
				'API_SESSION_ID' => $uuid,
				'DOCUMENT_SESSION_ID' => $sessionId,
				'OPEN_TOKEN' => $pending['OPEN_TOKEN'],
				'EDITOR_KEY' => $editorKey,
				'REV' => $rev,
				'STATUS' => $terminal ? PlatformSessionTable::STATUS_TERMINATED : PlatformSessionTable::STATUS_ACTIVE,
				'UPDATE_TIME' => new DateTime(),
			];
			if ($known)
			{
				$delete = PlatformSessionTable::delete($pending['ID']);
				if (!$delete->isSuccess())
				{
					return (new Result())->addErrors($delete->getErrors());
				}
			}
			$survivingRow = $known ?? $pending;
			$update = PlatformSessionTable::update($survivingRow['ID'], $fields);
			if (!$update->isSuccess())
			{
				return (new Result())->addErrors($update->getErrors());
			}

			$sessionMetadata = [
				'session_id' => $uuid,
				'editor_key' => $editorKey,
				'rev' => $rev,
			];
			if (!$terminal && $session && $session->isEdit() && !$session->isVersion())
			{
				// This primary transaction starts after open returns; its File model may predate that wait.
				$file = FileTable::query()->setSelect(['GLOBAL_CONTENT_VERSION'])
					->where('ID', (int)$session->getObjectId())->fetch();
				if (!$file || $file['GLOBAL_CONTENT_VERSION'] === null)
				{
					if (!$shared)
					{
						return $this->failure('The opened document is unavailable.');
					}
				}
				else
				{
					$sessionMetadata[DocumentSessionManager::CONTEXT_VO_OPENED_CONTENT_VERSION_KEY]
						= (int)$file['GLOBAL_CONTENT_VERSION'];
				}
			}
			if ($session && !(new DocumentSessionManager())->persistSessionMetadata($session, $sessionMetadata))
			{
				return $this->failure('Could not persist Vibeoffice open metadata.', self::ERROR_CODE_BUSY);
			}

			$completedRow = $fields + $survivingRow;
			$result = $this->completionResult($completedRow, $sessionId);
			if ($shared && !$terminal)
			{
				$checked = $this->checkSharedAccessLocked($completedRow, $document, $lockedFile);
				if (!$checked->isSuccess())
				{
					return $checked;
				}
				$result->setData(array_replace($result->getData(), ['configAllowed' => $checked->getData()['allowed']]));
			}

			return $result;
		});
	}

	public function refuseOpen(string $openToken): Result
	{
		try
		{
			return $this->withOpenToken($openToken, function (array $row): Result {
				$sessionId = (int)$row['DOCUMENT_SESSION_ID'];
				$sessionRow = $this->fetchLocked(DocumentSessionTable::query()
					->setSelect(['*'])->where('ID', $sessionId));
				$session = $sessionRow ? DocumentSession::buildFromArray($sessionRow) : null;
				if (!$session || HostDocumentKey::resolve($session) !== $row['HOST_DOC_KEY'])
				{
					return $this->failure('Invalid Vibeoffice open binding.');
				}

				$marked = $row['STATUS'] === PlatformSessionTable::STATUS_REFUSED
					&& ($row['API_SESSION_ID'] === null || $row['API_SESSION_ID'] === '');
				if ($row['STATUS'] === PlatformSessionTable::STATUS_PENDING
					&& ($row['API_SESSION_ID'] === null || $row['API_SESSION_ID'] === ''))
				{
					$state = $this->readAttemptState(
						DocumentSessionManager::extractVoMetadata($session), $row['OPEN_TOKEN'],
					);
					if (!is_array($state) || OpenAttemptState::hasDebt($state) || $state['stop'])
					{
						return $this->unresolved('Vibeoffice open attempt still has an unknown outcome.');
					}
					$update = PlatformSessionTable::update($row['ID'], [
						'STATUS' => PlatformSessionTable::STATUS_REFUSED,
						'UPDATE_TIME' => new DateTime(),
					]);
					if (!$update->isSuccess())
					{
						return (new Result())->addErrors($update->getErrors());
					}
					$marked = true;
				}

				return (new Result())->setData([
					'marked' => $marked,
					'openToken' => $row['OPEN_TOKEN'],
					'documentSessionId' => $sessionId,
					'hostDocKey' => $row['HOST_DOC_KEY'],
				]);
			});
		}
		catch (\Throwable $exception)
		{
			return $this->failure($exception->getMessage());
		}
	}

	/**
	 * A local cancel proves nothing about the request already sent with the token: the pending
	 * attempt, its token and snapshot stay, and no local session becomes eligible for finalization.
	 */
	public function cancelOpen(string $openToken): Result
	{
		if (!$this->normalizeUuid($openToken))
		{
			return $this->failure('Invalid Vibeoffice open token.');
		}

		return (new Result())->setData(['sessionIds' => []]);
	}

	/** Stop an abandoned shared admission; only a subsequent platform ACK can end its debt. */
	public function abandonSharedOpen(string $openToken): Result
	{
		return $this->withOpenToken($openToken, function (array $row, array $document): Result {
			if (SharedSessionScope::version($document) !== 1
				|| $row['STATUS'] !== PlatformSessionTable::STATUS_PENDING
				|| $row['API_SESSION_ID'] !== null)
			{
				return new Result();
			}
			$session = $this->fetchLockedSession((int)$row['DOCUMENT_SESSION_ID']);
			if (!$session || !SharedSessionScope::matchesBinding($session, $row))
			{
				return $this->unresolved('The abandoned editing admission is unknown.');
			}
			$result = $this->markRevokeLocked($row);
			return $result->isSuccess()
				? $result->setData($result->getData() + ['registryId' => (int)$row['ID']]) : $result;
		});
	}

	/**
	 * Drops the pending attempt. Only for a caller that holds a definitive answer of the platform
	 * on this very token and a known state without outstanding sends; any other outcome is retained.
	 */
	public function cancelDefinitiveOpen(string $openToken): Result
	{
		return $this->withOpenToken($openToken, function (array $row) use ($openToken): Result {
			if ($row['STATUS'] !== PlatformSessionTable::STATUS_PENDING
				|| (int)($row['ACCESS_REVOKE_STATE'] ?? 0) !== 0)
			{
				return (new Result())->setData(['sessionIds' => []]);
			}
			$session = $this->fetchLockedSession((int)$row['DOCUMENT_SESSION_ID']);
			$state = $session
				? $this->readAttemptState(DocumentSessionManager::extractVoMetadata($session), $openToken)
				: null;
			if (!is_array($state) || OpenAttemptState::hasDebt($state))
			{
				return $this->unresolved('Vibeoffice open attempt still has outstanding sends.');
			}

			$result = PlatformSessionTable::delete($row['ID']);

			return $result->isSuccess()
				? (new Result())->setData(['sessionIds' => $this->eligibleSessionIds([(int)$row['DOCUMENT_SESSION_ID']])])
				: (new Result())->addErrors($result->getErrors());
		});
	}

	/** Historical pending rows deliberately retain an unknown origin. */
	private function requestedTimeFields(array $row): array
	{
		return (int)$row['ACCESS_REVOKE_STATE'] === 0 && SharedEditingSchema::hasRevokeTelemetry()
			? ['REVOKE_REQUESTED_AT' => $row['REVOKE_REQUESTED_AT'] ?? new DateTime()] : [];
	}

	/** Called by a source mutation after the real file and this binding have been locked. */
	public function markRevokeLocked(array $row): Result
	{
		if (!DocumentStateRepository::hasOpenTransaction())
		{
			return $this->failure('Revoke requires the source transaction.');
		}
		$session = $this->fetchLockedSession((int)$row['DOCUMENT_SESSION_ID']);
		$attempt = $session && is_string($row['OPEN_TOKEN'])
			? $this->readAttemptState(DocumentSessionManager::extractVoMetadata($session), $row['OPEN_TOKEN'])
			: false;
		$confirmed = (int)$row['ACCESS_REVOKE_STATE'] === 2
			&& (!is_array($attempt) || $attempt['outstanding'] === 0);
		if (!$confirmed && (int)$row['ACCESS_REVOKE_STATE'] !== 1)
		{
			$updated = PlatformSessionTable::update($row['ID'], [
				'ACCESS_REVOKE_STATE' => 1, 'REVOKE_RETRY_AT' => new DateTime(), 'UPDATE_TIME' => new DateTime(),
			] + $this->requestedTimeFields($row));
			if (!$updated->isSuccess())
			{
				return (new Result())->addErrors($updated->getErrors());
			}
		}
		if ($session && SharedSessionScope::matchesBinding($session, $row) && $session->isActive())
		{
			$updated = DocumentSessionTable::update($session->getId(), ['STATUS' => DocumentSession::STATUS_NON_ACTIVE]);
			if (!$updated->isSuccess())
			{
				return (new Result())->addErrors($updated->getErrors());
			}
		}

		return (new Result())->setData(['pending' => !$confirmed]);
	}

	/**
	 * The caller must prove no outstanding send before issuing the acknowledged request.
	 * This final check cannot establish that ordering from the current debt alone.
	 * An ACK ends API liveness only; content evidence and identity carriers are retained.
	 */
	public function confirmRevokeLocked(string $hostDocKey, string $subject, array $ack): Result
	{
		if (!DocumentStateRepository::isDocumentLocked($hostDocKey) || !DocumentStateRepository::hasOpenTransaction())
		{
			return $this->failure('Revoke confirmation requires the document transaction.');
		}
		$placement = $ack['placement'] ?? null;
		if (($ack['confirmation_version'] ?? null) !== 1 || ($ack['host_doc_key'] ?? null) !== $hostDocKey
			|| ($ack['subject'] ?? null) !== $subject || ($ack['result'] ?? null) !== 'confirmed'
			|| !array_key_exists('placement', $ack) || preg_match('/^vp1_[a-f0-9]{32}$/D', $subject) !== 1
			|| ($placement !== null && (!is_array($placement)
				|| !$this->normalizeUuid($placement['instance_id'] ?? null)
				|| !is_string($placement['editor_key'] ?? null) || $placement['editor_key'] === ''
				|| !is_int($placement['rev'] ?? null) || $placement['rev'] < 0)))
		{
			return $this->failure('Invalid revoke acknowledgement.');
		}
		$document = $this->stateRepository->getByKey($hostDocKey);
		if (!$document || SharedSessionScope::version($document) !== 1)
		{
			return $this->failure('Unknown revoke scope.');
		}
		$rows = $this->queryLocked(PlatformSessionTable::query()->setSelect(SharedEditingSchema::sessionFields())
			->where('HOST_DOC_KEY', $hostDocKey)->where('PLATFORM_USER_ID', $subject));
		$confirmedCount = 0;
		foreach ($rows as $row)
		{
			if ((int)$row['ACCESS_REVOKE_STATE'] !== 1 || !is_string($row['OPEN_TOKEN']))
			{
				continue;
			}
			$session = $this->fetchLockedSession((int)$row['DOCUMENT_SESSION_ID']);
			if (!$session && $row['STATUS'] === PlatformSessionTable::STATUS_PENDING)
			{
				continue;
			}
			if ($session)
			{
				if (!SharedSessionScope::matchesBinding($session, $row))
				{
					continue;
				}
				$vo = DocumentSessionManager::extractVoMetadata($session);
				$attempt = $this->readAttemptState($vo, $row['OPEN_TOKEN']);
				if (!is_array($attempt) || $attempt['outstanding'] !== 0)
				{
					continue;
				}
				$token = $row['OPEN_TOKEN'];
				$persisted = (new DocumentSessionManager())->persistOpenAttemptTransition(
					$session,
					static function (array $current) use ($token): array {
						$attempt = &$current[DocumentSessionManager::CONTEXT_VO_OPEN_ATTEMPTS_KEY][$token];
						$attempt['stop'] = true;
						$attempt['finalized'] = true;
						$attempt['owner'] = null;
						$attempt['consumed'] = true;

						return $current;
					},
				);
				if (!$persisted)
				{
					return $this->failure('Could not finish the revoked open attempt.');
				}
			}
			$updated = PlatformSessionTable::update($row['ID'], [
				'ACCESS_REVOKE_STATE' => 2, 'STATUS' => PlatformSessionTable::STATUS_TERMINATED,
				'REVOKE_RETRY_AT' => null, 'UPDATE_TIME' => new DateTime(),
			]);
			if (!$updated->isSuccess())
			{
				return (new Result())->addErrors($updated->getErrors());
			}
			$confirmedCount++;
		}

		return (new Result())->setData(['confirmedCount' => $confirmedCount]);
	}

	/** Move only confirmed live bindings; pending responses retain their original generation fence. */
	public function advanceActivePlacement(string $hostDocKey, string $editorKey, int $rev): Result
	{
		if (!$this->isEditorKey($editorKey) || !$this->isRevision($rev))
		{
			return $this->failure('Invalid Vibeoffice editor generation.');
		}

		return $this->stateRepository->withLockedDocument($hostDocKey,
			fn(array $state): Result => $this->advanceActivePlacementLocked($state, $editorKey, $rev),
		);
	}

	/** The caller already owns the document, real file and state locks in its SQL transaction. */
	public function advanceActivePlacementLocked(array $state, string $editorKey, int $rev): Result
	{
		return $this->advancePlacementLocked($state, $editorKey, $rev, false);
	}

	/** A signed saved or no-change terminal can settle an admitted binding after its revoke ACK. */
	public function advanceTerminalPlacementLocked(array $state, string $editorKey, int $rev): Result
	{
		return $this->advancePlacementLocked($state, $editorKey, $rev, true);
	}

	private function advancePlacementLocked(array $state, string $editorKey, int $rev, bool $includeRevoked): Result
	{
		$hostDocKey = $state['HOST_DOC_KEY'] ?? null;
		if (!is_string($hostDocKey) || !DocumentStateRepository::isDocumentLocked($hostDocKey)
			|| !DocumentStateRepository::hasOpenTransaction())
		{
			return $this->failure('Placement advancement requires the document transaction.');
		}
		if (!$this->isEditorKey($editorKey) || !$this->isRevision($rev))
		{
			return $this->failure('Invalid Vibeoffice editor generation.');
		}
		$rejection = $this->stateRepository->getPlacementRejection($state, ['editorKey' => $editorKey, 'rev' => $rev]);
		if ($rejection !== null)
		{
			return (new Result())->setData(['accepted' => false, 'reason' => $rejection]);
		}
		$query = PlatformSessionTable::query()->setSelect(['ID'])
			->where('HOST_DOC_KEY', $hostDocKey)->where('STATUS', PlatformSessionTable::STATUS_ACTIVE)
			->whereNotNull('API_SESSION_ID')
			->where(Query::filter()->logic('or')->whereNull('REV')->where('REV', '<', $rev));
		foreach ($this->queryLocked($query) as $row)
		{
			$updated = PlatformSessionTable::update($row['ID'], [
				'EDITOR_KEY' => $editorKey, 'REV' => $rev, 'UPDATE_TIME' => new DateTime(),
			]);
			if (!$updated->isSuccess())
			{
				return (new Result())->addErrors($updated->getErrors());
			}
		}

		if ($includeRevoked && SharedSessionScope::version($state) === 1 && SharedEditingSchema::hasColumns())
		{
			$query = PlatformSessionTable::query()->setSelect(SharedEditingSchema::sessionFields())
				->where('HOST_DOC_KEY', $hostDocKey)->where('STATUS', PlatformSessionTable::STATUS_TERMINATED)
				->where('ACCESS_REVOKE_STATE', 2)->whereNotNull('API_SESSION_ID')->whereNotNull('OPEN_TOKEN')
				->where(Query::filter()->logic('or')->whereNull('REV')->where('REV', '<', $rev));
			foreach ($this->queryLocked($query) as $row)
			{
				if (!$this->isAdmittedRevokedBinding($state, $row))
				{
					continue;
				}
				$updated = PlatformSessionTable::update($row['ID'], [
					'EDITOR_KEY' => $editorKey, 'REV' => $rev, 'UPDATE_TIME' => new DateTime(),
				]);
				if (!$updated->isSuccess())
				{
					return (new Result())->addErrors($updated->getErrors());
				}
			}
		}

		return (new Result())->setData(['accepted' => true]);
	}

	private function isAdmittedRevokedBinding(array $state, array $row): bool
	{
		$access = SharedSessionScope::access($row);

		return $row['HOST_DOC_KEY'] === $state['HOST_DOC_KEY']
			&& (int)$row['DOCUMENT_SESSION_ID'] > 0
			&& $this->normalizeUuid($row['API_SESSION_ID']) !== null
			&& $this->normalizeUuid($row['OPEN_TOKEN']) !== null
			&& preg_match('/^vp1_[a-f0-9]{32}$/D', $row['PLATFORM_USER_ID'] ?? '') === 1
			&& $access !== null && ($access['context']['schemaVersion'] ?? null) === 1
			&& ($access['context']['objectId'] ?? null) === (int)$state['OBJECT_ID']
			&& ($access['context']['versionId'] ?? null) === null
			&& ($access['context']['sourceType'] ?? null) === $access['sourceType']
			&& ($access['context']['sourceId'] ?? null) === $access['sourceId']
			&& in_array($access['sourceType'], ['internal', 'attached', 'external', 'unified'], true);
	}

	/** Explicit UUID termination is authoritative in this host key, regardless of editor_key. */
	public function applyClosed(
		string $hostDocKey, array $terminated, array $retained, ?string $editorKey = null, ?int $rev = null,
	): Result
	{
		$terminated = $this->normalizeUuids($terminated);
		$retained = $this->normalizeUuids($retained);
		$hasPlacement = $editorKey !== null || $rev !== null;
		if ($terminated === null || $retained === null || array_intersect($terminated, $retained)
			|| ($hasPlacement && (!$this->isEditorKey($editorKey) || $rev === null || !$this->isRevision($rev))))
		{
			return $this->failure('Invalid Vibeoffice closed session lists.');
		}

		return $this->stateRepository->withLockedDocument($hostDocKey, function (array $state) use (
			$hostDocKey, $terminated, $retained, $editorKey, $rev, $hasPlacement,
		): Result {
			$adoptPlacement = $hasPlacement
				&& $this->stateRepository->acceptsPlacement($state, ['editorKey' => $editorKey, 'rev' => $rev]);
			$sessionIds = [];
			$closed = false;
			foreach ([PlatformSessionTable::STATUS_TERMINATED => $terminated, PlatformSessionTable::STATUS_ACTIVE => $retained] as $status => $uuids)
			{
				foreach ($uuids as $uuid)
				{
					$row = $this->findUuid($uuid);
					if ($row && $row['HOST_DOC_KEY'] !== $hostDocKey)
					{
						return $this->failure('Vibeoffice UUID belongs to another document.');
					}
					if ($row)
					{
						$fields = [];
						if ($adoptPlacement && ($row['REV'] === null || (int)$row['REV'] <= $rev))
						{
							$fields = ['EDITOR_KEY' => $editorKey, 'REV' => $rev];
						}
						if ($status === PlatformSessionTable::STATUS_TERMINATED)
						{
							$sessionIds[] = (int)$row['DOCUMENT_SESSION_ID'];
							if ($row['STATUS'] !== PlatformSessionTable::STATUS_TERMINATED)
							{
								$fields['STATUS'] = $status;
							}
						}
						if ($fields === [])
						{
							continue;
						}
						$result = PlatformSessionTable::update($row['ID'], $fields + ['UPDATE_TIME' => new DateTime()]);
					}
					else
					{
						$result = PlatformSessionTable::add([
							'HOST_DOC_KEY' => $hostDocKey, 'API_SESSION_ID' => $uuid, 'STATUS' => $status,
						]);
					}
					if (!$result->isSuccess())
					{
						return (new Result())->addErrors($result->getErrors());
					}
					$closed = $closed || $status === PlatformSessionTable::STATUS_TERMINATED;
				}
			}

			if ($closed)
			{
				$touch = DocumentStateTable::update((int)$state['ID'], ['UPDATED_TIME' => new DateTime()]);
				if (!$touch->isSuccess())
				{
					return (new Result())->addErrors($touch->getErrors());
				}
			}

			return (new Result())->setData([
				'sessionIds' => $this->eligibleSessionIds($sessionIds),
				'placementAccepted' => !$hasPlacement || $adoptPlacement,
			]);
		});
	}

	/** A marker without a UUID also fences late open responses from this exact editor generation. */
	public function terminateEditorGeneration(string $hostDocKey, string $editorKey, ?int $rev = null): Result
	{
		if (!$this->isEditorKey($editorKey) || !$this->isRevision($rev))
		{
			return $this->failure('Invalid Vibeoffice editor generation.');
		}

		return $this->stateRepository->withLockedDocument($hostDocKey, function (array $state) use (
			$hostDocKey, $editorKey, $rev,
		): Result {
			$query = $this->editorQuery($hostDocKey, $editorKey, $rev);
			$rows = $this->queryLocked($query);
			$sessionIds = [];
			$hasMarker = false;
			$closed = false;
			foreach ($rows as $row)
			{
				$hasMarker = $hasMarker || (!$row['API_SESSION_ID'] && !$row['OPEN_TOKEN'] && !$row['DOCUMENT_SESSION_ID']);
				$sessionIds[] = (int)$row['DOCUMENT_SESSION_ID'];
				if ($row['STATUS'] === PlatformSessionTable::STATUS_TERMINATED)
				{
					continue;
				}
				$result = PlatformSessionTable::update($row['ID'], [
					'STATUS' => PlatformSessionTable::STATUS_TERMINATED,
					'UPDATE_TIME' => new DateTime(),
				]);
				if (!$result->isSuccess())
				{
					return (new Result())->addErrors($result->getErrors());
				}
				$closed = true;
			}
			if (!$hasMarker)
			{
				$result = PlatformSessionTable::add([
					'HOST_DOC_KEY' => $hostDocKey,
					'EDITOR_KEY' => $editorKey,
					'REV' => $rev,
					'STATUS' => PlatformSessionTable::STATUS_TERMINATED,
				]);
				if (!$result->isSuccess())
				{
					return (new Result())->addErrors($result->getErrors());
				}
				$closed = true;
			}

			if ($closed)
			{
				$touch = DocumentStateTable::update((int)$state['ID'], ['UPDATED_TIME' => new DateTime()]);
				if (!$touch->isSuccess())
				{
					return (new Result())->addErrors($touch->getErrors());
				}
			}

			return (new Result())->setData(['sessionIds' => $this->eligibleSessionIds($sessionIds)]);
		});
	}

	/** Attribution survives revocation and cleanup through exact placement or an unambiguous confirmed subject. */
	public function resolveActorForPlacement(
		string $hostDocKey,
		string $subject,
		string $editorKey,
		int $rev,
		?string $apiSessionId = null,
	): ?int
	{
		if ($hostDocKey === '' || strlen($hostDocKey) > 128
			|| preg_match('/^vp1_[a-f0-9]{32}$/D', $subject) !== 1
			|| !$this->isEditorKey($editorKey) || !$this->isRevision($rev)
			|| ($apiSessionId !== null && $this->normalizeUuid($apiSessionId) === null)
			|| !SharedEditingSchema::hasColumns())
		{
			return null;
		}

		$pool = Application::getInstance()->getConnectionPool();
		$pool->useMasterOnly(true);
		try
		{
			$state = $this->stateRepository->getByKey($hostDocKey);
			if (!$state || $state['HOST_DOC_KEY'] !== $hostDocKey || SharedSessionScope::version($state) !== 1)
			{
				return null;
			}

			$uuid = $apiSessionId === null ? null : $this->normalizeUuid($apiSessionId);
			$query = PlatformSessionTable::query()
				->setSelect([
					'ID', 'HOST_DOC_KEY', 'PLATFORM_USER_ID', 'ACTOR_USER_ID', 'EDITOR_KEY', 'REV',
					'API_SESSION_ID', 'OPEN_TOKEN', 'DOCUMENT_SESSION_ID',
				])
				->where('HOST_DOC_KEY', $hostDocKey)->where('PLATFORM_USER_ID', $subject)
				->whereIn('STATUS', [PlatformSessionTable::STATUS_ACTIVE, PlatformSessionTable::STATUS_TERMINATED])
				->where('ACTOR_USER_ID', '>', 0)->where('DOCUMENT_SESSION_ID', '>', 0)
				->whereNotNull('API_SESSION_ID')->whereNotNull('OPEN_TOKEN')
			;
			if ($uuid !== null)
			{
				$query->where('API_SESSION_ID', $uuid);
			}

			foreach ($uuid === null ? [true, false] : [true] as $exact)
			{
				$placementQuery = clone $query;
				if ($exact)
				{
					$placementQuery->where('EDITOR_KEY', $editorKey)->where('REV', $rev);
				}
				else
				{
					$placementQuery->whereNotNull('REV')->where('REV', '<=', $rev);
				}
				$actorId = null;
				$lastId = 0;
				do
				{
					$page = (clone $placementQuery)->where('ID', '>', $lastId)->setOrder(['ID' => 'ASC'])->setLimit(100);
					$rows = DocumentStateRepository::hasOpenTransaction() ? $this->queryLocked($page) : $page->fetchAll();
					foreach ($rows as $row)
					{
						$lastId = (int)$row['ID'];
						// SQL collations may compare distinct subject, key and editor strings as equal.
						if ($row['HOST_DOC_KEY'] !== $hostDocKey || $row['PLATFORM_USER_ID'] !== $subject
							|| ($exact && ($row['EDITOR_KEY'] !== $editorKey || (int)$row['REV'] !== $rev))
							|| (!$exact && ($row['REV'] === null || (int)$row['REV'] > $rev))
							|| $this->normalizeUuid($row['API_SESSION_ID']) === null
							|| $this->normalizeUuid($row['OPEN_TOKEN']) === null
							|| ($uuid !== null && $row['API_SESSION_ID'] !== $uuid))
						{
							continue;
						}
						$candidate = (int)$row['ACTOR_USER_ID'];
						if ($actorId !== null && $actorId !== $candidate)
						{
							return null;
						}
						$actorId = $candidate;
					}
				}
				while (count($rows) === 100);
				if ($actorId !== null)
				{
					return $actorId;
				}
			}

			return null;
		}
		finally
		{
			$pool->useMasterOnly(false);
		}
	}

	public function hasLiveSessions(int $documentSessionId): bool
	{
		return (bool)$this->fetchLocked(PlatformSessionTable::query()
			->setSelect(['ID'])
			->where('DOCUMENT_SESSION_ID', $documentSessionId)
			->where($this->liveFilter())
			->setLimit(1));
	}

	public function hasLiveSessionsForHash(string $externalHash): bool
	{
		$sessions = DocumentSessionTable::query()
			->setSelect(['ID'])
			->where('EXTERNAL_HASH', $externalHash)
			->where('SERVICE', DocumentService::Vibeoffice->value);

		return (bool)$this->fetchLocked(PlatformSessionTable::query()
			->setSelect(['ID'])
			->whereIn('DOCUMENT_SESSION_ID', $sessions)
			->where($this->liveFilter())
			->setLimit(1));
	}

	/** Mapped local IDs except refused; callers must check liveness before finalizing them. */
	public function getSessionIds(string $hostDocKey, ?string $editorKey = null): array
	{
		$query = PlatformSessionTable::query()->setSelect(['DOCUMENT_SESSION_ID'])
			->where('HOST_DOC_KEY', $hostDocKey)->where('STATUS', '!=', PlatformSessionTable::STATUS_REFUSED)
		;
		if ($editorKey !== null)
		{
			$query->where('EDITOR_KEY', $editorKey);
		}

		return array_values(array_unique(array_filter(array_map(
			static fn(array $row): int => (int)$row['DOCUMENT_SESSION_ID'],
			$query->fetchAll(),
		))));
	}

	/** Batches are a memory bound, not a limit on retained history. */
	public function getSessionIdBatches(
		string $hostDocKey,
		?string $editorKey = null,
		?int $rev = null,
		bool $confirmedOnly = false,
	): \Generator {
		$lastId = 0;
		do
		{
			$query = PlatformSessionTable::query()->setSelect(['ID', 'DOCUMENT_SESSION_ID'])
				->where('HOST_DOC_KEY', $hostDocKey)->whereNotNull('DOCUMENT_SESSION_ID')
				->where('STATUS', '!=', PlatformSessionTable::STATUS_REFUSED)
				->where('ID', '>', $lastId)->setOrder(['ID' => 'ASC'])->setLimit(100);
			if ($editorKey !== null)
			{
				$query->where('EDITOR_KEY', $editorKey)->where('REV', $rev);
			}
			if ($confirmedOnly)
			{
				$query->where('API_SESSION_ID', '!=', '')->whereIn('STATUS', [
					PlatformSessionTable::STATUS_ACTIVE,
					PlatformSessionTable::STATUS_TERMINATED,
				]);
			}
			$rows = $query->fetchAll();
			if ($rows)
			{
				$lastId = (int)end($rows)['ID'];
				yield array_values(array_unique(array_map('intval', array_column($rows, 'DOCUMENT_SESSION_ID'))));
			}
		}
		while (count($rows) === 100);
	}

	public function getRecipientSessionIds(string $hostDocKey, string $editorKey, int $rev): array
	{
		$queries = [
			PlatformSessionTable::query()->where('HOST_DOC_KEY', $hostDocKey)->where($this->liveFilter()),
			$this->editorQuery($hostDocKey, $editorKey, $rev)->whereIn('STATUS', [
				PlatformSessionTable::STATUS_ACTIVE, PlatformSessionTable::STATUS_PENDING, PlatformSessionTable::STATUS_TERMINATED,
			]),
		];
		$ids = [];
		foreach ($queries as $query)
		{
			$rows = $query->setSelect(['DOCUMENT_SESSION_ID'])->setDistinct()
				->whereNotNull('DOCUMENT_SESSION_ID')->fetchAll();
			foreach ($rows as $row)
			{
				$id = (int)$row['DOCUMENT_SESSION_ID'];
				$ids[$id] = $id;
			}
		}

		return array_values($ids);
	}

	/** A cancelled HTTP attempt alone does not prove that its local session is terminal. */
	public function getTerminatedSessionIds(array $sessionIds): array
	{
		if ($sessionIds === [])
		{
			return [];
		}
		$rows = PlatformSessionTable::query()->setSelect(['DOCUMENT_SESSION_ID'])
			->whereIn('DOCUMENT_SESSION_ID', $sessionIds)
			->where('STATUS', PlatformSessionTable::STATUS_TERMINATED)
			->whereNotNull('API_SESSION_ID')->fetchAll();

		return array_values(array_unique(array_map('intval', array_column($rows, 'DOCUMENT_SESSION_ID'))));
	}

	private function withOpenToken(string $openToken, callable $mutation): Result
	{
		if (!$this->normalizeUuid($openToken))
		{
			return $this->failure('Invalid Vibeoffice open token.');
		}
		$pool = Application::getInstance()->getConnectionPool();
		$pool->useMasterOnly(true);
		try
		{
			$row = PlatformSessionTable::query()->setSelect(['HOST_DOC_KEY'])->where('OPEN_TOKEN', $openToken)->fetch();
		}
		finally
		{
			$pool->useMasterOnly(false);
		}
		if (!$row)
		{
			return $this->failure('Vibeoffice open reservation was not found.');
		}

		return $this->stateRepository->withLockedDocument($row['HOST_DOC_KEY'], function (array $state, ?File $lockedFile) use ($openToken, $mutation): Result {
			$row = $this->fetchLocked(PlatformSessionTable::query()
				->setSelect(SharedEditingSchema::sessionFields())->where('OPEN_TOKEN', $openToken));

			return $row ? $mutation($row, $state, $lockedFile) : $this->failure('Vibeoffice open reservation was not found.');
		});
	}

	private function completionResult(array $row, int $sessionId): Result
	{
		return (new Result())->setData([
			'terminal' => $row['STATUS'] === PlatformSessionTable::STATUS_TERMINATED,
			'configAllowed' => (int)($row['ACCESS_REVOKE_STATE'] ?? 0) === 0,
			'sessionIds' => $this->eligibleSessionIds([$sessionId]),
			'hostDocKey' => $row['HOST_DOC_KEY'],
		]);
	}

	private function liveFilter(): \Bitrix\Main\ORM\Query\Filter\ConditionTree
	{
		return Query::filter()->logic('or')
			->where('STATUS', PlatformSessionTable::STATUS_ACTIVE)
			->where('STATUS', PlatformSessionTable::STATUS_PENDING);
	}

	private function eligibleSessionIds(array $sessionIds): array
	{
		return array_values(array_filter(array_unique($sessionIds), fn(int $id): bool => $id > 0 && !$this->hasLiveSessions($id)));
	}

	private function findUuid(string $uuid): ?array
	{
		return $this->fetchLocked(PlatformSessionTable::query()
			->setSelect(SharedEditingSchema::sessionFields())->where('API_SESSION_ID', $uuid));
	}

	private function editorQuery(string $hostDocKey, string $editorKey, ?int $rev): Query
	{
		$query = PlatformSessionTable::query()->setSelect(SharedEditingSchema::sessionFields())
			->where('HOST_DOC_KEY', $hostDocKey)->where('EDITOR_KEY', $editorKey)
		;
		if ($rev !== null)
		{
			$query->where('REV', $rev);
		}

		return $query;
	}

	private function isEditorGenerationTerminated(string $hostDocKey, string $editorKey, ?int $rev): bool
	{
		$revision = Query::filter()->whereNull('REV');
		if ($rev !== null)
		{
			$revision->logic('or')->where('REV', $rev);
		}
		$query = $this->editorQuery($hostDocKey, $editorKey, null)->setSelect(['ID'])
			->whereNull('API_SESSION_ID')->whereNull('OPEN_TOKEN')->whereNull('DOCUMENT_SESSION_ID')
			->where('STATUS', PlatformSessionTable::STATUS_TERMINATED)->where($revision)->setLimit(1);

		return $this->fetchLocked($query) !== null;
	}

	private function fetchLocked(Query $query): ?array
	{
		return DocumentStateRepository::fetchForUpdate($query);
	}

	private function queryLocked(Query $query): array
	{
		return DocumentStateRepository::fetchAllForUpdate($query);
	}

	private function normalizeUuid(mixed $uuid): ?string
	{
		return is_string($uuid) && preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/Di', $uuid)
			? strtolower($uuid) : null;
	}

	private function normalizeUuids(array $uuids): ?array
	{
		if (count($uuids) > self::MAX_EVENT_SESSIONS)
		{
			return null;
		}
		$result = [];
		foreach ($uuids as $uuid)
		{
			$uuid = $this->normalizeUuid($uuid);
			if (!$uuid)
			{
				return null;
			}
			$result[$uuid] = $uuid;
		}

		return array_values($result);
	}

	private function isEditorKey(mixed $editorKey): bool
	{
		return is_string($editorKey) && $editorKey !== '' && strlen($editorKey) <= 255;
	}

	private function isRevision(mixed $rev): bool
	{
		return $rev === null || (is_int($rev) && $rev >= 0 && $rev <= 2_147_483_647);
	}

	private function unresolved(string $message): Result
	{
		return $this->failure($message, self::ERROR_CODE_OPEN_UNRESOLVED);
	}

	private function expiredAttemptRefusal(array $row, string $status, ?array $vo = null): Result
	{
		$state = null;
		if ($vo !== null)
		{
			try
			{
				$state = $this->readAttemptState($vo, (string)$row['OPEN_TOKEN']);
			}
			catch (\TypeError)
			{
				// Corrupt metadata must not replace an already established expiry refusal.
			}
		}

		return $this->attemptRefusal(
			'Vibeoffice open operation expired.',
			self::ERROR_CODE_OPEN_EXPIRED,
			$row['CREATE_TIME'] instanceof DateTime ? 'token_expired' : 'token_age_unknown',
			$status,
			$state,
		);
	}

	private function attemptRefusal(
		string $message,
		string $code,
		string $reason,
		string $status,
		array|false|null $state = null,
		?bool $fingerprintMatches = null,
	): Result
	{
		$result = $this->failure($message, $code);
		$this->openRefusalDiagnostics ??= new \WeakMap();
		$this->openRefusalDiagnostics[$result] = [
			'registry_reason' => $reason,
			'registry_status' => $status,
			'registry_outstanding' => is_array($state) ? $state['outstanding'] : null,
			'registry_fingerprint_present' => is_array($state) && array_key_exists('fingerprint', $state)
				? $state['fingerprint'] !== null
				: null,
			'registry_fingerprint_matches' => $fingerprintMatches,
		];

		return $result;
	}

	public function getOpenRefusalDiagnostics(Result $result): ?array
	{
		return $this->openRefusalDiagnostics[$result] ?? null;
	}

	private function failure(string $message, string $code = self::ERROR_CODE_FAILED): Result
	{
		return (new Result())->addError(new Error($message, $code));
	}
}
