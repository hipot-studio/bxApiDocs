<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Entity\Analytics;

enum Type: string
{
	case Task = 'task';
	case AutoTimeTracking = 'auto';
	case ManualTimeTracking = 'manual';
}
