<?php

declare(strict_types=1);

namespace Bitrix\HumanResources\Access\Strategy;

use Bitrix\HumanResources\Access\Model\NodeModel;
use Bitrix\Main\Access\AccessibleController;
use Bitrix\Main\Engine\ActionFilter\Access\AccessCheckStrategyInterface;

final class NodeAccessCheckStrategy implements AccessCheckStrategyInterface
{
	private function __construct(
		private readonly AccessibleController $accessController,
		private readonly array $requestKeys,
	)
	{
	}

	public static function create(
		AccessibleController $accessController,
		array $config,
	): static
	{
		return new static(
			accessController: $accessController,
			requestKeys: $config['requestKeys'] ?? [],
		);
	}

	/**
	 * Checks node access using request data.
	 *
	 * Node ID resolution priority (only among keys listed in requestKeys):
	 *   1. targetNodeId — used as primary node ID
	 *   2. nodeId — fallback if targetNodeId is absent
	 *   3. parentId — sets target parent node on the model
	 *
	 * If neither node ID nor parentId is found, checks access against a null-ID node.
	 */
	public function check(
		string $action,
		array $requestData,
	): bool
	{
		$keySet = array_flip($this->requestKeys);

		$id = isset($keySet['targetNodeId']) ? ($requestData['targetNodeId'] ?? null) : null;

		if (!$id && isset($keySet['nodeId']))
		{
			$id = $requestData['nodeId'] ?? null;
		}

		$parentId = isset($keySet['parentId']) ? ($requestData['parentId'] ?? null) : null;

		if (!$id && !$parentId)
		{
			$item = NodeModel::createFromId(null);

			return $this->accessController->check($action, $item);
		}

		$item = is_numeric($id)
			? NodeModel::createFromId((int)$id)
			: NodeModel::createFromId(null);

		if ($parentId && is_numeric($parentId))
		{
			$item->setTargetNodeId((int)$parentId);
		}

		return $this->accessController->check($action, $item);
	}
}
