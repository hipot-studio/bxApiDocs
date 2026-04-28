<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Service\Trigger\Schedule;

enum RecurrWeekday: string
{
	case Monday = 'MO';
	case Tuesday = 'TU';
	case Wednesday = 'WE';
	case Thursday = 'TH';
	case Friday = 'FR';
	case Saturday = 'SA';
	case Sunday = 'SU';

	public static function fromNumericDay(int $day): ?self
	{
		return match ($day)
		{
			1 => self::Monday,
			2 => self::Tuesday,
			3 => self::Wednesday,
			4 => self::Thursday,
			5 => self::Friday,
			6 => self::Saturday,
			7 => self::Sunday,
			default => null,
		};
	}
}
