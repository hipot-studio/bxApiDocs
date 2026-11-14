<?php

declare(strict_types=1);

namespace Bitrix\StaffTrack\Public\Provider\Presence;

/**
 * The group of the work day of an employee on the date of the list: the tabs of the date take his row by it, see
 * {@see PresenceTab::holds()}. The two working groups are divided by the check-ins of the date, with or without
 * coordinates.
 */
enum PresenceGroup: string
{
	case WorkingNoCheckIns = 'working_no_checkins';
	case WorkingWithCheckIns = 'working_with_checkins';
	case Finished = 'finished';
	case NotStarted = 'not_started';
}
