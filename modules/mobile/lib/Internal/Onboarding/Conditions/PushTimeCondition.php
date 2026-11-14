<?php

namespace Bitrix\Mobile\Internal\Onboarding\Conditions;

use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;
use Bitrix\Mobile\Internal\Onboarding\Region\RegionConfig;
use Bitrix\Mobile\Internal\Onboarding\Schedule\SendWindow;
use Bitrix\Mobile\Internal\Onboarding\Schedule\SeriesCalendar;

/**
 * Send window guard. Pure mechanism: window start, length and timezone come from
 * the region descriptor (campaign document `schedule.sendWindow` for a
 * campaign-driven region, legacy region values otherwise). A zero-length window or
 * an unparsable start keeps the window closed.
 *
 * A window declared in the timezone of the portal is read through SeriesCalendar - the same
 * clock the days of the series are counted on. Reading it through the personal zone of
 * whoever is authorized would open the window at another hour than the calendar of the
 * series decides its days in: the hourly agent may run on a hit of any user, and the dev
 * harness runs under an administrator.
 */
class PushTimeCondition implements PushSendCondition
{
	public const ERROR_OUTSIDE_WINDOW = 'Push sending time not reached or window expired';
	public const CODE_OUTSIDE_WINDOW = 'outside_send_window';

	public function __construct(
		private readonly ?int $currentTimestamp = null,
		private readonly ?SeriesCalendar $calendar = null,
	)
	{}

	public function check(RegionConfig $config): Result
	{
		$result = new Result();

		if (!$this->isWithinSendWindow($config))
		{
			$result->addError(new Error(self::ERROR_OUTSIDE_WINDOW, self::CODE_OUTSIDE_WINDOW));
		}

		return $result;
	}

	private function isWithinSendWindow(RegionConfig $config): bool
	{
		$window = SendWindow::tryFrom($config->getTimeSend(), $config->getSendWindowHours());

		return $window?->contains($this->getNow($config)) === true;
	}

	/**
	 * Now on the clock the window is declared on: the portal one where the document says so,
	 * the server one otherwise. SendWindow reads the hour and the minute off whatever moment it
	 * is handed, so putting the moment on the right clock is the whole of this condition's work.
	 */
	private function getNow(RegionConfig $config): DateTime
	{
		$now = $this->currentTimestamp === null
			? new DateTime()
			: DateTime::createFromTimestamp($this->currentTimestamp)
		;

		return $config->isPortalTimezoneWindow()
			? ($this->calendar ?? new SeriesCalendar())->toCalendarTime($now)
			: $now
		;
	}
}
