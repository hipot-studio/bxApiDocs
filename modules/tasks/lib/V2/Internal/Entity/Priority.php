<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Entity;

use Bitrix\Tasks\V2\Internal\Entity\Trait\EnumValuesTrait;

enum Priority: string
{
	use EnumValuesTrait;

	case Low = 'low';
	case Average = 'average';
	case High = 'high';
}
