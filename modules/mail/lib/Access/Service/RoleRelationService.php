<?php

namespace Bitrix\Mail\Access\Service;

use Bitrix\Mail\Access\Role\RoleUtil;
use Bitrix\Main\Access\Exception\RoleRelationSaveException;

class RoleRelationService
{
	/**
	 * @throws RoleRelationSaveException
	 */
	public function saveRoleRelations(array $userGroups): void
	{
		foreach ($userGroups as $group)
		{
			$roleId = (int)$group['id'];
			if (!$roleId)
			{
				continue;
			}

			(new RoleUtil($roleId))->updateRoleRelations($group['accessCodes'] ?? []);
		}
	}
}
