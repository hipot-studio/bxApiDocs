<?php

declare(strict_types=1);

namespace Bitrix\Tasks\Replication\Template\Enum;

/**
 * Type of days used for the deadline creation offset (REPLICATE_PARAMS.OFFSET_DAY_TYPE).
 *
 * Calendar counts every day; Workday counts only working days per the portal calendar.
 */
enum OffsetDayType: string
{
	case Calendar = 'calendar';
	case Workday = 'workday';

	public static function fromValue(?string $value): self
	{
		if ($value === null || $value === '')
		{
			return self::Calendar;
		}

		return self::tryFrom($value) ?? self::Calendar;
	}

	/**
	 * Day-kind derived from the unified "работать по рабочим дням" flag (REPLICATE_PARAMS.WORKDAY_ONLY).
	 * The standalone OFFSET_DAY_TYPE control was dropped; day-kind now follows the single toggle.
	 */
	public static function fromWorkdayFlag(?string $workdayOnly): self
	{
		return $workdayOnly === 'Y' ? self::Workday : self::Calendar;
	}
}
