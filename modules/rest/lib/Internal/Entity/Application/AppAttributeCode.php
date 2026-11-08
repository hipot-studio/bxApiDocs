<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Entity\Application;

enum AppAttributeCode: string
{
	case OwnerUserId = 'OWNER_USER_ID';
	case InstalledByUserId = 'INSTALLED_USER_ID';

	public static function getValues(): array
	{
		$values = [];

		foreach (self::cases() as $case)
		{
			$values[] = $case->value;
		}

		return $values;
	}
}
