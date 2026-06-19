<?php

namespace Bitrix\StaffTrack\Internal\Dictionary;

enum AddressStatus: int
{
	case PENDING = 0;
	case RESOLVED = 1;
	case FAILED = 2;
}
