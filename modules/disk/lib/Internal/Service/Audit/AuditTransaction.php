<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\Audit;

use Bitrix\Main\Application;
use Bitrix\Main\DB\Connection;
use Bitrix\Main\DB\TransactionException;
use LogicException;
use ReflectionObject;
use Throwable;
use UnexpectedValueException;

final class AuditTransaction
{
	private static ?self $activeOwner = null;
	private static ?AuditCommitBoundary $externalCommitOwner = null;

	private bool $acceptingCallbacks = false;
	private ?AuditTransactionOutcome $rollbackOutcome = null;

	private array $callbackExceptions = [];

	private array $compensations = [];

	private array $afterCommitCallbacks = [];
	private array $afterConfirmedCommitCallbacks = [];

	public function __construct(private readonly Connection $connection)
	{
	}

	public function execute(callable $callback): AuditTransactionResult
	{
		$this->assertCanStart();
		$this->reset();
		if ($this->hasOpenDatabaseTransaction())
		{
			return $this->executeInsideExternalTransaction($callback);
		}

		return $this->executeRootTransaction($callback);
	}

	public function canStartRootTransaction(): bool
	{
		return self::$activeOwner === null && !$this->hasOpenDatabaseTransaction();
	}

	/** @internal Commands with non-transactional side effects must own the real database transaction. */
	public function executeRoot(callable $callback): AuditTransactionResult
	{
		$this->assertCanStart();
		$this->reset();
		if ($this->hasOpenDatabaseTransaction())
		{
			return AuditTransactionResult::startFailed(
				new LogicException('A root audit transaction cannot start inside an external transaction.'),
			);
		}

		return $this->executeRootTransaction($callback);
	}

	private function executeRootTransaction(callable $callback): AuditTransactionResult
	{
		try
		{
			$this->connection->startTransaction();
		}
		catch (Throwable $exception)
		{
			return AuditTransactionResult::startFailed($exception);
		}

		$this->acceptingCallbacks = true;
		self::$activeOwner = $this;

		try
		{
			try
			{
				$outcome = $this->invoke($callback);
				if (!$outcome->isSuccess())
				{
					$this->rollbackOutcome ??= $outcome;
				}
			}
			catch (Throwable $exception)
			{
				$this->fail($exception);
				$outcome = AuditTransactionOutcome::failure();
			}

			$this->acceptingCallbacks = false;

			return $this->rollbackOutcome !== null
				? $this->rollback($this->rollbackOutcome)
				: $this->commit($outcome);
		}
		finally
		{
			$this->deactivate();
			$this->reset();
		}
	}

	private function executeInsideExternalTransaction(callable $callback): AuditTransactionResult
	{
		$this->acceptingCallbacks = true;
		self::$activeOwner = $this;

		try
		{
			try
			{
				$outcome = $this->invoke($callback);
				if (!$outcome->isSuccess())
				{
					$this->rollbackOutcome ??= $outcome;
				}
			}
			catch (Throwable $exception)
			{
				$this->fail($exception);
				$outcome = AuditTransactionOutcome::failure();
			}

			$this->acceptingCallbacks = false;
			$this->deactivate();

			if ($this->rollbackOutcome !== null)
			{
				$this->getExternalCommitBoundary()?->discard();
				return AuditTransactionResult::rolledBack(
					$this->rollbackOutcome,
					$this->callbackExceptions,
					$this->runCallbacks(array_reverse($this->compensations)),
				);
			}
			$boundary = $this->getExternalCommitBoundary();
			foreach ($this->afterConfirmedCommitCallbacks as $confirmedCallback)
			{
				$boundary?->addAfterConfirmedCommit($confirmedCallback);
			}

			return AuditTransactionResult::committed(
				$outcome,
				$this->runCallbacks($this->afterCommitCallbacks),
			);
		}
		finally
		{
			$this->deactivate();
			$this->reset();
		}
	}

	/**
	 * Runs the command under a savepoint of its own inside the caller's open database transaction:
	 * success releases the savepoint, failure rolls back to it. The main transaction counter is never touched.
	 */
	public function executeInsideSavepoint(callable $callback): AuditTransactionResult
	{
		$this->assertCanStart();
		$this->reset();
		$savepoint = 'disk_sp_' . bin2hex(random_bytes(8));

		try
		{
			$this->connection->query('SAVEPOINT ' . $savepoint);
			// Outside a transaction mysql accepts and immediately discards the savepoint, so the probe fails.
			$this->connection->query('ROLLBACK TO SAVEPOINT ' . $savepoint);
		}
		catch (Throwable $exception)
		{
			return AuditTransactionResult::startFailed($exception);
		}

		$levelBefore = $this->readTransactionLevel();
		$this->acceptingCallbacks = true;
		self::$activeOwner = $this;

		try
		{
			try
			{
				$outcome = $this->invoke($callback);
				if (!$outcome->isSuccess())
				{
					$this->rollbackOutcome ??= $outcome;
				}
			}
			catch (Throwable $exception)
			{
				$this->fail($exception);
				$outcome = AuditTransactionOutcome::failure();
			}

			$this->acceptingCallbacks = false;
			$this->deactivate();

			$levelAfter = $this->readTransactionLevel();
			if ($levelBefore !== null && $levelAfter !== null && $levelBefore !== $levelAfter)
			{
				$this->fail(new LogicException(
					"The audit transaction command left the database transaction level at {$levelAfter}"
					. " instead of {$levelBefore}.",
				));
			}

			if ($this->rollbackOutcome === null)
			{
				try
				{
					$this->connection->query('RELEASE SAVEPOINT ' . $savepoint);

					return AuditTransactionResult::committed(
						$outcome,
						$this->runCallbacks($this->afterCommitCallbacks),
					);
				}
				catch (Throwable $exception)
				{
					$this->fail($exception);
				}
			}

			return $this->rollbackToSavepoint($savepoint, $this->rollbackOutcome);
		}
		finally
		{
			$this->deactivate();
			$this->reset();
		}
	}

	private function rollbackToSavepoint(string $savepoint, AuditTransactionOutcome $outcome): AuditTransactionResult
	{
		try
		{
			$this->connection->query('ROLLBACK TO SAVEPOINT ' . $savepoint);
		}
		catch (Throwable $exception)
		{
			return AuditTransactionResult::rollbackFailed($outcome, $exception, $this->callbackExceptions);
		}

		try
		{
			$this->connection->query('RELEASE SAVEPOINT ' . $savepoint);
		}
		catch (Throwable)
		{
			// The rollback is already confirmed; a dangling savepoint ends with the caller's transaction.
		}

		return AuditTransactionResult::rolledBack(
			$outcome,
			$this->callbackExceptions,
			$this->runCallbacks(array_reverse($this->compensations)),
		);
	}

	public function join(callable $callback, ?Connection $connection = null): AuditTransactionOutcome
	{
		$this->assertCanJoin($connection);

		try
		{
			$outcome = $this->invoke($callback);
		}
		catch (Throwable $exception)
		{
			$this->fail($exception);

			throw $exception;
		}

		if (!$outcome->isSuccess())
		{
			$this->rollbackOutcome ??= $outcome;
		}

		return $outcome;
	}

	public function addCompensation(callable $compensation): void
	{
		$this->assertCallbackOpen();
		$this->compensations[] = $compensation;
	}

	public function addAfterCommit(callable $callback): void
	{
		$this->assertCallbackOpen();
		$this->afterCommitCallbacks[] = $callback;
	}

	public function addAfterConfirmedCommit(callable $callback): void
	{
		$this->assertCallbackOpen();
		$this->afterConfirmedCommitCallbacks[] = $callback;
	}

	public function tryAddAfterConfirmedCommit(callable $callback): bool
	{
		if (!$this->acceptingCallbacks || self::$activeOwner !== $this)
		{
			return false;
		}
		$this->afterConfirmedCommitCallbacks[] = $callback;

		return true;
	}

	public static function runWithExternalCommitOwner(AuditCommitBoundary $boundary, callable $callback): mixed
	{
		$previous = self::$externalCommitOwner;
		self::$externalCommitOwner = $boundary;
		try
		{
			return $callback();
		}
		catch (Throwable $exception)
		{
			$boundary->discard();

			throw $exception;
		}
		finally
		{
			self::$externalCommitOwner = $previous;
		}
	}

	private function getExternalCommitBoundary(): ?AuditCommitBoundary
	{
		return self::$externalCommitOwner?->getConnection() === $this->connection
			? self::$externalCommitOwner
			: null;
	}

	public function getConnection(): Connection
	{
		return $this->connection;
	}

	public static function run(callable $callback, ?Connection $connection = null): mixed
	{
		$connection ??= Application::getConnection();
		if (self::$activeOwner !== null)
		{
			$outcome = self::$activeOwner->join(
				static fn(self $owner): AuditTransactionOutcome => self::createLegacyOutcome($callback($owner)),
				$connection,
			);

			return $outcome->getValue();
		}

		return self::unwrapLegacyResult(
			(new self($connection))->execute(
				static fn(self $owner): AuditTransactionOutcome => self::createLegacyOutcome($callback($owner)),
			),
		);
	}

	public static function isActive(?Connection $connection = null): bool
	{
		$connection ??= Application::getConnection();

		return self::$activeOwner?->connection === $connection;
	}

	private function invoke(callable $callback): AuditTransactionOutcome
	{
		$outcome = $callback($this);
		if (!$outcome instanceof AuditTransactionOutcome)
		{
			throw new UnexpectedValueException(
				'Audit transaction callbacks must return an AuditTransactionOutcome.',
			);
		}

		return $outcome;
	}

	private function commit(AuditTransactionOutcome $outcome): AuditTransactionResult
	{
		try
		{
			$this->connection->commitTransaction();
		}
		catch (Throwable $exception)
		{
			return AuditTransactionResult::commitUnknown($outcome, $exception, $this->callbackExceptions);
		}

		$this->deactivate();

		return AuditTransactionResult::committed(
			$outcome,
			$this->runCallbacks(array_merge($this->afterCommitCallbacks, $this->afterConfirmedCommitCallbacks)),
		);
	}

	private function rollback(AuditTransactionOutcome $outcome): AuditTransactionResult
	{
		try
		{
			$this->connection->rollbackTransaction();
		}
		catch (Throwable $exception)
		{
			if (!$this->isConfirmedNestedRollback($exception))
			{
				return AuditTransactionResult::rollbackFailed($outcome, $exception, $this->callbackExceptions);
			}
		}

		$this->deactivate();

		return AuditTransactionResult::rolledBack(
			$outcome,
			$this->callbackExceptions,
			$this->runCallbacks(array_reverse($this->compensations)),
		);
	}

	private function isConfirmedNestedRollback(Throwable $exception): bool
	{
		return $exception instanceof TransactionException
			&& $exception->getMessage() === 'Nested rollbacks are unsupported.';
	}

	/**
	 * True when the instance connection is already inside a database transaction
	 * (mssql: @@TRANCOUNT, oracle: OCI_DEFAULT commit mode, mysql/pgsql: transactionLevel).
	 */
	public function hasOpenDatabaseTransaction(): bool
	{
		if ($this->connection->getType() === 'mssql')
		{
			return (int)$this->connection->queryScalar('SELECT @@TRANCOUNT') > 0;
		}

		if ($this->connection->getType() === 'oracle')
		{
			$transaction = $this->readConnectionProperty('transaction');

			return defined('OCI_DEFAULT') && $transaction === constant('OCI_DEFAULT');
		}

		$transactionLevel = $this->readConnectionProperty('transactionLevel');

		return $transactionLevel !== null && (int)$transactionLevel > 0;
	}

	/**
	 * True on mysql/pgsql inside an open database transaction. A missing transactionLevel property
	 * is treated as an open transaction so that the safe savepoint path is chosen.
	 */
	public function canExecuteInsideSavepoint(): bool
	{
		if (!in_array($this->connection->getType(), ['mysql', 'pgsql'], true))
		{
			return false;
		}

		$transactionLevel = $this->readTransactionLevel();

		return $transactionLevel === null || $transactionLevel > 0;
	}

	private function readTransactionLevel(): ?int
	{
		$transactionLevel = $this->readConnectionProperty('transactionLevel');

		return $transactionLevel === null ? null : (int)$transactionLevel;
	}

	private function readConnectionProperty(string $name): mixed
	{
		$reflection = new ReflectionObject($this->connection);
		do
		{
			if ($reflection->hasProperty($name))
			{
				return $reflection->getProperty($name)->getValue($this->connection);
			}
			$reflection = $reflection->getParentClass();
		}
		while ($reflection !== false);

		return null;
	}

	/** @param callable[] $callbacks @return Throwable[] */
	private function runCallbacks(array $callbacks): array
	{
		$exceptions = [];
		foreach ($callbacks as $callback)
		{
			try
			{
				$callback();
			}
			catch (Throwable $exception)
			{
				$exceptions[] = $exception;
			}
		}

		return $exceptions;
	}

	private function assertCanStart(): void
	{
		if (self::$activeOwner === null)
		{
			return;
		}

		self::reject('An audit transaction command is already owned by another scope.', self::$activeOwner);
	}

	private function assertCanJoin(?Connection $connection): void
	{
		$this->assertCallbackOpen();
		if ($connection === null || $connection === $this->connection)
		{
			return;
		}

		self::reject(
			'A nested audit transaction cannot join a scope owned by another connection.',
			$this,
		);
	}

	private function assertCallbackOpen(): void
	{
		if ($this->acceptingCallbacks && self::$activeOwner === $this)
		{
			return;
		}

		self::reject(
			'Audit transaction callbacks can only be registered during the callback.',
			self::$activeOwner,
		);
	}

	private static function reject(string $message, ?self $owner): never
	{
		$exception = new LogicException($message);
		if ($owner?->acceptingCallbacks)
		{
			$owner->fail($exception);
		}

		throw $exception;
	}

	private function fail(Throwable $exception): void
	{
		if (!in_array($exception, $this->callbackExceptions, true))
		{
			$this->callbackExceptions[] = $exception;
		}

		$this->rollbackOutcome ??= AuditTransactionOutcome::failure();
	}

	private function deactivate(): void
	{
		$this->acceptingCallbacks = false;
		if (self::$activeOwner === $this)
		{
			self::$activeOwner = null;
		}
	}

	private function reset(): void
	{
		$this->rollbackOutcome = null;
		$this->callbackExceptions = [];
		$this->compensations = [];
		$this->afterCommitCallbacks = [];
		$this->afterConfirmedCommitCallbacks = [];
	}

	private static function createLegacyOutcome(mixed $value): AuditTransactionOutcome
	{
		return $value === null || $value === false
			? AuditTransactionOutcome::failure($value)
			: AuditTransactionOutcome::success($value);
	}

	private static function unwrapLegacyResult(AuditTransactionResult $result): mixed
	{
		if ($result->isSuccess())
		{
			return $result->getOutcome()->getValue();
		}

		if (!$result->isRollbackConfirmed() || $result->getCompensationExceptions() !== [])
		{
			throw new AuditTransactionException($result);
		}

		$callbackExceptions = $result->getCallbackExceptions();
		if (count($callbackExceptions) === 1)
		{
			throw $callbackExceptions[0];
		}
		if ($callbackExceptions !== [])
		{
			throw new AuditTransactionException($result);
		}

		return $result->getOutcome()->getValue();
	}
}
