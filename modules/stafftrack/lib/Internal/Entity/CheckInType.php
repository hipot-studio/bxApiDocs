<?php

namespace Bitrix\StaffTrack\Internal\Entity;

enum CheckInType: int
{
	case MANUAL = 1;
	case AUTOMATIC = 2;
}
