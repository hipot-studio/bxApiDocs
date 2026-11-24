<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Internal\Service\Marketing\Time;

use Bitrix\Main\Type\DateTime;

/**
 * The calendar conditions are read in: days, weekdays and the clock of the portal zone
 * (`main/default_time_zone`), the zone the send window of the series is counted in. Reading the day
 * boundary in the server zone instead puts the day of a condition one day off on every portal whose
 * offset moves it across server midnight.
 *
 * The zone is the one of the portal and not the one of whoever is authorized: the offset is taken
 * through CTimeZone directly rather than through DateTime::toUserTime(), which resolves the personal
 * zone of the current user. The agent runs without a user and would get the portal zone either way,
 * but a dev page runs under an administrator, and a preview must show the day the agent will decide on.
 */
class PortalCalendar
{
	/**
	 * The same moment expressed in the portal calendar: the date, the weekday and the clock of the
	 * result are the portal ones, its timestamp is no longer the moment it came from.
	 */
	public function toCalendarTime(DateTime $moment): DateTime
	{
		$moment = (clone $moment)->setDefaultTimeZone();
		$offset = \CTimeZone::getTimezoneOffset('', $moment);

		return $offset === 0
			? $moment
			: $moment->add(($offset < 0 ? '-' : '') . 'PT' . abs($offset) . 'S')
		;
	}

	public function day(DateTime $moment): string
	{
		return $this->toCalendarTime($moment)->format('Y-m-d');
	}

	/**
	 * ISO-8601 day of the week: 1 for Monday through 7 for Sunday.
	 */
	public function weekday(DateTime $moment): int
	{
		return (int)$this->toCalendarTime($moment)->format('N');
	}
}
