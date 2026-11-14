<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\Audit;

use Throwable;

final class AuditTransactionResult
{
	public const STATUS_COMMITTED = 'committed';
	public const STATUS_ROLLED_BACK = 'rolled_back';
	public const STATUS_START_FAILED = 'start_failed';
	public const STATUS_COMMIT_UNKNOWN = 'commit_unknown';
	public const STATUS_ROLLBACK_FAILED = 'rollback_failed';

	private function __construct(
		private readonly string $status,
		private readonly AuditTransactionOutcome $outcome,
		private readonly array $callbackExceptions = [],
		private readonly ?Throwable $transactionException = null,
		private readonly array $compensationExceptions = [],
		private readonly array $afterCommitExceptions = [],
	)
	{
	}

	public static function committed(AuditTransactionOutcome $outcome, array $afterCommitExceptions = []): self
	{
		return new self(self::STATUS_COMMITTED, $outcome, afterCommitExceptions: $afterCommitExceptions);
	}

	public static function rolledBack(
		AuditTransactionOutcome $outcome,
		array $callbackExceptions = [],
		array $compensationExceptions = [],
	): self
	{
		return new self(self::STATUS_ROLLED_BACK, $outcome, $callbackExceptions, null, $compensationExceptions);
	}

	public static function startFailed(Throwable $exception): self
	{
		return new self(self::STATUS_START_FAILED, AuditTransactionOutcome::failure(), transactionException: $exception);
	}

	public static function commitUnknown(
		AuditTransactionOutcome $outcome,
		Throwable $exception,
		array $callbackExceptions = [],
	): self
	{
		return new self(self::STATUS_COMMIT_UNKNOWN, $outcome, $callbackExceptions, $exception);
	}

	public static function rollbackFailed(
		AuditTransactionOutcome $outcome,
		Throwable $exception,
		array $callbackExceptions = [],
	): self
	{
		return new self(self::STATUS_ROLLBACK_FAILED, $outcome, $callbackExceptions, $exception);
	}

	public function getStatus(): string
	{
		return $this->status;
	}

	public function getOutcome(): AuditTransactionOutcome
	{
		return $this->outcome;
	}

	public function isSuccess(): bool
	{
		return $this->status === self::STATUS_COMMITTED && $this->outcome->isSuccess();
	}

	public function isRollbackConfirmed(): bool
	{
		return $this->status === self::STATUS_ROLLED_BACK;
	}

	/** @return Throwable[] */
	public function getCallbackExceptions(): array
	{
		return $this->callbackExceptions;
	}

	public function getCallbackException(): ?Throwable
	{
		return $this->callbackExceptions[0] ?? null;
	}

	public function getTransactionException(): ?Throwable
	{
		return $this->transactionException;
	}

	/** @return Throwable[] */
	public function getCompensationExceptions(): array
	{
		return $this->compensationExceptions;
	}

	/** @return Throwable[] */
	public function getAfterCommitExceptions(): array
	{
		return $this->afterCommitExceptions;
	}

	/** @return Throwable[] */
	public function getExceptions(): array
	{
		return [
			...$this->callbackExceptions,
			...($this->transactionException === null ? [] : [$this->transactionException]),
			...$this->compensationExceptions,
			...$this->afterCommitExceptions,
		];
	}
}
