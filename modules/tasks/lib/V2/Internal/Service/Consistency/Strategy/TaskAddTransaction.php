<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Consistency\Strategy;

use Bitrix\Main\Application;
use Bitrix\Main\DB\TransactionException;
use Throwable;

class TaskAddTransaction implements ConsistencyStrategyInterface
{
	private const NESTED_ROLLBACK_COMPLETED = 'Nested rollbacks are unsupported.';

	public function execute(callable $callable, array $parameters = []): mixed
	{
		$connection = Application::getConnection();

		$connection->startTransaction();

		try
		{
			$result = $callable(...$parameters);

			$connection->commitTransaction();

			return $result;
		}
		catch (Throwable $exception)
		{
			$cleanupException = null;

			try
			{
				$connection->rollbackTransaction();
			}
			catch (Throwable $rollbackException)
			{
				if (!$this->isCompletedNestedRollback($rollbackException))
				{
					$cleanupException = $rollbackException;
				}
			}

			if ($cleanupException !== null)
			{
				$this->writeDiagnostic(
					$exception,
					$cleanupException,
				);
			}

			throw $exception;
		}
	}

	private function isCompletedNestedRollback(Throwable $exception): bool
	{
		return $exception instanceof TransactionException
			&& $exception->getMessage() === self::NESTED_ROLLBACK_COMPLETED
		;
	}

	private function writeDiagnostic(
		Throwable $originalException,
		Throwable $cleanupException,
	): void
	{
		try
		{
			Application::getInstance()->getExceptionHandler()->writeToLog(
				new TaskAddTransactionDiagnosticException(
				$originalException,
				$cleanupException,
				'unknown',
			),
			);
		}
		catch (Throwable)
		{
		}
	}
}
