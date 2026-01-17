<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Entity\Task;

use Bitrix\Tasks\V2\Internal\Entity\Trait\EnumValuesTrait;

enum Status: string
{
	use EnumValuesTrait;

	case Pending = 'pending';
	case InProgress = 'in_progress';
	case SupposedlyCompleted = 'supposedly_completed';
	case Completed = 'completed';
	case Deferred = 'deferred';
	case Declined = 'declined';

	public static function getDefault(): self
	{
		return self::Pending;
	}
}
