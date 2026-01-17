<?php

namespace Bitrix\Crm\Service\UserPermissions;

use Bitrix\Crm\AutomatedSolution\CapabilityAccessChecker;

/**
 * @internal
 * Do not use directly, only through \Bitrix\Crm\Service\Container::getInstance()->getUserPermissions()->dynamicType()
 */

final class DynamicType
{
	public function __construct(
		private readonly AutomatedSolution $automatedSolution,
		private readonly Admin $admin,
		private readonly \Bitrix\Crm\Service\UserPermissions\EntityPermissions\Admin $entityAdmin,
	)
	{
	}

	/**
	 * Can user add a dynamic type (smart-process)
	 * @param int|null $automatedSolutionId
	 * @return bool
	 */
	public function canAdd(?int $automatedSolutionId = null): bool
	{
		if ($automatedSolutionId)
		{
			return
				$this->automatedSolution->isAutomatedSolutionAdmin($automatedSolutionId)
				&& !CapabilityAccessChecker::getInstance()->isLockedAutomatedSolution($automatedSolutionId)
			;
		}

		return $this->admin->isCrmAdmin();
	}

	/**
	 * Can user update a dynamic type (smart-process)
	 * @param int $entityTypeId
	 * @return bool
	 */
	public function canUpdate(int $entityTypeId): bool
	{
		if (\CCrmOwnerType::isDynamicTypeBasedStaticEntity($entityTypeId))
		{
			return false;
		}

		if (CapabilityAccessChecker::getInstance()->isLockedEntityType($entityTypeId))
		{
			return false;
		}

		return $this->entityAdmin->isAdminForEntity($entityTypeId);
	}
}
