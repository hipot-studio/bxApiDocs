<?php

namespace Bitrix\Catalog\Controller;

use Bitrix\Catalog\Access\ActionDictionary;
use Bitrix\Catalog\PriceTable;
use Bitrix\Main\Error;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Result;
use Bitrix\Rest\Event\EventBindInterface;
use Bitrix\Catalog\GroupTable;
use Bitrix\Catalog\Config\Feature;

final class Price extends Controller implements EventBindInterface
{
	use ListAction; // default listAction realization
	use GetAction; // default getAction realization
	use CheckExists; // default implementation of existence check

	//region Actions
	/**
	 * @return array
	 */
	public function getFieldsAction(): array
	{
		return [$this->getServiceItemName() => $this->getViewFields()];
	}

	/**
	 * public function listAction
	 * @see ListAction::listAction
	 */

	/**
	 * public function getAction
	 * @see GetAction::getAction
	 */

	/**
	 * Update all product prices.
	 *
	 * @param array $fields
	 * @return array|null
	 */
	public function modifyAction(array $fields): ?array
	{
		if (!is_array($fields['PRODUCT']['PRICES']))
		{
			$this->addError(new Error('Product prices are empty'));

			return null;
		}

		$r = $this->modifyValidate($fields['PRODUCT']['PRICES']);

		if ($r->isSuccess())
		{
			$fields['PRODUCT']['ID'] = self::normalizeId($fields['PRODUCT']['ID'] ?? null);
			$r = $this->checkPermissionIBlockElementPriceModify($fields['PRODUCT']['ID']);
		}

		if ($r->isSuccess())
		{
			$r = $this->checkPricesBelongToProduct($fields['PRODUCT']['ID'], $fields['PRODUCT']['PRICES']);
		}

		if ($r->isSuccess())
		{
			$r = $this->modifyBefore($fields);
			if($r->isSuccess())
			{
				$r = $this->modify($fields);
			}
		}

		if (!$r->isSuccess())
		{
			$this->addErrors($r->getErrors());

			return null;
		}

		$ids = $r->getData()[0];
		$entityTable = $this->getEntityTable();

		return [
			'PRICES' =>
				$entityTable::getList(
					['filter' => ['=ID' => $ids]]
				)
				->fetchAll()
		];
	}

	/**
	 * @param int $id
	 * @return bool|null
	 */
	public function deleteAction(int $id): ?bool
	{
		$r = $this->exists($id);
		if ($r->isSuccess())
		{
			$r = $this->deleteValidate($id);
			if ($r->isSuccess())
			{
				$r = \Bitrix\Catalog\Model\Price::delete($id);
			}
		}

		if (!$r->isSuccess())
		{
			$this->addErrors($r->getErrors());

			return null;
		}

		return true;
	}

	/**
	 * @param int $id
	 * @param array $fields
	 * @return array|null
	 */
	public function updateAction(int $id, array $fields): ?array
	{
		$price = $this->get($id);
		if (!$price)
		{
			$this->addError(new Error('Price is not exists'));

			return null;
		}

		$fields = array_merge($price, $fields);

		return $this->modifySingleProductPrice((int)$price['PRODUCT_ID'], $fields);
	}

	/**
	 * @param array $fields
	 * @return array|null
	 */
	public function addAction(array $fields): ?array
	{
		unset($fields['ID']);

		return $this->modifySingleProductPrice(self::normalizeId($fields['PRODUCT_ID'] ?? null), $fields);
	}
	//endregion

	private function modifySingleProductPrice(int $productId, array $fields): ?array
	{
		$resultGroupType = GroupTable::getRowById((int)$fields['CATALOG_GROUP_ID']);
		if (!$resultGroupType)
		{
			$this->addError(new Error('Validate price error. Catalog price group is wrong'));

			return null;
		}

		$entityTable = $this->getEntityTable();
		$prices =
			$entityTable::getList(
				[
					'filter' => [
						'=PRODUCT_ID' => $productId,
						'=CATALOG_GROUP_ID' => $fields['CATALOG_GROUP_ID'],
					]
				]
			)
			->fetchAll()
		;

		$prices = array_combine(array_column($prices, 'ID'), $prices);
		$prices[$fields['ID'] ?? 0] = $fields;

		$r = $this->modifyValidate($prices);
		if ($r->isSuccess())
		{
			$r = $this->modify([
				'PRODUCT' => [
					'ID' => $productId,
					'PRICES' => [$fields]
				]
			]);
		}

		if (!$r->isSuccess())
		{
			$this->addErrors($r->getErrors());
			return null;
		}

		$ids = $r->getData()[0];

		return [
			$this->getServiceItemName() => $this->get($ids[0])
		];
	}

	protected function modify($fields)
	{
		$ids = [];
		$productId = self::normalizeId($fields['PRODUCT']['ID']);
		$prices = $fields['PRODUCT']['PRICES'];

		$r = $this->checkPermissionIBlockElementPriceModify($productId);
		if ($r->isSuccess())
		{
			$r = $this->checkPricesBelongToProduct($productId, $prices);
		}

		if($r->isSuccess())
		{
			foreach ($prices as $price)
			{
				if(isset($price['ID']))
				{
					$priceId = self::normalizeId($price['ID']);
					self::normalizeFields($price);
					$price['ID'] = $priceId;

					$result = \Bitrix\Catalog\Model\Price::update($priceId, $price);
					if($result->isSuccess())
					{
						$ids[] = $priceId;
					}
				}
				else
				{
					$result = \Bitrix\Catalog\Model\Price::add([
						'PRODUCT_ID' => $productId,
						'CATALOG_GROUP_ID' => $price['CATALOG_GROUP_ID'],
						'CURRENCY' => $price['CURRENCY'],
						'PRICE' => $price['PRICE'],
						'QUANTITY_FROM' => isset($price['QUANTITY_FROM']) ? $price['QUANTITY_FROM']:null,
						'QUANTITY_TO' => isset($price['QUANTITY_TO']) ? $price['QUANTITY_TO']:null,
						'EXTRA_ID' => isset($price['EXTRA_ID']) ? $price['EXTRA_ID']:null,
					]);

					if($result->isSuccess())
					{
						$ids[] = $result->getId();
					}
				}

				if($result->isSuccess() == false)
				{
					$r->addErrors($result->getErrors());
				}
			}

			if($r->isSuccess())
				$r->setData([$ids]);
		}

		return $r;
	}

	private function checkPricesBelongToProduct(int $productId, array $prices): Result
	{
		$result = new Result();

		$priceIds = [];
		foreach ($prices as $price)
		{
			if (!isset($price['ID']))
			{
				continue;
			}

			$priceId = self::normalizeId($price['ID']);
			if (
				$priceId === 0
				|| (isset($price['PRODUCT_ID']) && self::normalizeId($price['PRODUCT_ID']) !== $productId)
			)
			{
				$result->addError($this->getErrorElementPriceModifyAccessDenied());

				return $result;
			}

			$priceIds[$priceId] = $priceId;
		}

		if (empty($priceIds))
		{
			return $result;
		}

		$ownPriceCount = PriceTable::getCount([
			'@ID' => array_values($priceIds),
			'=PRODUCT_ID' => $productId,
		]);
		if ($ownPriceCount !== count($priceIds))
		{
			$result->addError($this->getErrorElementPriceModifyAccessDenied());
		}

		return $result;
	}

	protected function modifyBefore($fields)
	{
		$productId = self::normalizeId($fields['PRODUCT']['ID']);

		$ids = [];
		$prices = $fields['PRODUCT']['PRICES'];
		foreach ($prices as $price)
		{
			if (isset($price['ID']))
			{
				$ids[] = self::normalizeId($price['ID']);
			}
		}

		$entityTable = $this->getEntityTable();

		$res = $entityTable::getList(['filter' => ['=PRODUCT_ID' => $productId]]);
		while ($item = $res->fetch())
		{
			if (!in_array(self::normalizeId($item['ID']), $ids, true))
			{
				$entityTable::delete($item['ID']);
			}
		}

		return new Result();
	}

	private static function normalizeId(mixed $value): int
	{
		if (is_string($value) && preg_match('/^[0-9]+$/D', $value) === 1)
		{
			$value = (int)$value;
		}

		return is_int($value) && $value > 0 ? $value : 0;
	}

	private static function normalizeFields(array &$fields)
	{
		if (isset($fields['QUANTITY_FROM']))
		{
			if (is_string($fields['QUANTITY_FROM']) && $fields['QUANTITY_FROM'] === '')
				$fields['QUANTITY_FROM'] = null;
			elseif ($fields['QUANTITY_FROM'] === false || $fields['QUANTITY_FROM'] === 0)
				$fields['QUANTITY_FROM'] = null;
		}
		else
		{
			$fields['QUANTITY_FROM'] = null;
		}

		if (isset($fields['QUANTITY_TO']))
		{
			if (is_string($fields['QUANTITY_TO']) && $fields['QUANTITY_TO'] === '')
				$fields['QUANTITY_TO'] = null;
			elseif ($fields['QUANTITY_TO'] === false || $fields['QUANTITY_TO'] === 0)
				$fields['QUANTITY_TO'] = null;
		}
		else
		{
			$fields['QUANTITY_TO'] = null;
		}

		if (isset($fields['EXTRA_ID']))
		{
			if (is_string($fields['EXTRA_ID']) && $fields['EXTRA_ID'] === '')
				$fields['EXTRA_ID'] = null;
			elseif ($fields['EXTRA_ID'] === false)
				$fields['EXTRA_ID'] = null;
		}
		else
		{
			$fields['EXTRA_ID'] = null;
		}
	}

	private function modifyValidate(array $items): Result
	{
		$r = new Result();
		$items = array_values($items);
		$basePriceType = GroupTable::getBasePriceType();
		$basePriceTypeId = $basePriceType['ID'];
		$groupTypes = GroupTable::getTypeList();
		$sortedByType = [];
		$extendPrices = false;
		foreach ($items as $fields)
		{
			$groupId = (int)$fields['CATALOG_GROUP_ID'];
			if (!isset($groupTypes[$groupId]))
			{
				$r->addError(new Error('Validate price error. Catalog price group is wrong'));

				return $r;
			}

			if (!$extendPrices)
			{
				$extendPrices = (isset($fields['QUANTITY_FROM']) || isset($fields['QUANTITY_TO']));
			}
			$sortedByType[$groupId][] = $fields;
		}

		$allowEmptyRange = Option::get('catalog', 'save_product_with_empty_price_range') === 'Y';
		$enableQuantityRanges = Feature::isPriceQuantityRangesEnabled();

		if (!$extendPrices)
		{
			if (count($items) > count($sortedByType))
			{
				$r->addError(new Error('Validate price error. Catalog product is allowed has only single price without ranges in price group.'));
			}

			return $r;
		}

		if ($enableQuantityRanges === false)
		{
			$r->addError(new Error('Validate price error. Price quantity ranges disabled'));

			return $r;
		}

		$basePrices = $sortedByType[$basePriceTypeId];
		if (!$basePrices)
		{
			$r->addError(new Error('Validate price error. Ranges of base price are not equal to another price group range'));

			return $r;
		}

		$basePrices = $this->sortPriceRanges($basePrices);

		foreach ($sortedByType as $typeId => $prices)
		{
			$count = count($prices);
			$prices = $this->sortPriceRanges($prices);

			foreach ($prices as $i => $item)
			{
				$quantityFrom = (float)$item['QUANTITY_FROM'];
				$quantityTo = (float)$item['QUANTITY_TO'];

				if (
					$typeId !== $basePriceTypeId
					&& (
						!isset($basePrices[$i])
						|| $quantityFrom !== (float)$basePrices[$i]['QUANTITY_FROM']
						|| $quantityTo !== (float)$basePrices[$i]['QUANTITY_TO']
					)
				)
				{
					$r->addError(
						new Error(
							'Validate price error. Ranges of base price are not equal to another price group range'
						)
					);

					return $r;
				}

				if (
					($i !== 0 && $quantityFrom <= 0)
					|| ($i === 0 && $quantityFrom < 0)
				)
				{
					$r->addError(
						new Error(
							"Quantity bounds error: lower bound {$quantityFrom} must be above zero (for the first range)"
						)
					);
				}

				if (
					($i !== $count-1 && $quantityTo <= 0)
					|| ($i === $count-1 && $quantityTo < 0)
				)
				{
					$r->addError(
						new Error(
							"Quantity bounds error: higher bound {$quantityTo} must be above zero (for the last range)"
						)
					);

				}

				if ($quantityFrom > $quantityTo	&& ($i !== $count-1 || $quantityTo > 0))
				{
					$r->addError(
						new Error(
							"Quantity bounds error: range {$quantityFrom}-{$quantityTo} is incorrect"
						)
					);
				}

				$nextQuantityFrom = (float)$prices[$i + 1]["QUANTITY_FROM"];
				$nextQuantityTo = (float)$prices[$i + 1]["QUANTITY_TO"];
				if ($i < $count-1 && $quantityTo >= $nextQuantityFrom)
				{
					$r->addError(
						new Error(
							"Quantity bounds error: ranges {$quantityFrom}-{$quantityTo} and {$nextQuantityFrom}-{$nextQuantityTo} overlap"
						)
					);
				}

				if (
					$i < $count-1
					&& $nextQuantityFrom - $quantityTo > 1
					&& !$allowEmptyRange
				)
				{
					$validRangeFrom = $quantityTo + 1;
					$validRangeTo = $nextQuantityFrom - 1;

					$r->addError(
						new Error(
							"Invalid quantity range entry: no price is specified for range {$validRangeFrom}-{$validRangeTo})"
						)
					);
				}

				if ($i >= $count-1 && $quantityTo > 0)
				{
					$r->addError(
						new Error(
							"Invalid quantity range entry: no price is specified for quantity over {$quantityTo}"
						)
					);
				}
			}
		}

		return $r;
	}

	private function sortPriceRanges(array $prices): array
	{
		$count = count($prices);

		for ($i = 0; $i < $count - 1; $i++)
		{
			for ($j = $i + 1; $j < $count; $j++)
			{
				if ($prices[$i]["QUANTITY_FROM"] > $prices[$j]["QUANTITY_FROM"])
				{
					$tmp = $prices[$i];
					$prices[$i] = $prices[$j];
					$prices[$j] = $tmp;
				}
			}
		}

		return $prices;
	}

	protected function getEntityTable()
	{
		return new PriceTable();
	}

	protected function deleteValidate(int $id): Result
	{
		$price = PriceTable::getRow([
			'select' => [
				'ID',
				'PRODUCT_ID',
			],
			'filter' => [
				'=ID' => $id,
			],
		]);
		if ($price === null)
		{
			$result = new Result();
			$result->addError($this->getErrorEntityNotExists());

			return $result;
		}

		return $this->checkPermissionIBlockElementPriceModify((int)$price['PRODUCT_ID']);
	}

	//region checkPermissionController
	protected function checkPermissionEntity($name, $arguments=[])
	{
		$name = mb_strtolower($name); //for ajax mode

		if($name == 'modify')
		{
			$r = $this->checkModifyPermissionEntity();
		}
		else
		{
			$r = parent::checkPermissionEntity($name);
		}
		return $r;
	}

	protected function checkModifyPermissionEntity()
	{
		$r = $this->checkReadPermissionEntity();
		if ($r->isSuccess())
		{
			if (!$this->accessController->check(ActionDictionary::ACTION_PRICE_EDIT))
			{
				$r->addError($this->getErrorModifyAccessDenied());
			}
		}

		return $r;
	}

	protected function checkReadPermissionEntity()
	{
		$r = new Result();

		if (
			!$this->accessController->check(ActionDictionary::ACTION_CATALOG_READ)
			&& !$this->accessController->check(ActionDictionary::ACTION_PRICE_EDIT)
		)
		{
			$r->addError($this->getErrorReadAccessDenied());
		}
		return $r;
	}
	//endregion

	//region checkPermissionIBlock
	private function checkPermissionIBlockElementPriceModify(int $productId): Result
	{
		$r = new Result();

		if ($productId <= 0)
		{
			$r->addError($this->getErrorElementPriceModifyAccessDenied());

			return $r;
		}

		$iblockId = \CIBlockElement::GetIBlockByID($productId);
		if(!\CIBlockElementRights::UserHasRightTo($iblockId, $productId, self::IBLOCK_ELEMENT_EDIT_PRICE))
		{
			$r->addError($this->getErrorElementPriceModifyAccessDenied());
		}
		return $r;
	}

	private function getErrorElementPriceModifyAccessDenied(): Error
	{
		return new Error('Access Denied', 200040300030);
	}
	//endregion
}
