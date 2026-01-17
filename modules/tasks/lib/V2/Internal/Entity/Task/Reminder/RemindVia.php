<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Entity\Task\Reminder;

use Bitrix\Tasks\V2\Internal\Entity\Trait\EnumValuesTrait;

enum RemindVia: string
{
	use EnumValuesTrait;

	case Notification = 'notification';
	case Email = 'email';
}
