<?php

declare(strict_types= 1);

namespace Bitrix\Intranet\Internal\Enum;

enum AbsenceTypeCode: string
{
	case Vacation = 'VACATION';
	case Assignment = 'ASSIGNMENT';
	case SickLeave = 'LEAVESICK';
	case MaternityLeave = 'LEAVEMATERINITY';
	case UnpaidLeave = 'LEAVEUNPAYED';
	case Unknown = 'UNKNOWN';
	case Other = 'OTHER';
	case Personal = 'PERSONAL';
}
