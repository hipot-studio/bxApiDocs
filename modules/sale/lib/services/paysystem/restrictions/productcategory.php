<?php

namespace Bitrix\Sale\Services\PaySystem\Restrictions;

use Bitrix\Main\Localization\Loc;
use Bitrix\Sale\Services\Base\ProductCategoryRestriction;

Loc::loadMessages(__FILE__);

class ProductCategory extends ProductCategoryRestriction
{
	use PayableBasketItemsTrait;

	/**
	 * @return string
	 */
	protected static function getJsHandler(): string
	{
		return 'BX.Sale.PaySystem';
	}

	/**
	 * Returns the restriction description
	 * @return string
	 */
	public static function getClassDescription() : string
	{
		return '';
	}
}
