<?php

namespace Bitrix\Iblock\Infrastructure\Admin\ElementList\InlineEdit;

use Bitrix\Iblock\Public\Command\Catalog\PriceInput;
use Bitrix\Iblock\Public\Command\Catalog\PurchasingPriceInput;

/**
 * Catalog values of inline editing in the admin element lists, as they were posted.
 */
final class InlineEditRequest
{
	/**
	 * @param int[] $rowElementIds Elements of the posted grid rows.
	 * @param PriceInput[] $prices
	 * @param PurchasingPriceInput[] $purchasingPrices
	 * @param int[] $foreignPurchasingCellRowIds Rows whose purchasing price cell was posted for another element.
	 */
	public function __construct(
		public readonly array $rowElementIds = [],
		public readonly array $prices = [],
		public readonly array $purchasingPrices = [],
		public readonly array $foreignPurchasingCellRowIds = [],
	)
	{
	}
}
