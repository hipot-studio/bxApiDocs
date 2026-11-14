<?php

namespace Bitrix\Mobile\Internal\Onboarding\Schedule;

use Bitrix\Main\Type\DateTime;

interface ScheduleInterface
{
	public function calculateSendDate(DateTime $anchorDate, int $offsetDays): DateTime;

	/**
	 * The date $sendDays send days after the given one, which is already a calendar date
	 * of the series: no timezone conversion and no placement of the anchor day happen
	 * again, so the result of one calculation may be walked further.
	 */
	public function addSendDays(DateTime $date, int $sendDays): DateTime;

	public function isValidSendDay(DateTime $date): bool;
}
