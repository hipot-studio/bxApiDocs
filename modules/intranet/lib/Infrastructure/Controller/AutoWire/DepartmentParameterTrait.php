<?php

namespace Bitrix\Intranet\Infrastructure\Controller\AutoWire;

use Bitrix\Intranet\Entity\Collection\DepartmentCollection;
use Bitrix\Main\Engine\AutoWire\ExactParameter;
use Bitrix\Intranet\Integration;

trait DepartmentParameterTrait
{
	protected function createDepartmentCollectionParameter(): ExactParameter
	{
		return new ExactParameter(
			DepartmentCollection::class,
			'departmentCollection',
			function($className, ?array $departmentIds = null) {
				$permission = Integration\HumanResources\PermissionInvitation::createByCurrentUser();
				if (!$departmentIds)
				{
					$departmentCollection = new DepartmentCollection();
					if($department = $permission->findFirstPossibleAvailableDepartment())
					{
						$departmentCollection->add($department);
					}

					return $departmentCollection;
				}
				$departmentCollection = (new Integration\HumanResources\Department())->getByIds($departmentIds);

				return $departmentCollection->filter(function ($department) use ($permission) {
					return $permission->canInviteToDepartment($department);
				});
			},
		);
	}
}