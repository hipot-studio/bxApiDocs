<?php

namespace Bitrix\Sign\Factory\Access;

use Bitrix\Sign\Contract;
use Bitrix\Sign\Item;

class AccessibleItemFactory
{
	public function createFromItem(Contract\Item $item): ?Contract\Access\AccessibleItem
	{
		if ($item instanceof Item\Document)
		{
			return new Item\Access\Document($item);
		}
		if ($item instanceof Item\Document\SafeFolder)
		{
			// Company safe folder is an owner-scoped access item (no CRM entity). The safe access
			// hard gate is enforced by SafeFolderRule keyed on the safe folder action.
			return new Item\Access\SimpleAccessibleItemWithOwner($item->getId(), $item->getOwnerId());
		}
		if (!$item instanceof Contract\Item\ItemWithOwner)
		{
			return null;
		}

		if ($item instanceof Contract\Item\ItemWithCrmEntity)
		{
			return new Item\Access\SimpleAccessibleItemWithOwner(
				$item->getId(),
				$item->getOwnerId(),
				$item->getCrmId(),
				$item->getCrmEntityTypeId(),
			);
		}

		return new Item\Access\SimpleAccessibleItemWithOwner($item->getId(), $item->getOwnerId());
	}
}