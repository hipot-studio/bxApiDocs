<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Entity\Currency\BatchDeletion;

final class BatchDeletionContext
{
	public const MODE_SELECTED = 'selected';
	public const MODE_ALL = 'all';

	public const STATE_PREPARED = 'prepared';
	public const STATE_PROCESSING = 'processing';
	public const STATE_COMPLETED = 'completed';
	public const STATE_CANCELLED = 'cancelled';

	/** @var list<string> */
	private array $snapshotCodes;
	private string $state = self::STATE_PREPARED;
	private int $nextIndex = 0;
	private int $successCount = 0;
	private int $failedCount = 0;
	private ?BatchDeletionJournal $activeJournal = null;
	private int $createdAt;
	private int $updatedAt;

	/** @var array<string|int, mixed>|null */
	private ?array $terminalResult = null;

	/** @param list<string> $snapshotCodes */
	public function __construct(
		private readonly int $userId,
		private readonly string $gridId,
		private readonly string $processToken,
		private readonly string $mode,
		array $snapshotCodes,
	)
	{
		if ($userId <= 0)
		{
			throw new \InvalidArgumentException('Batch deletion owner must be a positive user id');
		}

		if (
			trim($gridId) === ''
			|| trim($processToken) === ''
			|| preg_match('//u', $gridId) !== 1
			|| preg_match('//u', $processToken) !== 1
		)
		{
			throw new \InvalidArgumentException('Batch deletion identity cannot be empty');
		}

		if (!in_array($mode, [self::MODE_SELECTED, self::MODE_ALL], true))
		{
			throw new \InvalidArgumentException('Unknown batch deletion mode');
		}

		$this->snapshotCodes = self::normalizeSnapshotCodes($snapshotCodes);
		$this->createdAt = time();
		$this->updatedAt = $this->createdAt;
	}

	/**
	 * @param list<mixed> $selectedCodes
	 * @param list<mixed> $existingCodes
	 */
	public static function createSelected(
		int $userId,
		string $gridId,
		string $processToken,
		array $selectedCodes,
		array $existingCodes,
	): self
	{
		$existingMap = [];
		foreach (self::normalizeRequestedCodes($existingCodes) as $code)
		{
			$existingMap[$code] = true;
		}

		$snapshotCodes = [];
		foreach (self::normalizeRequestedCodes($selectedCodes) as $code)
		{
			if (isset($existingMap[$code]))
			{
				$snapshotCodes[] = $code;
			}
		}

		return new self($userId, $gridId, $processToken, self::MODE_SELECTED, $snapshotCodes);
	}

	/** @param list<string> $existingCodes */
	public static function createAll(
		int $userId,
		string $gridId,
		string $processToken,
		array $existingCodes,
	): self
	{
		return new self($userId, $gridId, $processToken, self::MODE_ALL, $existingCodes);
	}

	public function getUserId(): int
	{
		return $this->userId;
	}

	public function getGridId(): string
	{
		return $this->gridId;
	}

	public function getProcessToken(): string
	{
		return $this->processToken;
	}

	public function getMode(): string
	{
		return $this->mode;
	}

	public function getState(): string
	{
		return $this->state;
	}

	/** @return list<string> */
	public function getSnapshotCodes(): array
	{
		return $this->snapshotCodes;
	}

	public function getNextIndex(): int
	{
		return $this->nextIndex;
	}

	public function getTotalCount(): int
	{
		return count($this->snapshotCodes);
	}

	/** @return array{processed: int, success: int, failed: int, total: int} */
	public function getCounters(): array
	{
		return [
			'processed' => $this->successCount + $this->failedCount,
			'success' => $this->successCount,
			'failed' => $this->failedCount,
			'total' => $this->getTotalCount(),
		];
	}

	public function getActiveJournal(): ?BatchDeletionJournal
	{
		return $this->activeJournal === null ? null : clone $this->activeJournal;
	}

	public function activateNextChunk(int $chunkSize): ?BatchDeletionJournal
	{
		if ($chunkSize <= 0)
		{
			throw new \InvalidArgumentException('Batch deletion chunk size must be positive');
		}

		if ($this->isTerminal())
		{
			return null;
		}

		if ($this->activeJournal !== null && !$this->activeJournal->isComplete())
		{
			return clone $this->activeJournal;
		}

		$this->activeJournal = null;
		$codes = array_slice($this->snapshotCodes, $this->nextIndex, $chunkSize);
		if ($codes === [])
		{
			return null;
		}

		$this->state = self::STATE_PROCESSING;
		$this->activeJournal = new BatchDeletionJournal($codes);
		$this->touch();

		return clone $this->activeJournal;
	}

	public function recordSuccess(string $code): void
	{
		$this->recordOutcome($code, true, '');
	}

	public function recordFailure(string $code, string $message): void
	{
		$this->recordOutcome($code, false, $message);
	}

	/** @param array<string|int, mixed> $result */
	public function complete(array $result): void
	{
		if ($this->nextIndex !== $this->getTotalCount())
		{
			throw new \LogicException('Batch deletion cannot complete before all snapshot items are resolved');
		}

		$this->setTerminalState(self::STATE_COMPLETED, $result);
	}

	/** @param array<string|int, mixed> $result */
	public function cancel(array $result): void
	{
		$this->setTerminalState(self::STATE_CANCELLED, $result);
	}

	public function isTerminal(): bool
	{
		return in_array($this->state, [self::STATE_COMPLETED, self::STATE_CANCELLED], true);
	}

	/** @return array<string|int, mixed>|null */
	public function getTerminalResult(): ?array
	{
		return $this->terminalResult;
	}

	/**
	 * @return array{
	 *     userId: int,
	 *     gridId: string,
	 *     processToken: string,
	 *     mode: string,
	 *     state: string,
	 *     snapshotCodes: list<string>,
	 *     nextIndex: int,
	 *     successCount: int,
	 *     failedCount: int,
	 *     activeJournal: array|null,
	 *     terminalResult: array|null,
	 *     createdAt: int,
	 *     updatedAt: int
	 * }
	 */
	public function toArray(): array
	{
		return [
			'userId' => $this->userId,
			'gridId' => $this->gridId,
			'processToken' => $this->processToken,
			'mode' => $this->mode,
			'state' => $this->state,
			'snapshotCodes' => $this->snapshotCodes,
			'nextIndex' => $this->nextIndex,
			'successCount' => $this->successCount,
			'failedCount' => $this->failedCount,
			'activeJournal' => $this->activeJournal?->toArray(),
			'terminalResult' => $this->terminalResult,
			'createdAt' => $this->createdAt,
			'updatedAt' => $this->updatedAt,
		];
	}

	/** @param array<string, mixed> $data */
	public static function fromArray(array $data): self
	{
		if (
			!is_int($data['userId'] ?? null)
			|| !is_string($data['gridId'] ?? null)
			|| !is_string($data['processToken'] ?? null)
			|| !is_string($data['mode'] ?? null)
			|| !is_string($data['state'] ?? null)
			|| !is_array($data['snapshotCodes'] ?? null)
			|| !is_int($data['nextIndex'] ?? null)
			|| !is_int($data['successCount'] ?? null)
			|| !is_int($data['failedCount'] ?? null)
		)
		{
			throw new \InvalidArgumentException('Invalid batch deletion context data');
		}

		$context = new self(
			$data['userId'],
			$data['gridId'],
			$data['processToken'],
			$data['mode'],
			$data['snapshotCodes'],
		);
		$context->restoreState($data);

		return $context;
	}

	private function recordOutcome(string $code, bool $success, string $message): void
	{
		if ($this->activeJournal === null)
		{
			throw new \LogicException('Batch deletion has no active journal');
		}

		if ($this->activeJournal->hasOutcome($code))
		{
			return;
		}

		if (($this->snapshotCodes[$this->nextIndex] ?? null) !== $code)
		{
			throw new \LogicException('Batch deletion outcomes must follow snapshot order');
		}

		if ($success)
		{
			$this->activeJournal->recordSuccess($code);
			$this->successCount++;
		}
		else
		{
			$this->activeJournal->recordFailure($code, $message);
			$this->failedCount++;
		}

		$this->nextIndex++;
		$this->touch();
	}

	/** @param array<string|int, mixed> $result */
	private function setTerminalState(string $state, array $result): void
	{
		if (!self::isSafeData($result))
		{
			throw new \InvalidArgumentException('Terminal batch deletion result must contain only safe data');
		}

		$this->state = $state;
		$this->terminalResult = $result;
		$this->touch();
	}

	/** @param array<string, mixed> $data */
	private function restoreState(array $data): void
	{
		$allowedStates = [
			self::STATE_PREPARED,
			self::STATE_PROCESSING,
			self::STATE_COMPLETED,
			self::STATE_CANCELLED,
		];
		if (!in_array($data['state'], $allowedStates, true))
		{
			throw new \InvalidArgumentException('Unknown batch deletion state');
		}

		$nextIndex = $data['nextIndex'];
		$successCount = $data['successCount'];
		$failedCount = $data['failedCount'];
		if (
			$nextIndex < 0
			|| $nextIndex > $this->getTotalCount()
			|| $successCount < 0
			|| $failedCount < 0
			|| $successCount + $failedCount !== $nextIndex
		)
		{
			throw new \InvalidArgumentException('Invalid batch deletion counters');
		}

		$activeJournalData = $data['activeJournal'] ?? null;
		if ($activeJournalData !== null && !is_array($activeJournalData))
		{
			throw new \InvalidArgumentException('Invalid active batch deletion journal');
		}

		$terminalResult = $data['terminalResult'] ?? null;
		if ($terminalResult !== null && (!is_array($terminalResult) || !self::isSafeData($terminalResult)))
		{
			throw new \InvalidArgumentException('Invalid terminal batch deletion result');
		}

		$isTerminal = in_array($data['state'], [self::STATE_COMPLETED, self::STATE_CANCELLED], true);
		if ($isTerminal !== ($terminalResult !== null))
		{
			throw new \InvalidArgumentException('Batch deletion terminal state and result do not match');
		}

		$hasCreatedAt = array_key_exists('createdAt', $data);
		$hasUpdatedAt = array_key_exists('updatedAt', $data);
		if ($hasCreatedAt !== $hasUpdatedAt)
		{
			throw new \InvalidArgumentException('Incomplete batch deletion timestamps');
		}
		if (
			$hasCreatedAt
			&& (
				!is_int($data['createdAt'])
				|| !is_int($data['updatedAt'])
				|| $data['createdAt'] <= 0
				|| $data['updatedAt'] < $data['createdAt']
			)
		)
		{
			throw new \InvalidArgumentException('Invalid batch deletion timestamps');
		}

		$activeJournal = $activeJournalData === null
			? null
			: BatchDeletionJournal::fromArray($activeJournalData);
		if ($activeJournal !== null)
		{
			$this->validateRestoredJournal($activeJournal, $nextIndex);
		}

		if ($data['state'] === self::STATE_PREPARED && ($nextIndex !== 0 || $activeJournal !== null))
		{
			throw new \InvalidArgumentException('Prepared batch deletion context contains progress');
		}

		if ($data['state'] === self::STATE_COMPLETED && $nextIndex !== $this->getTotalCount())
		{
			throw new \InvalidArgumentException('Completed batch deletion context contains unresolved items');
		}

		$this->state = $data['state'];
		$this->nextIndex = $nextIndex;
		$this->successCount = $successCount;
		$this->failedCount = $failedCount;
		$this->activeJournal = $activeJournal;
		$this->terminalResult = $terminalResult;
		if ($hasCreatedAt)
		{
			$this->createdAt = $data['createdAt'];
			$this->updatedAt = $data['updatedAt'];
		}
	}

	private function touch(): void
	{
		$this->updatedAt = time();
	}

	private function validateRestoredJournal(BatchDeletionJournal $journal, int $nextIndex): void
	{
		$outcomeCodes = array_keys($journal->getOutcomes());
		$expectedOutcomeCodes = array_slice($journal->getCodes(), 0, count($outcomeCodes));
		if ($outcomeCodes !== $expectedOutcomeCodes)
		{
			throw new \InvalidArgumentException('Batch deletion journal outcomes do not form a resolved prefix');
		}

		$chunkStart = $nextIndex - count($outcomeCodes);
		$expectedChunk = array_slice($this->snapshotCodes, $chunkStart, count($journal->getCodes()));
		if ($chunkStart < 0 || $journal->getCodes() !== $expectedChunk)
		{
			throw new \InvalidArgumentException('Batch deletion journal does not match its snapshot');
		}
	}

	/** @param list<mixed> $codes */
	private static function normalizeRequestedCodes(array $codes): array
	{
		$normalized = [];
		$seen = [];
		foreach ($codes as $code)
		{
			if (!is_string($code))
			{
				continue;
			}

			$code = strtoupper(trim($code));
			if (preg_match('/^[A-Z]{3}$/D', $code) !== 1 || isset($seen[$code]))
			{
				continue;
			}

			$normalized[] = $code;
			$seen[$code] = true;
		}

		return $normalized;
	}

	/** @param list<string> $codes */
	private static function normalizeSnapshotCodes(array $codes): array
	{
		$normalized = self::normalizeRequestedCodes($codes);
		if (count($normalized) !== count($codes))
		{
			throw new \InvalidArgumentException('Batch deletion snapshot contains invalid currency codes');
		}

		return $normalized;
	}

	private static function isSafeData(mixed $value): bool
	{
		if ($value === null || is_int($value) || is_bool($value))
		{
			return true;
		}

		if (is_float($value))
		{
			return is_finite($value);
		}

		if (is_string($value))
		{
			return preg_match('//u', $value) === 1;
		}

		if (!is_array($value))
		{
			return false;
		}

		foreach ($value as $key => $item)
		{
			if ((!is_int($key) && !is_string($key)) || !self::isSafeData($item))
			{
				return false;
			}
		}

		return true;
	}
}
