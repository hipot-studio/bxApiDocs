<?php

namespace Bitrix\StaffTrack\Internal\Entity;

/**
 * The two events of a stay, spelled the way the client writes them into the description of a point.
 * The values are released and arrive from the mobile application, so they cannot be changed here alone.
 *
 * Three services of Public\Services still keep a private copy of the same pair - DailyStatsUpdater,
 * CheckInMarkerAggregator and PointAcceptanceService. They are left alone on purpose, being released
 * surfaces of the paths of acceptance; the next change of substance in any of them brings its copy here.
 */
enum StayEvent: string
{
	case ENTER = 'ENTER';
	case LEAVE = 'LEAVE';

	/**
	 * @return array<int, string>
	 */
	public static function values(): array
	{
		return array_column(self::cases(), 'value');
	}
}
