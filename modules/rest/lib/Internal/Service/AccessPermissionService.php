<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Service;

use Bitrix\Rest\Internal\Entity\Access\AccessPermission;
use Bitrix\Rest\Internal\Entity\Access\EntityType;
use Bitrix\Rest\Internal\Entity\Access\PermissionType;
use Bitrix\Rest\Internal\Contract\Repository\AccessPermissionRepositoryInterface;
use Bitrix\Rest\Internal\Repository\AccessPermissionRepository;

class AccessPermissionService
{
	public function __construct(private AccessPermissionRepositoryInterface $repository = new AccessPermissionRepository())
	{
	}

	/**
	 * @param string[] $accessCodes
	 */
	public function setAccessCodes(
		EntityType $entityType,
		PermissionType $permission,
		array $accessCodes,
	): void
	{
		$this->repository->deleteByEntityTypeAndPermission($entityType, $permission);

		foreach ($accessCodes as $accessCode)
		{
			$this->repository->save(new AccessPermission($entityType, $accessCode, $permission));
		}
	}

	/**
	 * @return string[]
	 */
	public function getAccessCodes(
		EntityType $entityType,
		PermissionType $permission,
	): array
	{
		return $this->repository->getAccessCodesByEntityTypeAndPermission($entityType, $permission);
	}

	/**
	 * @param string[] $userAccessCodes
	 * @return PermissionType[]
	 */
	public function getUserPermissions(EntityType $entityType, array $userAccessCodes): array
	{
		if (empty($userAccessCodes))
		{
			return [];
		}

		$accessPermissions = $this->repository->getByEntityTypeAndAccessCodes($entityType, $userAccessCodes);

		$permissions = [];
		foreach ($accessPermissions as $accessPermission)
		{
			$permissions[$accessPermission->getPermission()->value] = $accessPermission->getPermission();
		}

		return array_values($permissions);
	}
}
