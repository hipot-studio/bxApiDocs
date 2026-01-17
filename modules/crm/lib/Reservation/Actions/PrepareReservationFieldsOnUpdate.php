<?php

namespace Bitrix\Crm\Reservation\Actions;

use Bitrix\Crm\Reservation\Tools\DateTimeComparator;
use Bitrix\Crm\Reservation\ProductRowReservation;
use Bitrix\Main\ORM\Objectify\Values;

class PrepareReservationFieldsOnUpdate extends PrepareReservationFields
{
	protected function prepareReservationRow(ProductRowReservation $productReservation): void
	{
		$currentValues = $productReservation->collectValues(Values::CURRENT);
		if (empty($currentValues))
		{
			$this->prepareDateReserveEnd($productReservation);

			return;
		}

		if (!array_key_exists('DATE_RESERVE_END', $currentValues))
		{
			$this->prepareDateReserveEnd($productReservation);

			return;
		}

		$dateReserveEnd = $currentValues['DATE_RESERVE_END'];
		if ($dateReserveEnd === null || $dateReserveEnd === '')
		{
			$actualValues = $productReservation->collectValues(Values::ACTUAL);
			if (!empty($actualValues['DATE_RESERVE_END']))
			{
				$dateReserveEnd = $actualValues['DATE_RESERVE_END'];
			}
		}

		$productReservation->setDateReserveEnd($this->service->prepareDateReserveEndWithTime(
			$dateReserveEnd
		));
	}

	/**
	 * Fix empty date and date without worktime end.
	 *
	 * @param ProductRowReservation $productReservation
	 * @return void
	 */
	private function prepareDateReserveEnd(ProductRowReservation $productReservation): void
	{
		$currentDate = $productReservation->getDateReserveEnd();
		$updateDate = $this->service->prepareDateReserveEndWithTime($currentDate);
		if (!DateTimeComparator::areEqual($updateDate, $currentDate))
		{
			$productReservation->setDateReserveEnd($updateDate);
		}
	}
}
