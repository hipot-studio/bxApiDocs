<?php

namespace Bitrix\Sale\Exchange\Entity\SubordinateSale;

class Order extends \Bitrix\Sale\Exchange\Entity\OrderImport
{
	/**
	 * @param \Bitrix\Sale\BasketBase $basket
	 * @param array $item
	 * @return \Bitrix\Sale\BasketItem|bool
	 *
	 * Для схемы подчиненных документов, для типового модуля обмена от 1С свойства у товаров не проверяются.
	 * Проверка происходит только по идентификатору
	 */
	static public function getBasketItemByItem(\Bitrix\Sale\BasketBase $basket, array $item)
	{
		return parent::getBasketItemByItem($basket, $item);
	}

	protected static function matchesBasketItem(\Bitrix\Sale\BasketItem $basketItem, array $item): bool
	{
		return $item['ID'] == $basketItem->getField('PRODUCT_XML_ID');
	}

	/**
	 * @param $typeId
	 * @return \Bitrix\Sale\Exchange\ImportBase
	 * @internal
	 */
	protected function entityCreateByFactory($typeId)
	{
		return \Bitrix\Sale\Exchange\Entity\SubordinateSale\EntityImportFactory::create($typeId);
	}
}