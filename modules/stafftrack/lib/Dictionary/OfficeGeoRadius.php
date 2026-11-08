<?php

namespace Bitrix\StaffTrack\Dictionary;

/**
 * Radiuses of the geo zone of an office, in meters. The set is closed: the form offers exactly these
 * and the domain accepts nothing else.
 */
enum OfficeGeoRadius: int
{
	case METERS_30 = 30;
	case METERS_50 = 50;
	case METERS_100 = 100;
	case METERS_300 = 300;
}
