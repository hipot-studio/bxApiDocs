<?php

declare(strict_types=1);

namespace Bitrix\HumanResources\Public\Service;

use Bitrix\HumanResources\Access\Permission\PermissionDictionary;
use Bitrix\HumanResources\Internals\Service\Container as InternalContainer;

/**
 * Public access-related API for external modules.
 */
class AccessService
{
	/**
	 * Returns IDs of active users who have the "fire employee" permission
	 * (HUMAN_RESOURCES_FIRE_EMPLOYEE) granted through a role.
	 *
	 * Portal admins are NOT included: their fire access is granted by the
	 * isAdmin() flag (see FireEmployeeRule), not by a role. Use FireEmployeeRule
	 * to check whether a specific user can fire.
	 *
	 * @return int[]
	 */
	public function getUserIdsWithFirePermission(): array
	{
		return InternalContainer::getAccessService()
			->getUserIdsByPermission(
				PermissionDictionary::HUMAN_RESOURCES_FIRE_EMPLOYEE,
				[PermissionDictionary::VALUE_YES],
			)
		;
	}
}
