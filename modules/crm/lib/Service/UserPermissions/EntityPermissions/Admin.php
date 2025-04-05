<?php

namespace Bitrix\Crm\Service\UserPermissions\EntityPermissions;

use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\UserPermissions\AutomatedSolution;

/**
 * @internal
 * Do not use directly, only through \Bitrix\Crm\Service\Container::getInstance()->getUserPermissions()->entityAdmin()
 */

class Admin
{
	public function __construct(
		private readonly \Bitrix\Crm\Service\UserPermissions\Admin $admin,
		private readonly AutomatedSolution $automatedSolution,
	)
	{
	}

	/**
	 * Is user an admin of entity
	 *
	 * @param int $entityTypeId
	 * @return bool
	 */
	public function isAdminForEntity(int $entityTypeId): bool
	{
		if ($this->admin->isAdmin())
		{
			return true;
		}
		if (\CCrmOwnerType::isPossibleDynamicTypeId($entityTypeId))
		{
			$automatedSolutionId = Container::getInstance()->getTypeByEntityTypeId($entityTypeId)?->getCustomSectionId();
			if ($automatedSolutionId)
			{
				return $this->automatedSolution->isAutomatedSolutionAdmin($automatedSolutionId);
			}
		}

		return $this->admin->isCrmAdmin();
	}
}
