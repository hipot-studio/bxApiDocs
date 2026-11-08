<?php

namespace Bitrix\Iblock\Public\Provider\Catalog;

use Bitrix\Iblock\Internal\Service\Catalog\InlineEditTargetFilter;

/**
 * Reads the targets of inline editing in the admin element list of an iblock.
 */
final class InlineEditTargetProvider
{
	/**
	 * Returns iblocks whose element rows may be saved from the list: the iblock of the list and the SKU iblock
	 * of its catalog.
	 *
	 * @param int $listIblockId
	 * @return int[]
	 */
	public function getEditableIblockIds(int $listIblockId): array
	{
		return (new InlineEditTargetFilter($listIblockId, false))->getEditableIblockIds();
	}

	/**
	 * Returns products that have active offers. Product rows of such products show the selected offer.
	 *
	 * @param int $listIblockId
	 * @param int[] $productIds
	 * @return int[]
	 */
	public function getProductIdsWithOffers(int $listIblockId, array $productIds): array
	{
		return (new InlineEditTargetFilter($listIblockId, true))->getProductIdsWithOffers($productIds);
	}
}
