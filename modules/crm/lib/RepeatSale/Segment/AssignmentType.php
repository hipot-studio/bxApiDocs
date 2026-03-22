<?php

namespace Bitrix\Crm\RepeatSale\Segment;

enum AssignmentType: int
{
	case byUser = 1;
	case byClient = 2;
	case byClientLastDeal = 3;

	public static function values(): array
	{
		return array_map(static fn(self $case) => $case->value, self::cases());
	}
}
