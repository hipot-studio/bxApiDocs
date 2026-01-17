<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Entity\Template\Access;

enum AccessEntityType: string
{
	case Group = 'group';
	case User = 'user';
	case Department = 'department';
	case AllUsers = 'meta-user';

	public static function getUserTypes(): array
	{
		return [self::User];
	}

	public static function getGroupTypes(): array
	{
		return [self::Group];
	}
}
