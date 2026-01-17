<?php

namespace Bitrix\Bizproc\Activity\Enum;

enum Joiner: string
{
	case Or = 'OR';
	case And = 'AND';

	public function getInt(): int
	{
		return match ($this)
		{
			self::And => 0,
			self::Or => 1,
		};
	}
}
