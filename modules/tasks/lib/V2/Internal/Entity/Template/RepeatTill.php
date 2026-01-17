<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Entity\Template;

use Bitrix\Tasks\V2\Internal\Entity\Trait\EnumValuesTrait;

enum RepeatTill: string
{
	use EnumValuesTrait;

	case ENDLESS = 'endless';
	case TIMES = 'times';
	case DATE = 'date';
}
