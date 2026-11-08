<?php

namespace Bitrix\StaffTrack\Dictionary;

/**
 * A missing option is a state of its own - the employee has never answered the popup - and it is
 * never stored, so it has no case here.
 */
enum CheckInGeoPermissionState: string
{
	case GRANTED = 'granted';
	case POSTPONED = 'postponed';
}
