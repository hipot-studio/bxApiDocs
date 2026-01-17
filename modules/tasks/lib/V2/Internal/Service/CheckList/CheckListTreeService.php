<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\CheckList;

class CheckListTreeService
{
	public function __construct(
		private readonly NodeIdGenerator $nodeIdGenerator,
	)
	{
	}

	public function buildTree(array $items): array
	{
		if (empty($items))
		{
			return [];
		}

		$items = $this->fillNodeIdsForItems($items);

		return $this->fillParentNodeIdsForItems($items);
	}

	private function fillNodeIdsForItems(array $items): array
	{
		$items = array_map(function (array $item): array
		{
			$id = $item['ID'] ?? 0;
			$title = $item['TITLE'] ?? '';

			$item['NODE_ID'] = $this->nodeIdGenerator->generate($title . $id);

			return $item;
		}, $items);

		return array_column($items, null, 'NODE_ID');
	}

	private function fillParentNodeIdsForItems(array $items): array
	{
		$itemsByIds = array_column($items, null, 'ID');

		return array_map(static function (array $item) use ($itemsByIds): array
		{
			$parentId = (int)($item['PARENT_ID'] ?? 0);

			if (isset($itemsByIds[$parentId]))
			{
				$item['PARENT_NODE_ID'] = $itemsByIds[$parentId]['NODE_ID'] ?? null;
			}

			return $item;
		}, $items);
	}
}
