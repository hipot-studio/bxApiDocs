<?php

namespace Bitrix\Crm\Service\UserPermissions;

use Bitrix\Crm\AutomatedSolution\CapabilityAccessChecker;
use Bitrix\Crm\Security\Role\PermissionsManager;
use Bitrix\Crm\Service\UserPermissions;
use Bitrix\Main\ArgumentOutOfRangeException;

/**
 * @internal
 * Do not use directly, only through \Bitrix\Crm\Service\Container::getInstance()->getUserPermissions()->automatedSolution()
 */

class AutomatedSolution
{
	private ?CapabilityAccessChecker $capabilityAccessChecker = null;

	public function __construct(
		private readonly PermissionsManager $permissionsManager,
		private readonly Admin $admin
	)
	{
	}

	/**
	 * Can user create, update or delete automated solutions and manage their permissions
	 * @return bool
	 */
	public function isAllAutomatedSolutionsAdmin(): bool
	{
		if ($this->admin->isAdmin())
		{
			return true;
		}

		return $this->permissionsManager->hasPermissionLevel(
			\Bitrix\Crm\Security\Role\Manage\Entity\AutomatedSolutionList::ENTITY_CODE,
			'CONFIG',
			UserPermissions::PERMISSION_ALL
		);
	}

	/**
	 * Can edit smart processes and permissions in $automatedSolutionId automated solution
	 * @param int $automatedSolutionId
	 * @return bool
	 */
	public function isAutomatedSolutionAdmin(int $automatedSolutionId): bool
	{
		if ($this->isAllAutomatedSolutionsAdmin())
		{
			return true;
		}

		if ($this->canEdit($automatedSolutionId))
		{
			return true;
		}

		return $this->permissionsManager->hasPermissionLevel(
			\Bitrix\Crm\Security\Role\Manage\Entity\AutomatedSolutionConfig::generateEntity($automatedSolutionId),
			UserPermissions::OPERATION_UPDATE,
			UserPermissions::PERMISSION_CONFIG
		);
	}

	/**
	 * Can user create, update or delete automated solutions
	 *
	 * @param int|null $automatedSolutionId
	 * @return bool
	 */
	public function canEdit(?int $automatedSolutionId = null): bool
	{
		if ($automatedSolutionId !== null && $this->isLocked($automatedSolutionId))
		{
			return false;
		}

		if ($this->admin->isAdmin())
		{
			return true;
		}

		if ($this->isAllAutomatedSolutionsAdmin())
		{
			return true;
		}

		return $this->permissionsManager->hasPermissionLevel(
			\Bitrix\Crm\Security\Role\Manage\Entity\AutomatedSolutionList::ENTITY_CODE,
			UserPermissions::OPERATION_UPDATE,
			UserPermissions::PERMISSION_ALL
		);
	}

	private function isLocked(int $automatedSolutionId): bool
	{
		if ($this->capabilityAccessChecker === null)
		{
			$this->capabilityAccessChecker = CapabilityAccessChecker::getInstance();
		}

		return $this->capabilityAccessChecker->isLockedAutomatedSolution($automatedSolutionId);
	}
}
