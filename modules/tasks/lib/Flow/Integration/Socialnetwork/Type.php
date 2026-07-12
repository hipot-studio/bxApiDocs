<?php

declare(strict_types=1);

namespace Bitrix\Tasks\Flow\Integration\Socialnetwork;

use Bitrix\Main\Loader;
use Bitrix\Socialnetwork\Item\Workgroup;

class Type
{
	public static function isAllowed(?string $value): bool
	{
		if (!Loader::includeModule('socialnetwork'))
		{
			return false;
		}

		// If the type is not specified, then it is allowed
		if ($value === null)
		{
			return true;
		}

		$type = Workgroup\Type::tryFrom($value);

		return in_array($type, self::getAllowedTypes(), true);
	}

	private static function getAllowedTypes(): array
	{
		return [
			Workgroup\Type::Group,
			Workgroup\Type::Project,
			Workgroup\Type::Scrum,
			Workgroup\Type::Collab,
		];
	}
}
