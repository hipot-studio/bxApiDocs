<?php

namespace Bitrix\HumanResources\Item\Collection;

use Bitrix\HumanResources\Item;

/**
 * @extends BaseCollection<Item\NodeMember>
 */
class NodeMemberCollection extends BaseCollection
{
	/**
	 * @param array $items
	 * @return static
	 */
	public static function wakeUp(array $items): static
	{
		$collection = new static();
		foreach ($items as $item)
		{
			if (is_array($item))
			{
				$collection->add(Item\NodeMember::wakeUp($item));
			}
		}

		return $collection;
	}

	/**
	 * @return list<int>
	 */
	public function getNodeIds(): array
	{
		return array_values(
			$this->map(
				static fn(Item\NodeMember $member) => $member->nodeId,
			),
		);
	}

	public function getUniqueNodeIds(): array
	{
		return array_values(
			array_unique(
				$this->map(
					static fn(Item\NodeMember $member) => $member->nodeId,
				),
			)
		);
	}

	/**
	 * @return list<int>
	 */
	public function getEntityIds(): array
	{
		return array_values(
			$this->map(
				static fn(Item\NodeMember $member) => $member->entityId,
			),
		);
	}
}
