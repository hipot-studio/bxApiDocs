<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Integration\AiAssistant\Service;

use Bitrix\AiAssistant\Exceptions\McpException;
use Bitrix\Intranet\Entity\Collection\DepartmentCollection;
use Bitrix\Intranet\Integration\HumanResources\Department;
use Bitrix\Intranet\Integration\HumanResources\PermissionInvitation;

class DepartmentInvitationAccessService
{
	public function resolveDepartmentCollection(int $userId, ?int $departmentId): DepartmentCollection
	{
		$permission = new PermissionInvitation($userId);

		if ($departmentId !== null)
		{
			$departmentCollection = (new Department())->getByIds([$departmentId]);
			if ($departmentCollection->count() > 1)
			{
				throw new McpException("Department with ID {$departmentId} has multiple entries");
			}

			$department = $departmentCollection->first();
			if ($department === null)
			{
				throw new McpException("Department with ID {$departmentId} was not found.");
			}

			if (!$permission->canInviteToDepartment($department))
			{
				throw new McpException("Current user cannot invite employees to department {$departmentId}.");
			}

			return $departmentCollection;
		}

		$departmentCollection = new DepartmentCollection();
		$defaultDepartment = $permission->findFirstPossibleAvailableDepartment();

		if ($defaultDepartment !== null)
		{
			$departmentCollection->add($defaultDepartment);
		}

		return $departmentCollection;
	}

	public function resolveDepartmentCollectionByIds(int $userId, array $departmentIds): DepartmentCollection
	{
		if (empty($departmentIds))
		{
			return new DepartmentCollection();
		}

		$permission = new PermissionInvitation($userId);
		$departmentCollection = (new Department())->getByIds($departmentIds);

		$foundIds = array_flip(
			$departmentCollection->map(
				static fn($department) => $department->getId(),
			),
		);

		foreach ($departmentIds as $departmentId)
		{
			if (!is_int($departmentId))
			{
				throw new McpException('Each department ID must be an integer.');
			}

			if (!isset($foundIds[$departmentId]))
			{
				throw new McpException("Department with ID {$departmentId} was not found.");
			}
		}

		foreach ($departmentCollection as $department)
		{
			if (!$permission->canInviteToDepartment($department))
			{
				throw new McpException(
					"Current user cannot invite employees to department {$department->getId()}."
				);
			}
		}

		return $departmentCollection;
	}
}
