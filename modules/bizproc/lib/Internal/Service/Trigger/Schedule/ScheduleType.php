<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Service\Trigger\Schedule;

enum ScheduleType: string
{
	case Once = 'once';
	case Hourly = 'hourly';
	case Daily = 'daily';
	case Weekly = 'weekly';
	case Monthly = 'monthly';
	case Yearly = 'yearly';
}
