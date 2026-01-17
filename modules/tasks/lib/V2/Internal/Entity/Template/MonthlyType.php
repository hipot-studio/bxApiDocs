<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Entity\Template;

enum MonthlyType: int
{
	case MonthDay = 1;
	case WeekDay = 2;
}
