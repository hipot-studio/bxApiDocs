<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Vibeoffice;

use Bitrix\Disk\Document\Models\DocumentService;
use Bitrix\Disk\Document\Models\DocumentSession;
use Bitrix\Disk\Document\Models\DocumentSessionTable;
use Bitrix\Disk\Document\Vibeoffice\Models\PlatformSessionTable;
use Bitrix\Main\Application;
use Bitrix\Disk\Document\Vibeoffice\Logging\LoggerProvider;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Throwable;

final class TerminalSessionFinalizer implements TerminalSessionFinalizerInterface
{
	private const LOGGER_ID = 'disk.vibeoffice.finalizer';

	private DocumentStateRepository $repository;
	private ?string $lockedHostDocKey = null;

	/**
	 * Whether the finalization running right now also takes the local row of the session with it.
	 *
	 * Internal state rather than a parameter of {@see self::finalizeSessions()}: the contract of
	 * {@see TerminalSessionFinalizerInterface} is what the webhook and the drain call, and a
	 * parameter none of them can pass would be silently dropped by any decorator of theirs.
	 */
	private bool $removeFinalizedLocalSessions = false;

	public function __construct(?DocumentStateRepository $repository = null)
	{
		$this->repository = $repository ?? new DocumentStateRepository();
	}

	public function withDocumentTransaction(string $hostDocKey, callable $mutation): Result
	{
		if ($this->lockedHostDocKey !== null || DocumentStateRepository::isDocumentLocked($hostDocKey))
		{
			return (new Result())->addError(new Error('Finalization is already running.', 'vibeoffice_finalize_nested'));
		}
		try
		{
			$result = $this->repository->withLockedDocument(
				$hostDocKey,
				function (array $state) use ($hostDocKey, $mutation): Result {
					$this->lockedHostDocKey = $hostDocKey;

					return $mutation($state);
				},
			);

			return $result;
		}
		finally
		{
			$this->lockedHostDocKey = null;
		}
	}

	public function finalizeSessions(array $sessionIds): Result
	{
		if ($this->lockedHostDocKey === null)
		{
			return (new Result())->addError(new Error('Document transaction is required.', 'vibeoffice_finalize_unlocked'));
		}
		$sessionIds = array_values(array_unique(array_filter($sessionIds, static fn($id): bool => is_int($id) && $id > 0)));
		if ($sessionIds === [])
		{
			return (new Result())->setData(['sessionIds' => []]);
		}

		$registry = new SessionRegistry($this->repository);
		$finalized = [];
		foreach (array_chunk($sessionIds, 300) as $chunk)
		{
			$query = DocumentSessionTable::query()->setSelect(['*'])
				->whereIn('ID', $chunk)->where('SERVICE', DocumentService::Vibeoffice->value)
			;
			foreach (DocumentStateRepository::fetchAllForUpdate($query) as $row)
			{
				$session = DocumentSession::buildFromArray($row);
				$id = (int)$session->getId();
				if (HostDocumentKey::resolve($session) !== $this->lockedHostDocKey || $registry->hasLiveSessions($id))
				{
					continue;
				}
				if ((int)$row['STATUS'] === DocumentSessionTable::STATUS_ACTIVE)
				{
					$update = DocumentSessionTable::update($id, ['STATUS' => DocumentSessionTable::STATUS_NON_ACTIVE]);
					if (!$update->isSuccess())
					{
						return (new Result())->addErrors($update->getErrors());
					}
					$finalized[] = $id;
				}
				if ($this->removeFinalizedLocalSessions)
				{
					$delete = DocumentSessionTable::delete($id);
					if (!$delete->isSuccess())
					{
						return (new Result())->addErrors($delete->getErrors());
					}
				}
			}
		}

		return (new Result())->setData(['sessionIds' => $finalized]);
	}

	/**
	 * Internal receipt: snapshot fields, kind, selection, refused, nullable openToken and optional preserveLocalSession.
	 * A VIEW receipt must come from this attempt's manager-owned row, after preparation/open failed.
	 */
	public function releaseRefusedCapacityAttempt(array $receipt): Result
	{
		$sessionId = is_int($receipt['sessionId'] ?? null) ? $receipt['sessionId'] : 0;
		try
		{
			if (!$this->isValidCapacityReceipt($receipt))
			{
				$result = (new Result())->addError(new Error(
					'Invalid refused Vibeoffice attempt.', 'vibeoffice_capacity_receipt_invalid',
				));
			}
			elseif (!$receipt['refused'])
			{
				return $this->capacityReleaseStatus('preserved_live', $sessionId);
			}
			else
			{
				$result = $this->withDocumentTransaction($receipt['hostDocKey'],
					fn(): Result => $this->releaseLockedCapacityAttempt($receipt),
				);
			}
		}
		catch (Throwable)
		{
			$result = (new Result())->addError(new Error(
				'Could not release the refused Vibeoffice attempt.', 'vibeoffice_capacity_release_failed',
			));
		}

		if (!$result->isSuccess())
		{
			$result->setData([
				'status' => 'failed',
				'sessionId' => $sessionId,
			]);
			LoggerProvider::get(self::LOGGER_ID)->error('vibeoffice finalizer', [
				'operation' => 'finalize',
				'code' => 'finalizer_failed',
				'document_session_id' => $sessionId,
			]);
		}

		return $result;
	}

	private function releaseLockedCapacityAttempt(array $receipt): Result
	{
		$sessionId = $receipt['sessionId'];
		$row = DocumentStateRepository::fetchForUpdate(DocumentSessionTable::query()->setSelect(['*'])
			->where('ID', $sessionId)->where('SERVICE', DocumentService::Vibeoffice->value));
		if ($row)
		{
			$snapshot = DocumentSessionManager::snapshotSession(DocumentSession::buildFromArray($row));
			foreach ($snapshot as $field => $value)
			{
				if ($receipt[$field] !== $value)
				{
					return (new Result())->addError(new Error(
						'Refused Vibeoffice session changed.', 'vibeoffice_capacity_identity_mismatch',
					));
				}
			}
		}

		$registry = new SessionRegistry($this->repository);
		if ($receipt['openToken'] !== null)
		{
			$binding = DocumentStateRepository::fetchForUpdate(PlatformSessionTable::query()->setSelect(['*'])
				->where('OPEN_TOKEN', $receipt['openToken']));
			if ($binding)
			{
				if ((int)$binding['DOCUMENT_SESSION_ID'] !== $sessionId
					|| $binding['HOST_DOC_KEY'] !== $receipt['hostDocKey'])
				{
					return (new Result())->addError(new Error(
						'Refused Vibeoffice open binding changed.', 'vibeoffice_capacity_binding_mismatch',
					));
				}
				if ($binding['STATUS'] === PlatformSessionTable::STATUS_PENDING)
				{
					// The refusal proves the outcome only when no send of the token is left without its answer;
					// an attempt still owing one stays pending and keeps its session as a live claim.
					$cancel = $registry->cancelDefinitiveOpen($receipt['openToken']);
					if (!$cancel->isSuccess() && !self::isUnresolvedOpen($cancel))
					{
						return (new Result())->addErrors($cancel->getErrors());
					}
				}
			}
		}

		if (!$row)
		{
			return $this->capacityReleaseStatus('absent', $sessionId);
		}
		if ($receipt['kind'] === 'VIEW' && $receipt['selection'] !== 'new')
		{
			return $this->capacityReleaseStatus('preserved_reused', $sessionId);
		}
		// Cancellation can succeed without removing a lease completed by a concurrent open.
		if ($registry->hasLiveSessions($sessionId))
		{
			return $this->capacityReleaseStatus('preserved_live', $sessionId);
		}
		if (($receipt['preserveLocalSession'] ?? false) === true)
		{
			return $this->capacityReleaseStatus('preserved_unowned', $sessionId);
		}

		$delete = DocumentSessionTable::delete($sessionId);

		return $delete->isSuccess()
			? $this->capacityReleaseStatus('released', $sessionId)
			: (new Result())->addErrors($delete->getErrors());
	}

	private function isValidCapacityReceipt(array $receipt): bool
	{
		foreach (['sessionId', 'objectId', 'userId', 'type'] as $field)
		{
			if (!isset($receipt[$field]) || !is_int($receipt[$field]))
			{
				return false;
			}
		}
		foreach (['versionId', 'attachedObjectId', 'externalLinkId'] as $field)
		{
			if (!array_key_exists($field, $receipt)
				|| ($receipt[$field] !== null && (!is_int($receipt[$field]) || $receipt[$field] < 0)))
			{
				return false;
			}
		}
		foreach (['externalHash', 'hostDocKey'] as $field)
		{
			if (!is_string($receipt[$field] ?? null) || $receipt[$field] === '')
			{
				return false;
			}
		}

		return $receipt['sessionId'] > 0 && $receipt['objectId'] > 0
			&& ($receipt['service'] ?? null) === DocumentService::Vibeoffice->value
			&& in_array($receipt['kind'] ?? null, ['EDIT', 'VIEW'], true)
			&& $receipt['type'] === ($receipt['kind'] === 'EDIT'
				? DocumentSession::TYPE_EDIT : DocumentSession::TYPE_VIEW)
			&& in_array($receipt['selection'] ?? null, ['new', 'reused'], true)
			&& is_bool($receipt['refused'] ?? null)
			&& (!array_key_exists('preserveLocalSession', $receipt) || is_bool($receipt['preserveLocalSession']))
			&& array_key_exists('openToken', $receipt)
			&& ($receipt['openToken'] === null || (is_string($receipt['openToken'])
				&& preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/Di',
					$receipt['openToken']) === 1));
	}

	private static function isUnresolvedOpen(Result $result): bool
	{
		foreach ($result->getErrors() as $error)
		{
			if ($error->getCode() !== SessionRegistry::ERROR_CODE_OPEN_UNRESOLVED)
			{
				return false;
			}
		}

		return !$result->isSuccess();
	}

	private function capacityReleaseStatus(string $status, int $sessionId): Result
	{
		return (new Result())->setData([
			'status' => $status,
			'sessionId' => $sessionId,
		]);
	}

	/** Release a refused attempt only when the platform has no live session for it. */
	public function releaseRefusedEditSession(DocumentSession $session, bool $removeLocalSession = false): Result
	{
		if (!$session->isEdit()
			|| $session->getService() !== DocumentService::Vibeoffice || !$session->getId())
		{
			return new Result();
		}

		$hostDocKey = HostDocumentKey::resolve($session);
		if ($this->repository->getByKey($hostDocKey))
		{
			$this->removeFinalizedLocalSessions = $removeLocalSession;
			try
			{
				$result = $this->withDocumentTransaction(
					$hostDocKey,
					fn(): Result => $this->finalizeSessions([(int)$session->getId()]),
				);
			}
			finally
			{
				$this->removeFinalizedLocalSessions = false;
			}
		}
		elseif ((new SessionRegistry($this->repository))->hasLiveSessions((int)$session->getId()))
		{
			return new Result();
		}
		else
		{
			$result = $this->finalizeDrainSession($session);
		}

		if (!$result->isSuccess())
		{
			LoggerProvider::get(self::LOGGER_ID)->error('vibeoffice finalizer', [
				'operation' => 'finalize',
				'code' => 'finalizer_failed',
				'document_session_id' => (int)$session->getId(),
			]);
		}

		return $result;
	}

	public function finalizeDocument(string $externalHash): Result
	{
		return $this->finalize(
			function () use ($externalHash): Result {
				$rows = DocumentSessionTable::query()->setSelect(['*'])
					->where('EXTERNAL_HASH', $externalHash)
					->where('SERVICE', DocumentService::Vibeoffice->value)
					->where('STATUS', DocumentSessionTable::STATUS_ACTIVE)
					->exec()
				;
				while ($row = $rows->fetch())
				{
					if (HostDocumentKey::resolve(DocumentSession::buildFromArray($row)) !== $externalHash)
					{
						continue;
					}
					$update = DocumentSessionTable::update((int)$row['ID'], ['STATUS' => DocumentSessionTable::STATUS_NON_ACTIVE]);
					if (!$update->isSuccess())
					{
						return (new Result())->addErrors($update->getErrors());
					}
				}

				return new Result();
			},
		);
	}

	public function finalizeDrainSession(DocumentSession $session): Result
	{
		return $this->finalize(
			function () use ($session): Result {
				$deleteResult = DocumentSessionTable::delete((int)$session->getId());
				if (!$deleteResult->isSuccess())
				{
					return (new Result())->addErrors($deleteResult->getErrors());
				}

				return new Result();
			},
		);
	}

	public function finalizeDrainDocument(string $externalHash): Result
	{
		return $this->finalize(
			function () use ($externalHash): Result {
				$rows = DocumentSessionTable::query()
					->setSelect(['ID'])
					->where('EXTERNAL_HASH', $externalHash)
					->where('SERVICE', DocumentService::Vibeoffice->value)
					->where('STATUS', DocumentSessionTable::STATUS_ACTIVE)
					->fetchAll()
				;
				foreach ($rows as $row)
				{
					$deleteResult = DocumentSessionTable::delete((int)$row['ID']);
					if (!$deleteResult->isSuccess())
					{
						return (new Result())->addErrors($deleteResult->getErrors());
					}
				}

				return new Result();
			},
		);
	}

	/**
	 * Unlike {@see self::finalizeDocument()} it does not skip a session whose pinned key differs from
	 * its hash: nobody is left to close such a document, so the platform rows stay for MaintenanceAgent.
	 */
	public function abandonDocument(string $externalHash): Result
	{
		return $this->finalize(
			static function () use ($externalHash): Result {
				$rows = DocumentSessionTable::query()
					->setSelect(['ID'])
					->where('EXTERNAL_HASH', $externalHash)
					->where('SERVICE', DocumentService::Vibeoffice->value)
					->where('STATUS', DocumentSessionTable::STATUS_ACTIVE)
					->fetchAll()
				;
				$sessionIds = [];
				foreach ($rows as $row)
				{
					$id = (int)$row['ID'];
					$update = DocumentSessionTable::update($id, ['STATUS' => DocumentSessionTable::STATUS_NON_ACTIVE]);
					if (!$update->isSuccess())
					{
						return (new Result())->addErrors($update->getErrors());
					}
					$sessionIds[] = $id;
				}

				return (new Result())->setData(['sessionIds' => $sessionIds]);
			},
		);
	}

	/**
	 * @param callable(): Result $mutation
	 */
	private function finalize(callable $mutation): Result
	{
		$result = new Result();

		$connection = Application::getConnection();
		$transactionStarted = false;

		try
		{
			$connection->startTransaction();
			$transactionStarted = true;

			$mutationResult = $mutation();
			if (!$mutationResult->isSuccess())
			{
				$connection->rollbackTransaction();
				$transactionStarted = false;

				return $mutationResult;
			}

			$connection->commitTransaction();
			$transactionStarted = false;

			return $mutationResult;
		}
		catch (Throwable $exception)
		{
			if ($transactionStarted)
			{
				$connection->rollbackTransaction();
			}

			return $result->addError(new Error(
				$exception->getMessage(),
				'terminal_session_finalize_failed',
			));
		}
	}
}
