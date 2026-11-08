<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Util;

final class AdminRightsGroupMemberships
{
	public static function prepareForRemoval(
		array $memberships,
		int $portalAdminGroupId,
		int $employeesGroupId,
	): array
	{
		$excludedGroupIds = [1, 2, $portalAdminGroupId, $employeesGroupId];
		$result = array_values(array_filter(
			$memberships,
			static fn(array $membership): bool => !in_array(
				(int)($membership['GROUP_ID'] ?? 0),
				$excludedGroupIds,
				true,
			),
		));
		$result[] = ['GROUP_ID' => $employeesGroupId];

		return $result;
	}
}
