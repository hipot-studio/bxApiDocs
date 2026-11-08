<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Vibeoffice;

use Bitrix\Disk\Document\Models\DocumentSession;
use Bitrix\Disk\ExternalLink;
use Bitrix\Disk\File;
use Bitrix\Disk\Internal\Service\Audit\AuditTransaction;
use Bitrix\Disk\Internal\Service\Audit\AuditTransactionException;
use Bitrix\Disk\Internal\Service\Audit\AuditTransactionOutcome;
use Bitrix\Disk\Version;
use Bitrix\Main\Application;
use Bitrix\Main\DB\Connection;
use Bitrix\Main\DB\TransactionException;
use Bitrix\Main\Diag\LoggerFactory;
use Bitrix\Main\Error;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Result;
use Bitrix\Main\UuidGenerator;

class DocumentStateRepository
{
	private const LOCK_TIMEOUT = 5;

	/** Diagnostic channel of the document state; `.settings.php` redirects it by id. */
	private const LOGGER_ID = 'disk.vibeoffice.state';

	private static array $heldLocks = [];

	public function __construct(private readonly ?Configuration $configuration = null)
	{
	}

	public function getByKey(string $hostDocKey): ?array
	{
		return Models\DocumentStateTable::query()
			->setSelect(['*'])
			->where('HOST_DOC_KEY', $hostDocKey)
			->fetch() ?: null
		;
	}

	public function getSnapshot(string $hostDocKey, int $snapshotSeq): ?array
	{
		return Models\SavedSnapshotTable::query()
			->setSelect(['*'])
			->where('HOST_DOC_KEY', $hostDocKey)
			->where('SNAPSHOT_SEQ', $snapshotSeq)
			->fetch() ?: null
		;
	}

	/**
	 * Lock order: document named lock, transaction, optional object row, session/ledger rows.
	 * The callback must not perform network I/O. Nested transactions leave the caller's commit intact.
	 * The named lock alone serializes state writers, so the state row itself is read without a row lock.
	 * Audited file changes own the transaction and cannot join an external SQL transaction.
	 *
	 * @param callable(array, ?File): Result $mutation
	 */
	public function withLockedDocument(
		string $hostDocKey,
		callable $mutation,
		bool $lockObject = false,
		bool $auditFileChanges = false,
	): Result
	{
		return $this->runLocked(
			self::getDocumentLockName($hostDocKey),
			function () use ($hostDocKey, $mutation, $lockObject): Result {
				$file = null;
				if ($lockObject)
				{
					$objectId = (int)($this->getByKey($hostDocKey)['OBJECT_ID'] ?? 0);
					$file = $objectId > 0 ? $this->loadLockedFile($objectId) : null;
					if (!$file)
					{
						return $this->failure('Vibeoffice document was not found.', 'vibeoffice_document_not_found');
					}
				}

				// Read after the object row lock: an external writer could have advanced the content.
				$state = $this->getByKey($hostDocKey);
				if (!$state)
				{
					return $this->failure('Vibeoffice document was not found.', 'vibeoffice_document_not_found');
				}

				return $mutation($state, $file);
			},
			$auditFileChanges,
		);
	}

	public static function getDocumentLockName(string $hostDocKey): string
	{
		return 'disk_vo_document_' . md5($hostDocKey);
	}

	/** Locking read of an ORM query. The whole module goes through this method instead of its own SQL. */
	public static function fetchForUpdate(Query $query): ?array
	{
		return self::queryForUpdate($query)->fetch() ?: null;
	}

	/** @return array[] */
	public static function fetchAllForUpdate(Query $query): array
	{
		return self::queryForUpdate($query)->fetchAll();
	}

	/**
	 * The core keeps its nesting level private, so the state of an external transaction is only
	 * readable through reflection. This is the single place in Vibeoffice that does it.
	 */
	public static function hasOpenTransaction(?Connection $connection = null): bool
	{
		$connection ??= Application::getConnection();
		$reflection = new \ReflectionObject($connection);
		do
		{
			if ($reflection->hasProperty('transactionLevel'))
			{
				return (int)$reflection->getProperty('transactionLevel')->getValue($connection) > 0;
			}
			$reflection = $reflection->getParentClass();
		}
		while ($reflection !== false);

		throw new \LogicException('Cannot determine the Vibeoffice connection transaction state.');
	}

	public static function isDocumentLocked(string $hostDocKey): bool
	{
		$lockId = self::lockId(Application::getConnection(), self::getDocumentLockName($hostDocKey));

		return isset(self::$heldLocks[$lockId]);
	}

	/**
	 * Takes the document lock and registers it, so the guards asking isDocumentLocked() see it, but
	 * opens no transaction: the caller may need to remove a blob, which is irreversible.
	 * Returns false when the lock belongs to somebody else.
	 */
	public static function runUnderDocumentLock(string $hostDocKey, callable $work, int $timeout = 0): bool
	{
		$connection = Application::getConnection();
		$lockName = self::getDocumentLockName($hostDocKey);
		if (!self::acquireLock($connection, $lockName, $timeout))
		{
			return false;
		}

		try
		{
			$work();
		}
		finally
		{
			self::releaseLock($connection, $lockName);
		}

		return true;
	}

	/** The optional source preserves a shared local hash across a user fork, including legacy mode. */
	public function bindNewGeneration(DocumentSession $session, ?DocumentSession $source = null): Result
	{
		$file = File::loadById((int)$session->getObjectId());
		if (!$file || !$session->getId())
		{
			return $this->failure('Vibeoffice source file was not found.', 'vibeoffice_source_not_found');
		}

		try
		{
			$scope = HostDocumentKey::createScope($session, $file);
		}
		catch (\Throwable)
		{
			return $this->failure('Invalid Vibeoffice document scope.', 'vibeoffice_scope_invalid');
		}

		return $this->runLocked(
			'disk_vo_scope_' . md5(HostDocumentKey::scopeIdentity($scope)),
			function () use ($session, $source, $scope): Result {
				$file = $this->loadLockedFile($scope['OBJECT_ID']);
				if (!$file)
				{
					return $this->failure('Vibeoffice source file was not found.', 'vibeoffice_source_not_found');
				}

				$session = DocumentSession::loadById((int)$session->getId());
				if (!$file || !$session || HostDocumentKey::createScope($session, $file) !== $scope)
				{
					return $this->failure('Vibeoffice document scope changed.', 'vibeoffice_scope_invalid');
				}

				if (!$this->hasValidExternalLink($session, $file))
				{
					return $this->failure('Invalid Vibeoffice external link.', 'vibeoffice_scope_invalid');
				}

				$metadata = DocumentSessionManager::extractVoMetadata($session);
				$pinnedKey = $metadata[HostDocumentKey::CONTEXT_KEY] ?? null;
				if (is_string($pinnedKey) && $pinnedKey !== '')
				{
					$state = $this->findStateForUpdate($pinnedKey);
					if (!$state || !$this->matchesScope($state, $scope))
					{
						return $this->failure('Invalid Vibeoffice document binding.', 'vibeoffice_binding_invalid');
					}

					return $this->boundResult($session, $state);
				}

				$content = $this->readContent($session, $file);
				if ($content === null)
				{
					return $this->failure('Vibeoffice source version was not found.', 'vibeoffice_source_not_found');
				}

				$generation = HostDocumentKey::contentGeneration(
					$scope,
					$content['EXPECTED_FILE_ID'],
					$content['EXPECTED_CONTENT_VERSION'],
				);
				$mode = HostDocumentKey::MODE_LEGACY;
				$key = $session->getExternalHash();
				if ($source !== null)
				{
					if (
						$source->getExternalHash() !== $session->getExternalHash()
						|| HostDocumentKey::createScope($source, $file) !== $scope
					)
					{
						return $this->failure('Invalid Vibeoffice fork source.', 'vibeoffice_binding_invalid');
					}

					$key = HostDocumentKey::resolve($source);
					$mode = HostDocumentKey::isStable($key) ? HostDocumentKey::MODE_STABLE : $mode;
				}
				elseif (($this->configuration ?? new Configuration())->isStableDocumentKeyEnabled())
				{
					$mode = HostDocumentKey::MODE_STABLE;
					$state = $this->findLatestStable($scope);
					if ($state && $this->matchesContent($state, $content) && !(int)$state['CONFLICTED'])
					{
						$key = $state['HOST_DOC_KEY'];
					}
					else
					{
						if ($session->isEdit())
						{
							$generation = hash('sha256', $generation . ':' . UuidGenerator::generateV4());
						}
						$key = HostDocumentKey::create($scope, $generation);
					}
				}

				$state = $this->findStateForUpdate($key);
				if ($state !== null && !$this->matchesScope($state, $scope))
				{
					return $this->failure('Invalid Vibeoffice document binding.', 'vibeoffice_binding_invalid');
				}
				if ($state === null)
				{
					if ($source !== null && $mode === HostDocumentKey::MODE_STABLE)
					{
						return $this->failure('Vibeoffice fork state was not found.', 'vibeoffice_binding_invalid');
					}

					$add = Models\DocumentStateTable::add($scope + $content + [
						'HOST_DOC_KEY' => $key,
						'KEY_MODE' => $mode,
						'CONTENT_GENERATION' => $generation,
					]);
					if (!$add->isSuccess())
					{
						return (new Result())->addErrors($add->getErrors());
					}
					$state = $this->findStateForUpdate($key);
				}

				$metadata = [HostDocumentKey::CONTEXT_KEY => $key];
				if (!$session->isVersion())
				{
					$metadata[DocumentSessionManager::CONTEXT_VO_CONTENT_VERSION_KEY] =
						$content['EXPECTED_CONTENT_VERSION'];
					$metadata['contentFileId'] = $content['EXPECTED_FILE_ID'];
				}
				if (!(new DocumentSessionManager())->persistSessionMetadata($session, $metadata))
				{
					return $this->failure('Could not pin the Vibeoffice document key.', 'vibeoffice_binding_failed');
				}

				return $this->boundResult(DocumentSession::loadById((int)$session->getId()), $state);
			},
		);
	}

	/** Create and pin a local generation atomically, including direct model fork/clone paths. */
	public function createSession(callable $factory, ?DocumentSession $source = null): Result
	{
		return $this->runTransaction(function () use ($factory, $source): Result {
			$session = $factory();

			return $session instanceof DocumentSession
				? $this->bindNewGeneration($session, $source)
				: $this->failure('Could not create a Vibeoffice session.', 'vibeoffice_session_failed');
		});
	}

	public function ensureSessionState(DocumentSession $session): Result
	{
		return $this->bindNewGeneration($session, $session);
	}

	public function isCurrentGeneration(DocumentSession $session, File $freshFile): bool
	{
		if ($session->isVersion())
		{
			return true;
		}

		$state = $this->getByKey(HostDocumentKey::resolve($session));
		if ($state)
		{
			return !(int)$state['CONFLICTED'] && $this->matchesContent($state, $this->liveContent($freshFile));
		}

		return !$session->isView() || !$session->isOutdatedByFileContent();
	}

	private static function queryForUpdate(Query $query): \Bitrix\Main\DB\Result
	{
		return Application::getConnection()->query($query->getQuery() . ' FOR UPDATE');
	}

	private function findLatestStable(array $scope): ?array
	{
		$query = Models\DocumentStateTable::query()->setSelect(['*']);
		foreach ($scope as $field => $value)
		{
			$query->where($field, $value);
		}

		$query
			->where('KEY_MODE', HostDocumentKey::MODE_STABLE)
			->setOrder(['ID' => 'DESC'])
			->setLimit(1);

		return self::fetchForUpdate($query);
	}

	private function findStateForUpdate(string $key): ?array
	{
		$query = Models\DocumentStateTable::query()->setSelect(['*'])->where('HOST_DOC_KEY', $key);

		return self::fetchForUpdate($query);
	}

	private function matchesScope(array $state, array $scope): bool
	{
		foreach ($scope as $field => $value)
		{
			if ((int)$state[$field] !== $value)
			{
				return false;
			}
		}

		return true;
	}

	private function matchesContent(array $state, array $content): bool
	{
		return (int)$state['EXPECTED_FILE_ID'] === $content['EXPECTED_FILE_ID']
			&& (int)$state['EXPECTED_CONTENT_VERSION'] === $content['EXPECTED_CONTENT_VERSION']
			&& ((int)$state['SESSION_TYPE'] === DocumentSession::TYPE_VIEW || (string)$state['EXPECTED_ETAG'] === $content['EXPECTED_ETAG']);
	}

	private function readContent(DocumentSession $session, File $file): ?array
	{
		if (!$session->isVersion())
		{
			return $this->liveContent($file);
		}

		$version = Version::loadById((int)$session->getVersionId());
		if (!$version || (int)$version->getObjectId() !== (int)$file->getRealObjectId())
		{
			return null;
		}

		return [
			'EXPECTED_FILE_ID' => (int)$version->getFileId(),
			'EXPECTED_CONTENT_VERSION' => (int)$version->getGlobalContentVersion(),
			'EXPECTED_ETAG' => '',
		];
	}

	private function liveContent(File $file): array
	{
		return [
			'EXPECTED_FILE_ID' => (int)$file->getFileId(),
			'EXPECTED_CONTENT_VERSION' => (int)$file->getGlobalContentVersion(),
			'EXPECTED_ETAG' => (string)$file->getEtag(),
		];
	}

	private function hasValidExternalLink(DocumentSession $session, File $file): bool
	{
		if (!$session->getExternalLinkId())
		{
			return true;
		}

		$link = ExternalLink::loadById($session->getExternalLinkId());

		return $link
			&& (int)$link->getObject()?->getRealObjectId() === (int)$file->getRealObjectId()
			&& (int)$link->getVersionId() === (int)$session->getVersionId();
	}

	private function loadLockedFile(int $objectId): ?File
	{
		$query = \Bitrix\Disk\Internals\ObjectTable::query()->setSelect(['*'])->where('ID', $objectId);
		$row = self::fetchForUpdate($query);

		return $row ? File::buildFromArray($row) : null;
	}

	private function boundResult(DocumentSession $session, array $state): Result
	{
		return (new Result())->setData([
			'hostDocKey' => $state['HOST_DOC_KEY'],
			'state' => $state,
			'session' => $session,
		]);
	}

	private function runLocked(string $lockName, callable $mutation, bool $auditFileChanges = false): Result
	{
		$connection = Application::getConnection();
		if (!self::acquireLock($connection, $lockName, self::LOCK_TIMEOUT))
		{
			return $this->failure('Could not lock the Vibeoffice document.', 'vibeoffice_lock_timeout');
		}

		try
		{
			// Update lines shipped without the Disk audit (26.1300.0) have no AuditTransaction class.
			$auditable = $auditFileChanges && class_exists(AuditTransaction::class);

			return $auditable ? $this->runAuditTransaction($mutation) : $this->runTransaction($mutation);
		}
		finally
		{
			self::releaseLock($connection, $lockName);
		}
	}

	private static function lockId(Connection $connection, string $lockName): string
	{
		return spl_object_id($connection) . ':' . $lockName;
	}

	/** A lock already held by this connection is only counted: the core does not nest a named lock. */
	private static function acquireLock(Connection $connection, string $lockName, int $timeout): bool
	{
		$lockId = self::lockId($connection, $lockName);
		if (!isset(self::$heldLocks[$lockId]) && !$connection->lock($lockName, $timeout))
		{
			return false;
		}
		self::$heldLocks[$lockId] = (self::$heldLocks[$lockId] ?? 0) + 1;

		return true;
	}

	private static function releaseLock(Connection $connection, string $lockName): void
	{
		$lockId = self::lockId($connection, $lockName);
		if (--self::$heldLocks[$lockId] === 0)
		{
			unset(self::$heldLocks[$lockId]);
			$connection->unlock($lockName);
		}
	}

	private function runAuditTransaction(callable $mutation): Result
	{
		$transaction = new AuditTransaction(Application::getConnection());
		try
		{
			$auditResult = $transaction->execute(static function () use ($mutation): AuditTransactionOutcome {
				$result = $mutation();

				return $result->isSuccess()
					? AuditTransactionOutcome::success($result)
					: AuditTransactionOutcome::failure($result);
			});
			$result = $auditResult->getOutcome()->getValue();
			if ($result instanceof Result && ($auditResult->isSuccess()
				|| ($auditResult->isRollbackConfirmed() && !$result->isSuccess()
					&& $auditResult->getCallbackExceptions() === []
					&& $auditResult->getCompensationExceptions() === [])))
			{
				return $result;
			}

			throw new AuditTransactionException($auditResult);
		}
		catch (\Throwable $exception)
		{
			$this->logStateException($exception);

			return $this->failure('Could not persist Vibeoffice state.', 'vibeoffice_state_failed');
		}
	}

	private function runTransaction(callable $mutation): Result
	{
		$connection = Application::getConnection();
		$transactionStarted = false;
		try
		{
			$connection->startTransaction();
			$transactionStarted = true;
			$result = $mutation();
			if ($result->isSuccess())
			{
				$connection->commitTransaction();
			}
			else
			{
				self::rollbackOwnLevel($connection);
			}
			$transactionStarted = false;

			return $result;
		}
		catch (\Throwable $exception)
		{
			if ($transactionStarted)
			{
				self::rollbackOwnLevel($connection);
			}
			$this->logStateException($exception);

			return $this->failure('Could not persist Vibeoffice state.', 'vibeoffice_state_failed');
		}
	}

	/** The core undoes a nested level on its own and only then reports it by throwing. */
	private static function rollbackOwnLevel(Connection $connection): void
	{
		try
		{
			$connection->rollbackTransaction();
		}
		catch (TransactionException $exception)
		{
			if ($exception->getMessage() !== 'Nested rollbacks are unsupported.')
			{
				throw $exception;
			}
		}
	}

	/**
	 * Keeps a rolled-back state mutation diagnosable: deadlocks and lock timeouts otherwise leave
	 * only the generic error. The record carries neither the document key nor a session hash.
	 */
	private function logStateException(\Throwable $exception): void
	{
		(new LoggerFactory())
			->createById(self::LOGGER_ID, isCheckEnabledFromRegistry: false)
			?->error('Vibeoffice state: {exception}: {message}.', [
				'exception' => $exception::class,
				'message' => $exception->getMessage(),
			])
		;
	}

	private function failure(string $message, string $code): Result
	{
		return (new Result())->addError(new Error($message, $code));
	}
}
