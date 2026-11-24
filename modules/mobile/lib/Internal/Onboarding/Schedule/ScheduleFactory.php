<?php

namespace Bitrix\Mobile\Internal\Onboarding\Schedule;

use Bitrix\Mobile\Internal\Onboarding\Region\RegionConfig;
use Bitrix\Mobile\Internal\Service\Marketing\Time\PortalCalendar;
use Bitrix\Mobile\Internal\Service\Marketing\Time\TimeWindow;

/**
 * Workday arithmetic of a region: the calendar rules of the campaign document for a
 * campaign-driven region, the legacy values of the descriptor otherwise.
 */
class ScheduleFactory
{
	public static function forRegion(RegionConfig $regionConfig, ?PortalCalendar $calendar = null): ScheduleInterface
	{
		return new WorkdaySchedule(
			$regionConfig->isWorkdaysOnly(),
			$regionConfig->shouldShiftWeekendToMonday(),
			self::resolveSendWindow($regionConfig),
			self::calendarForRegion($regionConfig, $calendar),
		);
	}

	/**
	 * Calendar the days of a region are read in, or null for the server zone. The portal
	 * calendar goes to a region whose campaign document declares its send window in that same
	 * zone (`schedule.sendWindow.timezone`); the legacy day map has always read its dates in the
	 * server zone, and moving its day boundary would move every day of its series.
	 *
	 * The timezone of the window decides here, not the mere fact of a campaign document: a
	 * `"server"` window is declared on the server clock, and a schedule counting its days on the
	 * portal clock would then decide the day of a push by one clock and whether it may go out
	 * by another - and the two part company on exactly the portals whose offset moves
	 * the window across server midnight.
	 *
	 * Whoever asks the schedule for a date must ask this for the day boundary of the same
	 * region: a dedup counted in one calendar against a schedule counted in another compares
	 * two different days.
	 */
	public static function calendarForRegion(
		RegionConfig $regionConfig,
		?PortalCalendar $calendar = null,
	): ?PortalCalendar
	{
		if (!$regionConfig->isCampaignDriven() || !$regionConfig->isPortalTimezoneWindow())
		{
			return null;
		}

		return $calendar ?? new PortalCalendar();
	}

	/**
	 * The window is handed to the schedule only where it may move a send date - that is
	 * offset 0, the anchor day itself. A legacy day map starts at day 1, so its dates keep
	 * the placement they have always had whatever time of day the anchor falls on.
	 */
	private static function resolveSendWindow(RegionConfig $regionConfig): ?TimeWindow
	{
		if (!$regionConfig->isCampaignDriven())
		{
			return null;
		}

		return TimeWindow::tryFrom($regionConfig->getTimeSend(), $regionConfig->getSendWindowHours());
	}
}
