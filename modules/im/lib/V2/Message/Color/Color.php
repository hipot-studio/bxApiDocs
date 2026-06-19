<?php

namespace Bitrix\Im\V2\Message\Color;

enum Color: string
{
	case PRIMARY = 'primary';
	case SECONDARY = 'secondary';
	case ALERT = 'alert';
	case BASE = 'base';
	case AI_ASSISTANT = 'ai-assistant';

	public static function validateColor($color): string
	{
		if (isset($color) && self::tryFrom($color) !== null)
		{
			return $color;
		}

		return self::BASE->value;
	}
}
