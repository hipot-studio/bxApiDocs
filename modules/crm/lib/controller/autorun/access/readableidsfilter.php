<?php

namespace Bitrix\Crm\Controller\Autorun\Access;

use Bitrix\Crm\Item;
use Bitrix\Crm\Service\Factory;

/**
 * Narrows a list of requested entity ids down to the ones the current user may read.
 *
 * Read is checked here rather than the operation of the action itself: the base controller does not know
 * what its heir executes, and the right to act is checked per item in `processItem()`.
 */
final class ReadableIdsFilter
{
	/**
	 * @param int[] $requestedIds
	 *
	 * @return int[] the subset of $requestedIds readable by the current user
	 */
	public function narrowToReadable(Factory $factory, array $requestedIds): array
	{
		$items = $factory->getItemsFilteredByPermissions([
			'select' => [Item::FIELD_NAME_ID],
			'filter' => ['@ID' => $requestedIds],
		]);

		$readableIds = [];
		foreach ($items as $item)
		{
			$readableIds[] = $item->getId();
		}

		return $readableIds;
	}
}
