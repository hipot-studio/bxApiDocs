<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Entity\Task\Reminder;

use Bitrix\Tasks\V2\Internal\Entity\Trait\EnumValuesTrait;

enum RemindBy: string
{
	use EnumValuesTrait;

	case Deadline = 'deadline';
	case Date = 'date';
	case Recurring = 'recurring';
}
