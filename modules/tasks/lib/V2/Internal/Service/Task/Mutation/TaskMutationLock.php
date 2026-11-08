<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Task\Mutation;

use Bitrix\Main\Application;
use Bitrix\Main\DB\SqlExpression;
use Bitrix\Tasks\Control\Exception\TaskNotExistsException;

class TaskMutationLock
{
	public function acquire(int $taskId, TaskMutationScope $scope): void
	{
		if ($scope->holds($taskId))
		{
			return;
		}

		$pool = Application::getInstance()->getConnectionPool();
		$pool->useMasterOnly(true);
		try
		{
			$query = new SqlExpression('SELECT ID FROM b_tasks WHERE ID = ?i FOR UPDATE', $taskId);
			$query->setConnection($scope->getConnection());
			$row = $scope->getConnection()->query($query->compile())->fetch();
		}
		finally
		{
			$pool->useMasterOnly(false);
		}

		if (!$row)
		{
			throw new TaskNotExistsException();
		}

		$scope->rememberTask($taskId);
	}
}
