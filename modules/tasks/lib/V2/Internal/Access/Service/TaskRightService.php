<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Access\Service;

use Bitrix\Tasks\Access\AccessCacheLoader;
use Bitrix\Tasks\Access\Model\TaskModel;
use Bitrix\Tasks\V2\Internal\Access\Factory\ControllerFactoryInterface;
use Bitrix\Tasks\V2\Internal\Access\Factory\Type;
use Bitrix\Tasks\V2\Internal\Access\Task\ActionDictionary;

class TaskRightService
{
	use UserRightsTrait;
	use ModelRightsTrait;

	public function __construct(
		private readonly ControllerFactoryInterface $controllerFactory,
		private readonly AccessCacheLoader $accessCacheLoader,
	)
	{

	}

	public function canView(int $userId, int $taskId): bool
	{
		return $this->can($userId, $taskId, ActionDictionary::TASK_ACTIONS['read']);
	}

	public function can(int $userId, int $taskId, string $rule): bool
	{
		return $this->get([$rule => $rule], $taskId, $userId)[$rule] ?? false;
	}

	public function getTaskRightsBatch(int $userId, array $taskIds, array $rules = ActionDictionary::TASK_ACTIONS, array $params = []): array
	{
		$this->accessCacheLoader->preload($userId, $taskIds);

		$access = [];
		foreach ($taskIds as $taskId)
		{
			$access[$taskId] = $this->get($rules, $taskId, $userId, $params);
		}

		return $access;
	}

	public function getUserRights(int $userId, array $rules = ActionDictionary::USER_ACTIONS['tasks']): array
	{
		return $this->getUserRightsByType(
			userId: $userId,
			rules: $rules,
			type: Type::Task,
			controllerFactory: $this->controllerFactory,
		);
	}

	public function getUserRightBatch(string $rule, int $taskId, array $userIds): array
	{
		$access = array_fill_keys($userIds, false);

		$item = TaskModel::createFromId($taskId);

		foreach ($userIds as $userId)
		{
			$controller = $this->controllerFactory->create(Type::Task, $userId);
			if ($controller === null)
			{
				continue;
			}

			$access[$userId] = $controller->check($rule, $item);
		}

		return $access;
	}

	public function get(array $rules, int $taskId, int $userId, array $params = []): array
	{
		return $this->getModelRights(
			type: Type::Task,
			controllerFactory: $this->controllerFactory,
			rules: $rules,
			item: TaskModel::createFromId($taskId),
			userId: $userId,
			params: $params,
		);
	}
}
