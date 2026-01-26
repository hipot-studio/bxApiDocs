<?php

namespace Bitrix\Sale\Services\PaySystem\Restrictions;

use Bitrix\Main\Localization\Loc;
use Bitrix\Sale\Services\Base\ConcreteProductRestriction;

Loc::loadMessages(__FILE__);

/**
 * Class ConcreteProduct
 * Restrictions paysystem by concrete products
 * @package Bitrix\Sale\Services\PaySystem\Restrictions
 */
class ConcreteProduct extends ConcreteProductRestriction
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
