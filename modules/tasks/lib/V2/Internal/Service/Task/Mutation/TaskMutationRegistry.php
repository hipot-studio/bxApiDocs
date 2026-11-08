<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Task\Mutation;

use Bitrix\Main\DB\Connection;
use Throwable;

class TaskMutationRegistry
{
	/** @var \WeakMap<Connection, TaskMutationScope> */
	private \WeakMap $scopes;

	public function __construct()
	{
		$this->scopes = new \WeakMap();
	}

	public function active(Connection $connection): ?TaskMutationScope
	{
		return $this->scopes[$connection] ?? null;
	}

	public function commit(Connection $connection, TaskMutationScope $scope): void
	{
		if ($this->active($connection) !== $scope)
		{
			throw new \LogicException('Cannot commit an inactive task mutation scope.');
		}

		$connection->commitTransaction();
		$scope->markCommitted();
		unset($this->scopes[$connection]);
	}

	public function run(Connection $connection, callable $callback): mixed
	{
		$scope = $this->active($connection);
		$isRoot = $scope === null;
		$scope ??= new TaskMutationScope($connection);

		if ($isRoot)
		{
			$connection->startTransaction();
			$this->scopes[$connection] = $scope;
		}

		try
		{
			$result = $callback($scope);

			if ($isRoot && !$scope->isCommitted())
			{
				$connection->commitTransaction();
			}

			return $result;
		}
		catch (Throwable $exception)
		{
			if ($isRoot && !$scope->isCommitted())
			{
				TaskMutationScope::rollbackTransaction($connection);
			}

			throw $exception;
		}
		finally
		{
			if ($isRoot)
			{
				unset($this->scopes[$connection]);
			}
		}
	}
}
