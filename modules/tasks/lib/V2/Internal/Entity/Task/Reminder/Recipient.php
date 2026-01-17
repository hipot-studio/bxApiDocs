<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Entity\Task\Reminder;

use Bitrix\Tasks\V2\Internal\Entity\Trait\EnumValuesTrait;

enum Recipient: string
{
	use EnumValuesTrait;

	case Responsible = 'responsible';
	case Creator = 'creator';
	case Accomplice = 'accomplice';
	case Myself = 'myself';
}
