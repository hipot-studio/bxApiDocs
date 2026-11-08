<?php

namespace Bitrix\StaffTrack\Dictionary;

enum OfficePresence: string
{
	case PRESENT = 'present';
	case ABSENT = 'absent';
	case UNKNOWN = 'unknown';
}
