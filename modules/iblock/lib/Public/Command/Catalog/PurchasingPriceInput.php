<?php

namespace Bitrix\Iblock\Public\Command\Catalog;

/**
 * Changed purchasing price of the offer selected in a product row of the admin list.
 */
final class PurchasingPriceInput
{
	/**
	 * @param int $rowElementId Product of the grid row.
	 * @param int $offerId Offer that owns the purchasing price.
	 * @param string $price Price as posted; empty clears the purchasing price.
	 * @param string $currency
	 */
	public function __construct(
		public readonly int $rowElementId,
		public readonly int $offerId,
		public readonly string $price,
		public readonly string $currency,
	)
	{
	}
}
