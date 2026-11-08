<?php

namespace Bitrix\Mobile\Tab\Preset\Service;

use Bitrix\Main\Application;
use Bitrix\Main\DB\Connection;

final class PresetWriteTransaction
{
	private int $depth = 0;
	private ?\Throwable $rollbackCause = null;
	private array $afterCommitCallbacks = [];

	public function __construct(
		private readonly ?Connection $connection = null,
	)
	{
	}

	public function run(callable $callback): mixed
	{
		if ($this->depth > 0)
		{
			$this->depth++;

			try
			{
				return $callback();
			}
			catch (\Throwable $exception)
			{
				$this->rollbackCause ??= $exception;

				throw $exception;
			}
			finally
			{
				$this->depth--;
			}
		}

		$connection = $this->connection ?? Application::getConnection();
		$this->afterCommitCallbacks = [];
		$connection->startTransaction();
		$this->depth = 1;

		try
		{
			$result = $callback();
		}
		catch (\Throwable $exception)
		{
			$this->depth = 0;
			$this->rollbackCause = null;
			$this->afterCommitCallbacks = [];
			$connection->rollbackTransaction();

			throw $exception;
		}

		$this->depth = 0;
		if ($this->rollbackCause !== null)
		{
			$exception = $this->rollbackCause;
			$this->rollbackCause = null;
			$this->afterCommitCallbacks = [];
			$connection->rollbackTransaction();

			throw $exception;
		}

		try
		{
			$connection->commitTransaction();
		}
		catch (\Throwable $exception)
		{
			$this->afterCommitCallbacks = [];
			$connection->rollbackTransaction();

			throw $exception;
		}

		$this->runAfterCommitCallbacks();

		return $result;
	}

	public function afterCommit(callable $callback): void
	{
		if ($this->depth === 0)
		{
			$callback();

			return;
		}

		$this->afterCommitCallbacks[] = $callback;
	}

	private function runAfterCommitCallbacks(): void
	{
		$callbacks = $this->afterCommitCallbacks;
		$this->afterCommitCallbacks = [];
		$firstException = null;

		foreach ($callbacks as $callback)
		{
			try
			{
				$callback();
			}
			catch (\Throwable $exception)
			{
				$firstException ??= $exception;
			}
		}

		if ($firstException !== null)
		{
			throw $firstException;
		}
	}
}
