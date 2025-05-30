<?php

namespace Bitrix\Crm\Service\Operation\Action;

use Bitrix\Crm\Item;
use Bitrix\Crm\Recycling\ControllerManager;
use Bitrix\Crm\Service\Operation\Action;
use Bitrix\Main\Error;
use Bitrix\Main\ORM\Objectify\Values;
use Bitrix\Main\Result;

class MoveToBin extends Action
{
	public function process(Item $item): Result
	{
		$result = new Result();

		$recyclingController = ControllerManager::resolveController($item->getEntityTypeId());
		if (!$recyclingController)
		{
			$result->addError(
				new Error('Recycling controller for entityTypeId ' . $item->getEntityTypeId() . ' was not found'),
			);

			return $result;
		}

		$data = $item->getCompatibleData(Values::ALL, true);

		$recyclingController->moveToBin(
			$item->getId(),
			[
				'FIELDS' => $data,
			]
		);

		return $result;
	}
}
