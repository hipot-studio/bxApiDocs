<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Access\Service;

use Bitrix\Main\Access\AccessibleItem;
use Bitrix\Tasks\V2\Internal\Access\Factory\ControllerFactoryInterface;
use Bitrix\Tasks\V2\Internal\Access\Factory\Type;

trait ModelRightsTrait
{
	protected function getModelRights(
		Type $type,
		ControllerFactoryInterface $controllerFactory,
		array $rules,
		AccessibleItem $item,
		int $userId,
		array $params = [],
	): array
	{
		$controller = $controllerFactory->create($type, $userId);
		if ($controller === null)
		{
			return [];
		}

		$ruleChunks = [];
		$accessRequest = [];
		foreach ($rules as $name => $rule)
		{
			$ruleChunks[$rule][] = $name;
			$accessRequest[$rule] = $params[$name] ?? null;
		}

		$access = $controller->batchCheck($accessRequest, $item);

		$result = [];
		foreach ($access as $rule => $value)
		{
			$actions = $ruleChunks[$rule];
			foreach ($actions as $name)
			{
				$result[$name] = $value;
			}
		}

		return $result;
	}
}
