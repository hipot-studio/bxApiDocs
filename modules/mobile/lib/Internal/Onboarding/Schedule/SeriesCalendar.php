<?php

namespace Bitrix\Mobile\Internal\Onboarding\Schedule;

use Bitrix\Main\Type\DateTime;

/**
 * Timezone the calendar of the series is read in - the portal one, the same the send
 * window is counted in (`schedule.sendWindow.timezone`, `DateTime::createFromUserTime()`
 * of PushTimeCondition). Reading the day boundary in the server zone instead puts the
 * send day of the series and the due date of a final one day off on every portal whose
 * offset moves the window across server midnight.
 *
 * The zone is the one of the portal (`main/default_time_zone`) and not the one of whoever
 * is authorized: the offset is taken through CTimeZone directly rather than through
 * DateTime::toUserTime(), which resolves the personal zone of the current user. The agent
 * runs without a user and would get the portal zone either way, but the dev harness runs
 * under an administrator, and a preview must show the day the agent will decide on.
 */
class SeriesCalendar
{
	/**
	 * The same moment expressed in the calendar of the series: the date and the weekday of
	 * the result are the portal ones, its timestamp is no longer the moment it came from.
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
}
