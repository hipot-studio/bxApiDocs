<?php

namespace Bitrix\Crm\Reservation\Tools;

use Bitrix\Main\Type\DateTime;

class DateTimeComparator
{
	/**
	 * Use to compare two dates of the product reservation deadline, taking into account the end of the working day.
	 *
	 * @param DateTime|null $date1
	 * @param DateTime|null $date2
	 * @return bool
	 */
	public static function areEqual(?DateTime $date1, ?DateTime $date2): bool
	{
		if ($date1 === null && $date2 === null)
		{
			return true;
		}

		if ($date1 === null || $date2 === null)
		{
			return false;
		}

		return $date1->getTimestamp() === $date2->getTimestamp();
	}

	/**
	 * Use to compare two dates of the product reservation deadline as date.
	 *
	 * @param DateTime|null $date1
	 * @param DateTime|null $date2
	 * @return bool
	 */
	public static function areEqualByDateOnly(?DateTime $date1, ?DateTime $date2): bool
	{
		if ($date1 === null && $date2 === null)
		{
			return true;
		}

		if ($date1 === null || $date2 === null)
		{
			return false;
		}

		return $date1->format('Y-m-d') === $date2->format('Y-m-d');
	}
}
