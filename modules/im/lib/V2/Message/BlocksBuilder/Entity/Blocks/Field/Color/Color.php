<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\BlocksBuilder\Entity\Blocks\Field\Color;

enum Color: string
{
	case Primary = 'primary';
	case Secondary = 'secondary';
	case Alert = 'alert';
	case Base = 'base';
	case Tertiary = 'tertiary';
	case Success = 'success';
	case AiAssistant = 'ai-assistant';

	public static function checkForIcon(string $value): bool
	{
		$color = self::tryFrom($value);

		return $color !== null && $color !== self::AiAssistant;
	}
}
