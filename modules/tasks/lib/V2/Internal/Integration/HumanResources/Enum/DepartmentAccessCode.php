<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\HumanResources\Enum;

use Bitrix\Main\Access\AccessCode;

enum DepartmentAccessCode: string
{
	case Legacy = 'D';
	case LegacyRecursive = 'DR';
	case Structure = 'SND';
	case StructureRecursive = 'SNDR';

	public static function getId(?string $accessCode): ?int
	{
		if ($accessCode === null)
		{
			return null;
		}

		$parsedAccessCode = new AccessCode($accessCode);
		$departmentId = $parsedAccessCode->getEntityId();

		if ($departmentId <= 0 || self::tryFrom($parsedAccessCode->getEntityPrefix()) === null)
		{
			return null;
		}

		return $departmentId;
	}
}
