<?php

namespace Bitrix\Crm\Reservation\Actions;

use Bitrix\Main;
use Bitrix\Crm\Reservation\Validator;
use Bitrix\Crm;
use CCrmOwnerType;

final class CheckProductsOnAdd extends Base
{
	public function process(Crm\Item $item): Main\Result
	{
		$result = new Main\Result();

		$factory = Crm\Service\Container::getInstance()->getFactory($item->getEntityTypeId());
		if (!$factory)
		{
			return $result;
		}

		$productRows = $item->getProductRows();
		if (!$productRows)
		{
			return new Main\Result();
		}

		$validatorList = Validator\Factory::getInstance()->getValidatorCollection([
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

		if ($this->isSuccessStage($item))
		{
			$checkResult = self::checkQuantityFromCollection(CCrmOwnerType::Deal, 0, $productRows);
			if (!$checkResult->isSuccess())
			{
				$stageId = $factory->setStartStageIdPermittedForUser($item);
				$item->setStageId($stageId);
			}

			$checkResult = self::checkAvailabilityServices($productRows->toArray());
			if (!$checkResult->isSuccess())
			{
				$stageId = $factory->setStartStageIdPermittedForUser($item);
				$item->setStageId($stageId);
			}
		}

		return $result;
	}
}
