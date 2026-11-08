<?php

namespace Bitrix\StaffTrack\Dictionary;

enum EmployeeOfficeCardStatus: string
{
	case AVAILABLE = 'available';
	case NO_OFFICE = 'no_office';
	case UNAVAILABLE = 'unavailable';
}
