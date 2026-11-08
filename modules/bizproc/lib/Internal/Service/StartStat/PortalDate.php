<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Service\StartStat;

use Bitrix\Main\Type\Date;
use Bitrix\Main\Type\DateTime;
use CTimeZone;

/**
 * The day a moment belongs to as the portal sees it. The counter of successful manual starts, the
 * reader of the popularity window and the cleanup agent all take their day from here, so a bucket
 * never depends on who happened to open the page: the day of a process is a property of the portal,
 * not of the viewer, and CTimeZone::GetOffset() would mix the zone of the current user into it.
 *
 * The offset is read once per call and applied to every moment of a calculation alike - a start and
 * the control moment it is compared with. Two moments told apart by a daylight saving transition
 * therefore keep the distance in days they have on the calendar, and a window stays as wide as it was
 * configured.
 */
final class PortalDate
{
	public static function of(DateTime $moment): Date
	{
		$shifted = $moment->getTimestamp() + self::getPortalOffset();

		return new Date(date('Y-m-d', $shifted), 'Y-m-d');
	}

	private static function getPortalOffset(): int
	{
		if (!CTimeZone::OptionEnabled())
		{
			// time zones are off: the server time is the portal time
			return 0;
		}

		// an empty zone means main:default_time_zone, and the server zone while that one is unset
		return CTimeZone::getTimezoneOffset('');
	}
}
