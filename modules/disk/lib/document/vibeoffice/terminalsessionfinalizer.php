<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Vibeoffice;

use Bitrix\Disk\Document\Models\DocumentService;
use Bitrix\Disk\Document\Models\DocumentSession;
use Bitrix\Disk\Document\Models\DocumentSessionTable;
use Bitrix\Main\Application;
use Bitrix\Main\Diag\LoggerFactory;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Throwable;

final class TerminalSessionFinalizer implements TerminalSessionFinalizerInterface
{
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
			(new LoggerFactory())
				->createById('disk.vibeoffice.open', isCheckEnabledFromRegistry: false)
				?->error('Could not release refused edit session {sessionId}.', [
					'sessionId' => (int)$session->getId(),
				])
			;
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
