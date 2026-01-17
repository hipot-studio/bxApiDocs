<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository\Mapper\Template;

use Bitrix\Tasks\Access\Permission\PermissionDictionary;
use Bitrix\Tasks\V2\Internal\Access\Template\PermissionType;

class PermissionTypeMapper
{
	public function mapToEnum(?int $permissionId): ?PermissionType
	{
		return match ($permissionId)
		{
			PermissionDictionary::TEMPLATE_VIEW => PermissionType::ReadOnly,
			PermissionDictionary::TEMPLATE_FULL => PermissionType::Full,
			default => null,
		};
	}

	public function mapFromEnum(PermissionType $permissionType): ?int
	{
		return match($permissionType->value)
		{
			PermissionType::ReadOnly->value => PermissionDictionary::TEMPLATE_VIEW,
			PermissionType::Full->value => PermissionDictionary::TEMPLATE_FULL,
			default => null,
		};
	}
}
