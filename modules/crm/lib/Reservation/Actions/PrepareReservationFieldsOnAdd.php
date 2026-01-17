<?php

namespace Bitrix\Crm\Reservation\Actions;

use Bitrix\Crm\Reservation\ProductRowReservation;
use Bitrix\Main\ORM\Objectify\Values;

class PrepareReservationFieldsOnAdd extends PrepareReservationFields
{
	protected function prepareReservationRow(ProductRowReservation $productReservation): void
	{
		$currentValues = $productReservation->collectValues(Values::CURRENT);

		if (empty($currentValues))
		{
			return;
		}

		$productReservation->setDateReserveEnd($this->service->prepareDateReserveEndWithTime(
			$currentValues['DATE_RESERVE_END'] ?? null
		));
	}
}
