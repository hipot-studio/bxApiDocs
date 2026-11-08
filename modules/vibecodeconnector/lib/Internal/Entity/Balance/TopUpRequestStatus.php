<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Entity\Balance;

enum TopUpRequestStatus: string
{
	case Active = 'active';
	case Closed = 'closed';

	public static function values(): array
	{
		return array_column(self::cases(), 'value');
	}
}
