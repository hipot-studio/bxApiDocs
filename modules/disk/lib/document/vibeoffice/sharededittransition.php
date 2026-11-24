<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Vibeoffice;

use Bitrix\Disk\Document\Vibeoffice\Clients\ProtocolClientFactory;
use Bitrix\Disk\File;
use Bitrix\Main\Application;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\Web\Json;

final class SharedEditTransition
{
	public function __construct(private readonly DocumentStateRepository $repository = new DocumentStateRepository())
	{}

	/** Preparation may request saves, but never holds the final file row or a document mutex. */
	public function prepare(int $realObjectId, OpeningBudget $budget): Result
	{
		if (DocumentStateRepository::hasOpenTransaction() || DocumentStateRepository::holdsAnyLock()
			|| $budget->remainingMs() <= 0)
		{
			return $this->busy();
		}
		$pool = Application::getInstance()->getConnectionPool();
		$pool->useMasterOnly(true);
		try
		{
			$file = File::loadById($realObjectId);
			if (!$file || $file->isDeleted())
			{
				return $this->busy();
			}
			$pending = [];
			$census = $this->repository->getOldEditCensus($realObjectId, $budget);
			foreach ($census['states'] as $key => $state)
			{
				if ($budget->remainingMs() <= 0)
				{
					return $this->busy();
				}
				if (!$state || !in_array((int)($state['EDIT_SCOPE_VERSION'] ?? 0), [0, 1], true))
				{
					return $this->busy();
				}
				if ((int)($state['EDIT_SCOPE_VERSION'] ?? 0) === 0
					&& !$this->isSettled($state, $census['localDebt'][$key] ?? false, $budget))
				{
					$pending[] = $key;
				}
			}
			if ($pending === [])
			{
				return $budget->remainingMs() > 0 ? new Result() : $this->busy();
			}
			$client = ProtocolClientFactory::create();
			foreach ($pending as $key)
			{
				if ($budget->remainingMs() <= 0)
				{
					return $this->busy();
				}
				$requested = $client->forcesaveWithinDeadline($key, null, $budget->deadlineForOpen());
				if (!$requested->isSuccess())
				{
					return $this->busy();
				}
			}
			$seconds = $budget->remainingMs() / 1000;
			if ($seconds > 0)
			{
				(new SavedContentSynchronizer($client, queuedBudgetSec: $seconds, recentClosedBudgetSec: $seconds))
					->synchronize($file, $budget)
				;
			}

			// A force-save response alone cannot establish a terminal content outcome.
			return $this->verifyStates($realObjectId, $budget);
		}
		catch (\Throwable)
		{
			return $this->busy();
		}
		finally
		{
			$pool->useMasterOnly(false);
		}
	}

	/** The caller owns the final real file row. This method neither commits nor takes a named lock. */
	public function verifyLocked(File $file, ?OpeningBudget $budget = null): Result
	{
		if (!DocumentStateRepository::hasOpenTransaction() || $file->isDeleted()
			|| !$this->hasRemainingBudget($budget))
		{
			return $this->busy();
		}
		$pool = Application::getInstance()->getConnectionPool();
		$pool->useMasterOnly(true);
		try
		{
			$objectId = (int)$file->getRealObjectId();
			if (!DocumentStateRepository::fetchForUpdate(
				\Bitrix\Disk\Internals\ObjectTable::query()->setSelect(['ID'])->where('ID', $objectId),
			))
			{
				return $this->busy();
			}

			return $this->verifyStates($objectId, $budget);
		}
		catch (\Throwable)
		{
			return $this->busy();
		}
		finally
		{
			$pool->useMasterOnly(false);
		}
	}

	private function verifyStates(int $realObjectId, ?OpeningBudget $budget = null): Result
	{
		$census = $this->repository->getOldEditCensus($realObjectId, $budget);
		foreach ($census['states'] as $key => $state)
		{
			if ($budget !== null && $budget->remainingMs() <= 0)
			{
				return $this->busy();
			}
			if (!$state || !in_array((int)($state['EDIT_SCOPE_VERSION'] ?? 0), [0, 1], true)
				|| ((int)($state['EDIT_SCOPE_VERSION'] ?? 0) === 0
					&& !$this->isSettled($state, $census['localDebt'][$key] ?? false, $budget)))
			{
				return $this->busy();
			}
		}

		return $this->hasRemainingBudget($budget) ? new Result() : $this->busy();
	}

	private function isSettled(array $state, bool $localDebt, ?OpeningBudget $budget = null): bool
	{
		if (!$this->hasRemainingBudget($budget))
		{
			return false;
		}
		try
		{
			$proof = Json::decode($state['TERMINAL_PROOF'] ?? '');
		}
		catch (\Throwable)
		{
			return false;
		}
		if (!is_array($proof) || !TerminalProof::matchesState($proof, $state)
			|| !$this->hasRemainingBudget($budget)
			|| !$this->repository->matchesTerminalPlacement($state, $proof)
			|| !$this->hasRemainingBudget($budget)
			|| $this->repository->hasTerminalDebt($state, localDebt: $localDebt, budget: $budget)
			|| !$this->hasRemainingBudget($budget))
		{
			return false;
		}
		return TerminalProof::matchesReceipt($proof, $state, $this->repository)
			&& $this->hasRemainingBudget($budget);
	}

	private function hasRemainingBudget(?OpeningBudget $budget): bool
	{
		return $budget === null || $budget->remainingMs() > 0;
	}

	private function busy(): Result
	{
		return (new Result())->addError(new Error(
			'Old editing sessions are not settled.', 'vibeoffice_transition_busy',
		));
	}
}
