<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Public\Dto\Project;

enum ProjectRole: string
{
	case Owner = 'A';
	case Moderator = 'E';
	case Member = 'K';

	/**
	 * @return string[]
	 */
	public static function memberValues(): array
	{
		return [
			self::Owner->value,
			self::Moderator->value,
			self::Member->value,
		];
	}
}
