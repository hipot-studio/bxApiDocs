<?php

namespace Bitrix\Iblock\Public\Command\Catalog;

/**
 * Price of one price type posted in an element row of the admin list for the row element or an offer of the row
 * product. Null means the value was not posted.
 */
final class PriceInput
{
	/**
	 * @param int $rowElementId Element of the grid row.
	 * @param int $targetId Element that owns the price: the row element or an offer of the row product.
	 * @param int $priceTypeId
	 * @param string|null $price
	 * @param string|null $currency
	 * @param string|null $oldPrice Price shown in the grid before editing.
	 * @param string|null $oldCurrency Currency shown in the grid before editing.
	 * @param string|null $priceId Id of the edited price record, empty for a new price.
	 * @param string|null $quantityFrom
	 * @param string|null $quantityTo
	 * @param string|null $extraId Markup of the price, posted for a price calculated from the base price.
	 */
	public function __construct(
		public readonly int $rowElementId,
		public readonly int $targetId,
		public readonly int $priceTypeId,
		public readonly ?string $price = null,
		public readonly ?string $currency = null,
		public readonly ?string $oldPrice = null,
		public readonly ?string $oldCurrency = null,
		public readonly ?string $priceId = null,
		public readonly ?string $quantityFrom = null,
		public readonly ?string $quantityTo = null,
		public readonly ?string $extraId = null,
	)
	{
	}
}
