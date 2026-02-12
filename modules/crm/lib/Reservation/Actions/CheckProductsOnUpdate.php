<?php

namespace Bitrix\Crm\Reservation\Actions;

use Bitrix\Crm\Reservation\Validator;
use Bitrix\Main;
use Bitrix\Crm;
use Bitrix\Main\Loader;

final class CheckProductsOnUpdate extends Base
{
	public function process(Crm\Item $item): Main\Result
	{
		if (!Loader::includeModule('catalog'))
		{
			return new Main\Result();
		}

		$productRows = $item->getProductRows();
		if (!$productRows)
		{
			return new Main\Result();
		}

		$validatorList = Validator\Factory::getInstance()->getValidatorCollection([
			Validator\Factory::VALIDATOR_AVAILABLE_PRODUCT,
			Validator\Factory::VALIDATOR_CUSTOM_PRODUCT_RESERVE,
		]);
		foreach ($validatorList as $validator)
		{
			$validatorResult = $validator->validateCollection($productRows);
			if (!$validatorResult->isSuccess())
			{
				return $validatorResult;
			}
		}
		unset(
			$validatorResult,
			$validatorList,
		);

		$result = new Main\Result();
		if ($this->isMovedToSuccessfulStage($item))
		{
			$checkResult = self::checkQuantityFromCollection($item->getEntityTypeId(), $item->getId(), $productRows);
			if (!$checkResult->isSuccess())
			{
				Crm\Activity\Provider\StoreDocument::addProductActivity($item->getId());

				$result->addError(Crm\Reservation\Error\InventoryManagementError::create());
			}

			$checkResult = self::checkAvailabilityServices($productRows->toArray());
			if (!$checkResult->isSuccess())
			{
				Crm\Activity\Provider\StoreDocument::addServiceActivity($item->getId());

				$result->addError(Crm\Reservation\Error\AvailabilityServices::create());
			}
		}

		return $result;
	}
}
