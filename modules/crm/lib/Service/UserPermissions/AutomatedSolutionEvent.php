<?php

namespace Bitrix\Crm\Service\UserPermissions;

use Bitrix\Crm\Security\EntityPermission\ApproveCustomPermsToExistRole;
use Bitrix\Crm\Security\Role\Manage\Permissions\Event\Read;
use Bitrix\Crm\Security\Role\PermissionsManager;
use Bitrix\Crm\Service\UserPermissions;

/**
 * @internal
 * Do not use directly, only through \Bitrix\Crm\Service\Container::getInstance()->getUserPermissions()->automatedSolutionEvent()
 */

class AutomatedSolutionEvent
{
	public function __construct(
		private readonly AutomatedSolution $automatedSolution,
		private readonly PermissionsManager $permissionsManager,
	)
	{
	}

	/**
	 * Can user read automated solutions events
	 *
	 * @param int $automatedSolutionId
	 * @return bool
	 */
	public function canRead(int $automatedSolutionId): bool
	{
		if ((new ApproveCustomPermsToExistRole())->hasWaitingPermission(new Read()))
		{
			return true;
		}

		if($this->automatedSolution->isAutomatedSolutionAdmin($automatedSolutionId))
		{
			return true;
		}

		return $this->permissionsManager->hasPermission(
			\Bitrix\Crm\Security\Role\Manage\Entity\AutomatedSolutionEvent::generateEntity($automatedSolutionId),
			UserPermissions::OPERATION_READ
		);
	}
}
