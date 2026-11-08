<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Vibeoffice;

use Bitrix\Disk\Document\Models\DocumentService;
use Bitrix\Disk\Document\Models\DocumentSession;
use Bitrix\Disk\Document\Models\DocumentSessionTable;
use Bitrix\Disk\Document\Models\RestrictionLogTable;
use Bitrix\Disk\Document\OnlyOffice\RestrictionManager;
use Bitrix\Main\Application;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Closure;
use Throwable;

final class TerminalSessionFinalizer implements TerminalSessionFinalizerInterface
{
	private const OPERATION_FINALIZE_DOCUMENT = 'vibeoffice_finalize_document';
	private const OPERATION_FINALIZE_DRAIN_SESSION = 'vibeoffice_finalize_drain_session';
	private const OPERATION_FINALIZE_DRAIN_DOCUMENT = 'vibeoffice_finalize_drain_document';
	private const NOTIFICATION_ERROR_CODE = 'restriction_notification_failed';

	private RestrictionManager $restrictionManager;
	private Closure $publicationHandler;
	private Closure $diagnosticHandler;
	private DocumentStateRepository $repository;
	private ?string $lockedHostDocKey = null;
	private bool $restrictionLocked = false;
	private int $deletedRestrictionCount = 0;

	/**
	 * @param callable(int): void|null $publicationHandler Internal after-commit seam for tests.
	 * @param callable(string): void|null $diagnosticHandler Internal diagnostic seam for tests.
	 */
	public function __construct(
		?RestrictionManager $restrictionManager = null,
		?callable $publicationHandler = null,
		?callable $diagnosticHandler = null,
		?DocumentStateRepository $repository = null,
	)
	{
		$this->restrictionManager = $restrictionManager ?? new RestrictionManager();
		$this->repository = $repository ?? new DocumentStateRepository();
		$this->publicationHandler = Closure::fromCallable(
			$publicationHandler
			?? fn(int $availableCount) => $this->restrictionManager->notifyRestrictionLogChanged($availableCount)
		);
		$this->diagnosticHandler = Closure::fromCallable(
			$diagnosticHandler
			?? static fn(string $message) => \AddMessage2Log($message, 'disk')
		);
	}

	public function withDocumentTransaction(string $hostDocKey, callable $mutation): Result
	{
		if ($this->lockedHostDocKey !== null || DocumentStateRepository::isDocumentLocked($hostDocKey))
		{
			return (new Result())->addError(new Error('Finalization is already running.', 'vibeoffice_finalize_nested'));
		}
		$this->deletedRestrictionCount = 0;
		try
		{
			$result = $this->repository->withLockedDocument(
				$hostDocKey,
				function (array $state) use ($hostDocKey, $mutation): Result {
					$this->lockedHostDocKey = $hostDocKey;

					return $mutation($state);
				},
			);
			if ($result->isSuccess() && $this->deletedRestrictionCount > 0)
			{
				try
				{
					$availableCount = $this->restrictionManager->getAvailableDocumentSessionCount();
				}
				catch (Throwable)
				{
					$availableCount = null;
				}
				$this->publishAfterCommit((new Result())->setData([
					'deletedCount' => $this->deletedRestrictionCount,
					'availableCount' => $availableCount,
				]), 'vibeoffice_finalize_sessions', $hostDocKey);
			}

			return $result;
		}
		finally
		{
			$this->lockedHostDocKey = null;
			$this->deletedRestrictionCount = 0;
			if ($this->restrictionLocked)
			{
				$this->restrictionManager->unlock();
				$this->restrictionLocked = false;
			}
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
			return (new Result())->setData(['sessionIds' => [], 'deletedCount' => 0]);
		}
		if (!$this->restrictionLocked)
		{
			if (!$this->restrictionManager->lock())
			{
				return (new Result())->addError(new Error('Could not lock document restrictions.', 'lock_timeout'));
			}
			$this->restrictionLocked = true;
		}
		$registry = new SessionRegistry($this->repository);
		$hashes = [];
		$finalized = [];
		foreach (array_chunk($sessionIds, 300) as $chunk)
		{
			$query = DocumentSessionTable::query()->setSelect(['*'])
				->whereIn('ID', $chunk)->where('SERVICE', DocumentService::Vibeoffice->value);
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
				$hashes[$session->getExternalHash()] = true;
			}
		}

		$deleted = 0;
		foreach (array_keys($hashes) as $hash)
		{
			if ($this->hasActiveSessions($hash) || $registry->hasLiveSessionsForHash($hash))
			{
				continue;
			}
			$rows = RestrictionLogTable::query()->setSelect(['ID'])->where('EXTERNAL_HASH', $hash)->exec();
			while ($row = $rows->fetch())
			{
				$delete = RestrictionLogTable::delete((int)$row['ID']);
				if (!$delete->isSuccess())
				{
					return (new Result())->addErrors($delete->getErrors());
				}
				$deleted++;
			}
		}
		$this->deletedRestrictionCount += $deleted;

		return (new Result())->setData(['sessionIds' => $finalized, 'deletedCount' => $deleted]);
	}

	public function finalizeDocument(string $externalHash): Result
	{
		return $this->finalize(
			$externalHash,
			self::OPERATION_FINALIZE_DOCUMENT,
			function () use ($externalHash): Result {
				$rows = DocumentSessionTable::query()->setSelect(['*'])
					->where('EXTERNAL_HASH', $externalHash)
					->where('SERVICE', DocumentService::Vibeoffice->value)
					->where('STATUS', DocumentSessionTable::STATUS_ACTIVE)
					->exec();
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
				if ($this->hasActiveSessions($externalHash))
				{
					return (new Result())->setData(['deletedCount' => 0]);
				}

				return $this->restrictionManager->deleteByExternalHash($externalHash);
			},
		);
	}

	private function hasActiveSessions(string $externalHash): bool
	{
		return (bool)DocumentSessionTable::query()->setSelect(['ID'])
			->where('EXTERNAL_HASH', $externalHash)->where('STATUS', DocumentSessionTable::STATUS_ACTIVE)
			->setLimit(1)->fetch();
	}

	public function finalizeDrainSession(DocumentSession $session): Result
	{
		$externalHash = $session->getExternalHash();

		return $this->finalize(
			$externalHash,
			self::OPERATION_FINALIZE_DRAIN_SESSION,
			function () use ($session, $externalHash): Result {
				$deleteResult = DocumentSessionTable::delete((int)$session->getId());
				if (!$deleteResult->isSuccess())
				{
					return (new Result())->addErrors($deleteResult->getErrors());
				}

				$activeSession = DocumentSessionTable::getRow([
					'select' => ['ID'],
					'filter' => [
						'=EXTERNAL_HASH' => $externalHash,
						'=STATUS' => DocumentSessionTable::STATUS_ACTIVE,
					],
				]);
				if ($activeSession !== null)
				{
					return (new Result())->setData(['deletedCount' => 0]);
				}

				return $this->restrictionManager->deleteByExternalHash($externalHash);
			},
		);
	}

	public function finalizeDrainDocument(string $externalHash): Result
	{
		return $this->finalize(
			$externalHash,
			self::OPERATION_FINALIZE_DRAIN_DOCUMENT,
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

				return $this->restrictionManager->deleteByExternalHash($externalHash);
			},
		);
	}

	/**
	 * @param callable(): Result $mutation
	 */
	private function finalize(string $externalHash, string $operation, callable $mutation): Result
	{
		$result = new Result();
		if (!$this->restrictionManager->lock())
		{
			return $result->addError(new Error(
				'Could not acquire the document restriction lock.',
				'lock_timeout',
			));
		}

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

			$this->publishAfterCommit($mutationResult, $operation, $externalHash);

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
		finally
		{
			$this->restrictionManager->unlock();
		}
	}

	private function publishAfterCommit(Result $result, string $operation, string $externalHash): void
	{
		$data = $result->getData();
		if ((int)($data['deletedCount'] ?? 0) <= 0)
		{
			return;
		}

		$availableCount = $data['availableCount'] ?? null;
		if ($availableCount === null)
		{
			// The count is unknown (the limit source is temporarily unavailable). Publishing a
			// made-up number would tell the portal the limit is exhausted, so the cleanup stays
			// committed and the next successful publication refreshes the count.
			return;
		}

		try
		{
			($this->publicationHandler)((int)$availableCount);
		}
		catch (Throwable $exception)
		{
			$message = self::NOTIFICATION_ERROR_CODE
				. ": operation={$operation}; EXTERNAL_HASH={$externalHash}; {$exception->getMessage()}"
			;

			try
			{
				($this->diagnosticHandler)($message);
			}
			catch (Throwable)
			{
				// The committed cleanup must stay successful even when diagnostics are unavailable.
			}
		}
	}
}
