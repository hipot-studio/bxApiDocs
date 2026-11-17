<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Vibeoffice;

use Bitrix\Disk\Document\Models\DocumentService;
use Bitrix\Disk\Document\Models\DocumentSession;
use Bitrix\Disk\Document\Models\DocumentSessionTable;
use Bitrix\Disk\Document\SessionManager;
use Bitrix\Disk\File;
use Bitrix\Disk\Internals\FileTable;
use Bitrix\Disk\Internals\VersionTable;
use Bitrix\Main\Application;
use Bitrix\Main\Error;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\Result;
use Bitrix\Main\Web\Json;

final class DocumentSessionManager extends SessionManager
{
	/** Reserved CONTEXT key carrying the vo-specific runtime metadata. */
	public const CONTEXT_VO_KEY = 'vo';

	/** Named application lock prefix serialising the CONTEXT read-modify-write (M1). */
	private const CONTEXT_LOCK_PREFIX = 'disk_vo_session_ctx_';

	/** Seconds to wait for the per-session CONTEXT lock. */
	private const CONTEXT_LOCK_TIMEOUT = 5;
	private const EDIT_SESSION_LOCK_PREFIX = 'disk_vo_edit_';

	/**
	 * Sub-key (inside {@see self::CONTEXT_VO_KEY}) carrying the file `GLOBAL_CONTENT_VERSION`
	 * captured when the session was created. {@see DocumentSession::isOutdatedByFileContent()}
	 * reads it (via {@see DocumentSession::getContentVersionSnapshot()}) to rotate the docKey
	 * of a stale VIEW session by monotonic content version instead of a second-granular
	 * timestamp. Written only here, so non-vibeoffice sessions keep the legacy timestamp check.
	 */
	public const CONTEXT_VO_CONTENT_VERSION_KEY = 'contentVersion';

	/** Immutable file-version boundary captured when the local generation is bound. */
	public const CONTEXT_VO_SAVED_VERSION_ID_KEY = 'savedVersionId';

	/**
	 * Sub-key (inside {@see self::CONTEXT_VO_KEY}) carrying the editor visual mode
	 * ({@see \Bitrix\Disk\Document\OnlyOffice\Editor\ConfigBuilder::VISUAL_MODE_USUAL} /
	 * {@see \Bitrix\Disk\Document\OnlyOffice\Editor\ConfigBuilder::VISUAL_MODE_COMPACT}) requested
	 * when the session was opened. The config channel resolves the session by hash alone (the
	 * editorMode of the load* action never reaches it directly), so it is persisted here and read
	 * back in {@see \Bitrix\Disk\Controller\Vibeoffice::buildOpenBody()} to inject `editor.ui.mode`.
	 * Written only for a compact open (messenger-call point #4); its absence means the full editor.
	 */
	public const CONTEXT_VO_EDITOR_MODE_KEY = 'editorMode';

	/**
	 * Sub-key (inside {@see self::CONTEXT_VO_KEY}) carrying the map openToken => origin|null declared by
	 * that open attempt. A null value is an explicit omission; a missing token is a legacy attempt.
	 */
	public const CONTEXT_VO_CLIENT_ORIGINS_KEY = 'clientOrigins';

	/** Sub-key carrying the map openToken => {@see OpenAttemptState} of attempts reserved with a state. */
	public const CONTEXT_VO_OPEN_ATTEMPTS_KEY = 'openAttempts';

	/** Sub-key carrying the token of the latest attempt reserved with a state. */
	public const CONTEXT_VO_LAST_OPEN_TOKEN_KEY = 'lastOpenToken';

	/**
	 * Sub-key carrying the map participant => token of the latest attempt of that participant. Visitors of
	 * one public link share the local session, yet each of them opens with an identity of its own.
	 */
	public const CONTEXT_VO_PARTICIPANT_OPEN_TOKENS_KEY = 'participantOpenTokens';

	/**
	 * Sub-key carrying the registry ID of the first attempt reserved with a state: a pending row below it
	 * without a state is a legacy attempt, one above it was given up by the bound of the attempt states.
	 */
	public const CONTEXT_VO_OPEN_ATTEMPT_FLOOR_KEY = 'openAttemptFloor';

	protected DocumentService|null $service = DocumentService::Vibeoffice;
	private ?EditSessionAdmission $editSessionAdmission = null;
	private DocumentStateRepository $stateRepository;
	private ?string $preparedExternalHash = null;
	private ?string $restrictionOutcome = null;
	private array $ownedRows = [];
	private array $selectionErrors = [];
	private bool $sessionCreated = false;

	public function __construct(
		?EditSessionAdmission $editSessionAdmission = null,
		?DocumentStateRepository $stateRepository = null,
	)
	{
		parent::__construct();

		$this->editSessionAdmission = $editSessionAdmission;
		$this->stateRepository = $stateRepository ?? new DocumentStateRepository();
	}

	public function prepareExternalHash(): string
	{
		$externalHash = DocumentSessionTable::generateDocumentKey();
		$this->preparedExternalHash = $externalHash;
		$this->setExternalHash($externalHash);

		return $externalHash;
	}

	/** Internal selection receipt; ownership belongs only to a successfully created VIEW. */
	public function findOrCreateSessionWithOwnership(bool $exactUser = false): Result
	{
		$session = $this->findOrCreateSession($exactUser);
		$result = (new Result())->setData([
			'session' => $session,
			'selection' => $session === null ? 'none' : ($this->sessionCreated ? 'new' : 'reused'),
			'ownedRows' => $session === null ? [] : $this->ownedRows,
		]);
		if ($session === null)
		{
			$result->addErrors($this->selectionErrors);
		}

		return $result;
	}

	/** Snapshot shared by ownership receipts and the exact source EDIT of a refused attempt. */
	public static function snapshotSession(DocumentSession $session): array
	{
		return [
			'sessionId' => (int)$session->getId(),
			'externalHash' => $session->getExternalHash(),
			'hostDocKey' => HostDocumentKey::resolve($session),
			'objectId' => (int)$session->getObjectId(),
			'versionId' => $session->getVersionId(),
			'attachedObjectId' => $session->getContext()?->getAttachedObjectId(),
			'userId' => $session->getUserId(),
			'externalLinkId' => $session->getExternalLinkId(),
			'type' => $session->getType(),
			'service' => $session->getService()?->value,
		];
	}

	public function findOrCreateSession($exactUser = false): ?DocumentSession
	{
		$this->ownedRows = [];
		$this->sessionCreated = false;
		$this->selectionErrors = [];
		$this->refreshLiveFile();
		$this->restrictionOutcome = null;

		if ($this->sessionType !== DocumentSession::TYPE_EDIT)
		{
			return $this->findOrCreatePinnedSession($exactUser);
		}

		$requestedSessionType = $this->sessionType;
		$reservationKey = $this->prepareExternalHash();
		$ticket = $this->getEditSessionAdmission()->admit(
			$this->getAdmissionObjectId(),
			$this->userId,
			$reservationKey,
		);
		if (!$ticket->isAllowed())
		{
			$this->restrictionOutcome = $ticket->getOutcome();
			$this->sessionType = DocumentSession::TYPE_VIEW;
		}

		try
		{
			return $this->findOrCreatePinnedSession($exactUser);
		}
		finally
		{
			$this->sessionType = $requestedSessionType;
		}
	}

	private function findOrCreatePinnedSession(bool $exactUser): ?DocumentSession
	{
		$session = $this->findSession($exactUser);
		if (!$session)
		{
			return $this->addSession();
		}

		$file = $this->file ?: $this->attachedObject?->getFile();
		if ($file && !$this->stateRepository->isCurrentGeneration($session, $file))
		{
			return $this->addSession();
		}

		if ($session->isNonActive())
		{
			return $this->createPinnedSession(
				fn() => $session->cloneWithNewHash($this->getUserId(), $this->sessionContext),
				errorSource: $session,
			);
		}

		if (!$session->belongsToUser($this->getUserId()))
		{
			return $this->createPinnedSession(
				fn() => $session->forkForUser($this->getUserId(), $this->sessionContext),
				$session,
			);
		}

		return $session;
	}

	private function createPinnedSession(
		callable $factory,
		?DocumentSession $source = null,
		?DocumentSession $errorSource = null,
	): ?DocumentSession
	{
		$errorSource ??= $source;
		$previousErrors = $this->getErrors();
		$previousSourceErrors = $errorSource?->getErrors() ?? [];
		$result = $this->stateRepository->createSession($factory, $source);
		if (!$result->isSuccess())
		{
			$this->errorCollection->add(array_filter(
				$errorSource?->getErrors() ?? [],
				static fn(Error $error): bool => !in_array($error, $previousSourceErrors, true),
			));
			$this->selectionErrors = $result->getErrors();
			foreach ($this->getErrors() as $error)
			{
				if (!in_array($error, $previousErrors, true))
				{
					$this->selectionErrors[] = $error;
				}
			}
			$this->errorCollection->add($result->getErrors());

			return null;
		}

		$session = $result->getData()['session'];
		$this->sessionCreated = true;
		if ($session->isView())
		{
			$this->ownedRows[] = self::snapshotSession($session);
		}

		return $session;
	}

	public function getRestrictionOutcome(): ?string
	{
		return $this->restrictionOutcome;
	}

	protected function getLockKey(): string
	{
		if ($this->sessionType !== DocumentSession::TYPE_EDIT)
		{
			return parent::getLockKey();
		}

		return self::EDIT_SESSION_LOCK_PREFIX . $this->getAdmissionObjectId() . '_' . (int)$this->userId;
	}

	private function getEditSessionAdmission(): EditSessionAdmission
	{
		return $this->editSessionAdmission ??= new EditSessionAdmission();
	}

	private function getAdmissionObjectId(): int
	{
		if ($this->version)
		{
			return (int)$this->version->getObjectId();
		}

		if ($this->file)
		{
			return (int)$this->file->getRealObjectId();
		}

		if ($this->attachedObject)
		{
			return (int)$this->attachedObject->getObjectId();
		}

		return (int)$this->sessionContext->getObjectId();
	}

	protected function buildFields(): array
	{
		$fields = parent::buildFields();
		$fields['TYPE'] = $this->getEffectiveSessionType();
		if ($this->preparedExternalHash !== null)
		{
			$fields['EXTERNAL_HASH'] = $this->preparedExternalHash;
		}

		return $fields;
	}

	/**
	 * Keeps the two opening channels apart: an internal opening (no `externalLinkId` in the session
	 * context) must not pick up a session created behind a public link, because the internal config
	 * channel refuses a session carrying an `ExternalLink`
	 * ({@see \Bitrix\Disk\Controller\Vibeoffice::getEditorConfigForUnifiedLinkAction()}) and the editor
	 * would only render the generic error state. An external opening keeps the same condition the base
	 * {@see SessionManager::findSession()} already applies for its own link.
	 */
	protected function buildFilter(): array
	{
		$filter = parent::buildFilter();
		unset($filter['=EXTERNAL_HASH']);
		$filter['=EXTERNAL_LINK_ID'] = $this->sessionContext?->getExternalLinkId();

		return $filter;
	}

	public function addSession(): ?DocumentSession
	{
		return $this->createPinnedSession(fn() => parent::addSession());
	}

	/**
	 * Persists one consistent snapshot of the file content version and latest immutable version ID.
	 */
	public function persistContentVersionSnapshot(DocumentSession $documentSession, ?File $file = null): bool
	{
		$objectId = $file ? (int)$file->getRealObjectId() : (int)$documentSession->getObjectId();
		if ($objectId !== (int)$documentSession->getObjectId())
		{
			return false;
		}
		$snapshot = FileTable::query()
			->setSelect(['GLOBAL_CONTENT_VERSION', 'SAVED_VERSION_ID'])
			->registerRuntimeField(new Reference(
				'SAVED_VERSION',
				VersionTable::class,
				['=this.ID' => 'ref.OBJECT_ID'],
				['join_type' => 'LEFT'],
			))
			->registerRuntimeField(new ExpressionField('SAVED_VERSION_ID', 'MAX(%s)', ['SAVED_VERSION.ID']))
			->where('ID', $objectId)
			->setGroup(['ID', 'GLOBAL_CONTENT_VERSION'])
			->fetch()
		;
		if (!$snapshot || $snapshot['GLOBAL_CONTENT_VERSION'] === null)
		{
			return false;
		}

		return $this->persistSessionMetadata($documentSession, [
			self::CONTEXT_VO_CONTENT_VERSION_KEY => (int)$snapshot['GLOBAL_CONTENT_VERSION'],
			self::CONTEXT_VO_SAVED_VERSION_ID_KEY => (int)$snapshot['SAVED_VERSION_ID'],
		]);
	}

	/** Returns the immutable latest version ID of a real file, or zero before its first version. */
	public static function getLatestSavedVersionId(int $objectId): int
	{
		$row = VersionTable::query()
			->setSelect(['ID'])
			->where('OBJECT_ID', $objectId)
			->setOrder(['ID' => 'DESC'])
			->setLimit(1)
			->fetch()
		;

		return $row ? (int)$row['ID'] : 0;
	}

	public function cloneSessionWithCurrentContentVersion(
		DocumentSession $documentSession,
		int $userId,
	): ?DocumentSession
	{
		$file = File::loadById($documentSession->getObjectId()) ?: $documentSession->getFile();
		$this
			->setFile($file)
			->setVersion($documentSession->getVersion())
		;

		return $this->createPinnedSession(function () use ($documentSession, $userId): ?DocumentSession {
			$fork = $documentSession->cloneWithNewHash($userId, $documentSession->getContext());
			$mode = self::extractVoMetadata($documentSession)[self::CONTEXT_VO_EDITOR_MODE_KEY] ?? null;
			if ($fork && $mode !== null && !$this->persistSessionMetadata($fork, [self::CONTEXT_VO_EDITOR_MODE_KEY => $mode]))
			{
				return null;
			}

			return $fork;
		}, errorSource: $documentSession);
	}

	/**
	 * Current monotonic content version of the live file the session points at.
	 *
	 * Deliberately NOT set for a version-pinned session ({@see DocumentSession::isVersion()}):
	 * such a view streams a fixed historical version, while
	 * {@see DocumentSession::isOutdatedByFileContent()} compares against the live file's
	 * version — stamping it would rotate the docKey on every open as soon as the live file
	 * moves ahead. Version-pinned sessions therefore keep the legacy timestamp check
	 * (which never rotates them either).
	 *
	 * The live file is resolved from {@see SessionManager::$file} when an explicit file was
	 * set, but the typical Disk path (CRM/comments/activity stream) opens a document through
	 * an {@see \Bitrix\Disk\AttachedObject} and leaves `$this->file` null (the controller
	 * calls `setFile($object)` with a null `$object`, resolving the real file as
	 * `$attachedObject->getFile()`). In that case we fall back to the attached object's live
	 * file ({@see \Bitrix\Disk\AttachedObject::getFile()} returns the underlying
	 * {@see \Bitrix\Disk\File} or null), so attachedObject-opened VIEW sessions also get the
	 * content-version snapshot. Returns null when no live file is available (best-effort).
	 */
	private function resolveContentVersion(): ?int
	{
		if ($this->version)
		{
			return null;
		}

		$file = $this->file ?: $this->attachedObject?->getFile();
		if ($file)
		{
			return (int)$file->getGlobalContentVersion();
		}

		return null;
	}

	private function refreshLiveFile(): void
	{
		if ($this->version || $this->attachedObject?->isSpecificVersion())
		{
			return;
		}

		$file = $this->file ?: $this->attachedObject?->getFile();
		if (!$file)
		{
			return;
		}

		$freshFile = File::loadById($file->getId());
		if ($freshFile)
		{
			$this->file = $freshFile;
		}
	}

	/**
	 * Persists the minimal vo-metadata subset host needs to re-issue the config and to
	 * drain (Q-3): the platform `session_id`, the last `editor_key`, `rev`. Stored under
	 * the reserved {@see self::CONTEXT_VO_KEY} sub-key of the session CONTEXT JSON, merged
	 * over any previously stored vo-metadata, alongside (never overwriting) the base
	 * DocumentSessionContext keys.
	 *
	 * Failover/refresh itself is driven by the frontend helper over SSE; the host only
	 * keeps what it needs to resolve `refresh_url`/re-open and for drain.
	 *
	 * @param array<string, mixed> $metadata e.g. ['session_id' => …, 'editor_key' => …, 'rev' => …]
	 */
	public function persistSessionMetadata(DocumentSession $documentSession, array $metadata): bool
	{
		$metadata = array_filter($metadata, static fn($value) => $value !== null);
		if (empty($metadata))
		{
			return false;
		}

		return $this->mergeVoMetadata($documentSession, static function (array $existingVo) use ($metadata): ?array {
			if (
				isset($existingVo[HostDocumentKey::CONTEXT_KEY], $metadata[HostDocumentKey::CONTEXT_KEY])
				&& $existingVo[HostDocumentKey::CONTEXT_KEY] !== $metadata[HostDocumentKey::CONTEXT_KEY]
			)
			{
				return null;
			}

			return array_merge($existingVo, $metadata);
		});
	}

	/**
	 * Records the origin (or its omission) an open attempt declares. The snapshot of a token is
	 * immutable: a repeated call for a known token keeps the stored value.
	 */
	public function persistClientOriginSnapshot(DocumentSession $session, string $openToken, ?string $origin): bool
	{
		if ($openToken === '')
		{
			return false;
		}

		return $this->mergeVoMetadata($session, static function (array $existingVo) use ($openToken, $origin): ?array {
			$origins = $existingVo[self::CONTEXT_VO_CLIENT_ORIGINS_KEY] ?? [];
			if (!is_array($origins))
			{
				return null;
			}
			if (array_key_exists($openToken, $origins))
			{
				return $existingVo;
			}
			$origins[$openToken] = $origin;
			$existingVo[self::CONTEXT_VO_CLIENT_ORIGINS_KEY] = $origins;

			return $existingVo;
		});
	}

	/**
	 * Applies a transition of the open attempt states on the freshest vo metadata. The transition gets the
	 * whole fresh vo array and returns it changed, or null to refuse the write.
	 *
	 * @param callable(array): ?array $transition
	 */
	public function persistOpenAttemptTransition(DocumentSession $session, callable $transition): bool
	{
		return $this->mergeVoMetadata($session, $transition);
	}

	/**
	 * Read-modify-write of the vo sub-key on the freshest CONTEXT, under the per-session lock and
	 * inside the caller's transaction when there is one. The merge returns null to refuse the write.
	 *
	 * @param callable(array): ?array $merge
	 */
	private function mergeVoMetadata(DocumentSession $documentSession, callable $merge): bool
	{
		$sessionId = (int)$documentSession->getId();
		if (!$sessionId)
		{
			return false;
		}

		$connection = Application::getConnection();
		$lockName = self::getSessionContextLockName($sessionId);
		$locked = $connection->lock($lockName, self::CONTEXT_LOCK_TIMEOUT);
		if (!$locked)
		{
			return false;
		}

		$ownsTransaction = false;
		try
		{
			if (!$this->hasTransaction())
			{
				$connection->startTransaction();
				$ownsTransaction = true;
			}
			$rawContext = $this->readFreshContextRaw($sessionId);
			if ($rawContext === null)
			{
				return false;
			}
			$context = $this->decodeContext($rawContext);

			$existingVo = is_array($context[self::CONTEXT_VO_KEY] ?? null) ? $context[self::CONTEXT_VO_KEY] : [];
			$vo = $merge($existingVo);
			if ($vo === null)
			{
				return false;
			}
			$context[self::CONTEXT_VO_KEY] = $vo;

			$result = DocumentSessionTable::update($sessionId, [
				'CONTEXT' => Json::encode($context),
			]);
			if ($result->isSuccess() && $ownsTransaction)
			{
				$connection->commitTransaction();
				$ownsTransaction = false;
			}

			return $result->isSuccess();
		}
		finally
		{
			try
			{
				if ($ownsTransaction)
				{
					$connection->rollbackTransaction();
				}
			}
			finally
			{
				$connection->unlock($lockName);
			}
		}
	}

	/**
	 * Re-reads the freshest CONTEXT JSON straight from the DB (bypassing any model-level cache)
	 * so the merge in {@see self::persistSessionMetadata()} runs on the latest stored value.
	 */
	private function readFreshContextRaw(int $sessionId): ?string
	{
		$query = DocumentSessionTable::query()->setSelect(['CONTEXT'])->where('ID', $sessionId);
		$context = DocumentStateRepository::fetchForUpdate($query)['CONTEXT'] ?? null;

		return is_string($context) ? $context : null;
	}

	public static function getSessionContextLockName(int $sessionId): string
	{
		return self::CONTEXT_LOCK_PREFIX . $sessionId;
	}

	private function hasTransaction(): bool
	{
		return DocumentStateRepository::hasOpenTransaction();
	}

	/**
	 * Reads the vo-metadata subset previously stored by {@see self::persistSessionMetadata()}.
	 *
	 * @return array<string, mixed>
	 */
	public static function extractVoMetadata(DocumentSession $documentSession): array
	{
		$context = self::decodeContext($documentSession->getContextRaw());

		$vo = $context[self::CONTEXT_VO_KEY] ?? null;

		return is_array($vo) ? $vo : [];
	}

	/**
	 * Decodes the session CONTEXT JSON into an array. Returns an empty array when the raw
	 * value is null/empty, the JSON is invalid, or it does not decode to an array.
	 *
	 * @return array<string, mixed>
	 */
	private static function decodeContext(?string $raw): array
	{
		if ($raw === null || $raw === '')
		{
			return [];
		}

		try
		{
			$decoded = Json::decode($raw);
		}
		catch (\Throwable)
		{
			return [];
		}

		return is_array($decoded) ? $decoded : [];
	}
}
