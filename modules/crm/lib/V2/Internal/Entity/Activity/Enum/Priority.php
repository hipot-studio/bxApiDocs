<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Entity\Activity\Enum;

/**
 * Grade of the priority of an activity. The importance flag of a write is the highest grade of this scale.
 */
enum Priority: string
{
	case None = 'none';
	case Low = 'low';
	case Medium = 'medium';
	case High = 'high';
}
