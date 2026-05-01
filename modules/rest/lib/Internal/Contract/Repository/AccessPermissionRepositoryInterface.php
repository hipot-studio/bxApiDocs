<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Contract\Repository;

use Bitrix\Main\Entity\EntityCollection;
use Bitrix\Main\Entity\EntityInterface;
use Bitrix\Rest\Internal\Entity\Access\AccessPermission;
use Bitrix\Rest\Internal\Entity\Access\EntityType;
use Bitrix\Rest\Internal\Entity\Access\PermissionType;
use Bitrix\Main\Repository\RepositoryInterface;

interface AccessPermissionRepositoryInterface
{
	public function save(EntityInterface $entity): void;

	public function deleteByEntityTypeAndPermission(
		EntityType $entityType,
		PermissionType $permission,
	): void;

	/**
	 * @return string[]
	 */
	public function getAccessCodesByEntityTypeAndPermission(
		EntityType $entityType,
		PermissionType $permission,
	): array;

	/**
	 * @param string[] $accessCodes
	 * @return AccessPermission[]
	 */
	public function getByEntityTypeAndAccessCodes(EntityType $entityType, array $accessCodes): EntityCollection;
}
