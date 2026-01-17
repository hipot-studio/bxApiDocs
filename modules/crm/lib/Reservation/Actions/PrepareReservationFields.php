<?php

namespace Bitrix\Crm\Reservation\Actions;

use Bitrix\Crm\Item;
use Bitrix\Crm\ProductRowCollection;
use Bitrix\Crm\Reservation\ProductRowReservation;
use Bitrix\Crm\Service\Sale\Reservation\ReservationService;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

abstract class PrepareReservationFields extends Base
{
	protected ReservationService $service;

	public function __construct()
	{
		parent::__construct();

		$this->service = ReservationService::getInstance();
	}
	public function process(Item $item): Result
	{
		$result = new Result();

		$isAfterOperation = $this->getItemBeforeSave() !== null;
		if ($isAfterOperation)
		{
			$result->addError(
				new Error('Action can only be executing before saving')
			);

			return $result;
		}

		$productRows = $item->getProductRows();
		if ($productRows instanceof ProductRowCollection)
		{
			$this->prepareReservationFields($productRows);
		}

		return $result;
	}

	protected function prepareReservationFields(ProductRowCollection $productRows): void
	{
		foreach ($productRows as $row)
		{
			$productReservation = $row->getProductRowReservation();
			if (!$productReservation)
			{
				continue;
			}

			$this->prepareReservationRow($productReservation);
		}
	}

	abstract protected function prepareReservationRow(ProductRowReservation $productReservation): void;
}
