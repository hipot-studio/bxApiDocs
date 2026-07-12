<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\TaskAnalytics\Enum;

enum ChecklistMovementState: string
{
	case HasMovement = 'has_movement';
	case NoMovement = 'no_movement';
}
