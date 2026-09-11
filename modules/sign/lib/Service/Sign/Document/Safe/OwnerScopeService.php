<?php

namespace Bitrix\Sign\Service\Sign\Document\Safe;

use Bitrix\Crm\Service\UserPermissions;
use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Sign\Access\Model\UserModel;
use Bitrix\Sign\Access\Service\RolePermissionService;
use Bitrix\Sign\Service\Container;
use Bitrix\Sign\Type\Document\InitiatedByType;
use Bitrix\Sign\Type\Member\EntityType;

final class OwnerScopeService
{
	private readonly RolePermissionService $rolePermissionService;
	private array $ownerIdsCache = [];

	public function __construct(?RolePermissionService $rolePermissionService = null)
	{
		$this->rolePermissionService = $rolePermissionService ?? Container::instance()->getRolePermissionService();
	}

	/**
	 * `null` means unrestricted access; an empty list means access is denied.
	 *
	 * @return list<int>|null
	 */
	public function resolveOwnerIds(UserModel $user, int $permissionId): ?array
	{
		if ($user->isAdmin())
		{
			return null;
		}

		$roles = $user->getRoles();
		sort($roles, SORT_NUMERIC);
		$cacheKey = $user->getUserId() . ':' . implode(',', $roles) . ':' . $permissionId;
		if (array_key_exists($cacheKey, $this->ownerIdsCache))
		{
			return $this->ownerIdsCache[$cacheKey];
		}

		$value = $this->rolePermissionService->getValueForPermission($roles, (string)$permissionId);

		$this->ownerIdsCache[$cacheKey] = match ($value)
		{
			UserPermissions::PERMISSION_ALL => null,
			UserPermissions::PERMISSION_SUBDEPARTMENT => $user->getUserDepartmentMembers(true),
			UserPermissions::PERMISSION_DEPARTMENT => $user->getUserDepartmentMembers(),
			UserPermissions::PERMISSION_SELF => [$user->getUserId()],
			default => [],
		};

		return $this->ownerIdsCache[$cacheKey];
	}

	public function canAccessOwner(UserModel $user, int $permissionId, int $ownerId): bool
	{
		$ownerIds = $this->resolveOwnerIds($user, $permissionId);

		return $ownerIds === null || in_array($ownerId, $ownerIds, true);
	}

	public function buildDocumentCondition(
		UserModel $user,
		int $permissionId,
		string $createdByColumn,
		string $initiatedByColumn,
		string $representativeColumn,
		string $entityTypeColumn,
	): ?ConditionTree
	{
		$ownerIds = $this->resolveOwnerIds($user, $permissionId);
		if ($ownerIds === null)
		{
			return null;
		}
		if ($ownerIds === [])
		{
			return Query::filter()->where($createdByColumn, null);
		}

		return Query::filter()
			->logic(ConditionTree::LOGIC_OR)
			->where(
				Query::filter()
					->where($initiatedByColumn, InitiatedByType::EMPLOYEE->toInt())
					->whereIn($representativeColumn, $ownerIds)
					->where($entityTypeColumn, EntityType::COMPANY)
			)
			->whereIn($createdByColumn, $ownerIds)
		;
	}
}
