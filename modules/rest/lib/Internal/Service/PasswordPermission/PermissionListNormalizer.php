<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Service\PasswordPermission;

final class PermissionListNormalizer
{
	public function normalize(array $permissionList): array
	{
		$result = [];

		foreach ($permissionList as $value)
		{
			if (!is_string($value))
			{
				continue;
			}

			$permission = trim($value);
			if ($permission === '')
			{
				continue;
			}

			$key = mb_strtolower($permission);
			if (!isset($result[$key]))
			{
				$result[$key] = $permission;
			}
		}

		return array_values($result);
	}
}
