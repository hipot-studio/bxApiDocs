<?php

declare(strict_types=1);

namespace Bitrix\Booking\Service;

use Bitrix\Main\Config\Option;

class MultidayBookingFeature
{
	private const MODULE_ID = 'booking';
	private const OPTION_NAME = 'multiday_booking';

	public static function isOn(): bool
	{
		return (bool)Option::get(self::MODULE_ID, self::OPTION_NAME, false);
	}
}
