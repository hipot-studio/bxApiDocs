<?php

namespace Bitrix\Crm\Service\UserPermissions;

use Bitrix\Crm\AutomatedSolution\CapabilityAccessChecker;

trait AutomatedSolutionEntityLockedTrait
{
	private ?CapabilityAccessChecker $capabilityAccessChecker = null;

	private function isAutomatedSolutionEntityLocked(int $entityTypeId): bool
	{
		if ($this->capabilityAccessChecker === null)
		{
			$this->capabilityAccessChecker = CapabilityAccessChecker::getInstance();
		}

		return $this->capabilityAccessChecker->isLockedEntityType($entityTypeId);
	}
}
