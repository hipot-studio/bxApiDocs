<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Service\Enum;

enum GridMode: string
{
	case Day = 'day';
	case Week = 'week';
}
