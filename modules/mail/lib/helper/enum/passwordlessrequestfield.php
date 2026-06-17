<?php

declare(strict_types=1);

namespace Bitrix\Mail\Helper\Enum;

enum PasswordlessRequestField: string
{
	case Employee = 'EMPLOYEE';
	case Email = 'EMAIL';
	case Status = 'STATUS';
	case DateSent = 'DATE_SENT';
}
