<?php

declare(strict_types=1);

namespace Bitrix\HumanResources\Internals\Service\Structure;

use Bitrix\HumanResources\Access\StructureAccessController;
use Bitrix\HumanResources\Access\StructureActionDictionary;
use Bitrix\HumanResources\Config\Storage;
use Bitrix\HumanResources\Enum\Access\RoleCategory;
use Bitrix\HumanResources\Service\Container;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\UserAccessTable;
use Bitrix\Main\UserTable;

final class AccessService
{
	public function checkAccessToEditPermissions(
		RoleCategory $category,
		?int $userId = null,
		bool $checkTariffRestriction = true,
	): bool
	{
		if ($checkTariffRestriction && !Storage::canUsePermissionConfig())
		{
			return false;
		}

		return
			$this->isSupportedRoleCategory($category)
			&& $this->checkAccessToEditRoleCategory($category, $userId)
		;
	}

	/**
	 * Returns IDs of active users who, through a role, have the given permission set to
	 * any of the given values. Admins are NOT included (their access comes from the
	 * isAdmin() flag, not a role).
	 *
	 * The caller must pass the set of values that count as "granted":
	 * - toggler permissions (e.g. FIRE_EMPLOYEE): [PermissionDictionary::VALUE_YES];
	 * - variables permissions: the full set of non-NONE values.
	 *
	 * Note: for variables permissions this answers "has the permission at capability
	 * level" (the role grants it), NOT "may act on a specific target user" — scope and
	 * subtree are not evaluated here.
	 *
	 * @param string $permissionId
	 * @param int[] $values values that count as "permission granted"
	 * @return int[]
	 */
	public function getUserIdsByPermission(string $permissionId, array $values): array
	{
		$roleIds = Container::getAccessPermissionRepository()->getRoleIdsByPermissionValues($permissionId, $values);
		if (empty($roleIds))
		{
			return [];
		}

		$relationCodes = Container::getAccessRoleRelationRepository()->getRelationCodesByRoleIds($roleIds);
		if (empty($relationCodes))
		{
			return [];
		}

		$userIds = [];
		foreach (
			UserAccessTable::query()
				->addSelect('USER_ID')
				->whereIn('ACCESS_CODE', $relationCodes)
				->fetchAll() as $row
		)
		{
			$userIds[(int)$row['USER_ID']] = (int)$row['USER_ID'];
		}

		if (empty($userIds))
		{
			return [];
		}

		$activeUserIds = [];
		foreach (
			UserTable::query()
				->addSelect('ID')
				->whereIn('ID', array_values($userIds))
				->where('ACTIVE', 'Y')
				->fetchAll() as $row
		)
		{
			$activeUserIds[] = (int)$row['ID'];
		}

		return $activeUserIds;
	}

	private function isSupportedRoleCategory(RoleCategory $category): bool
	{
		return in_array($category, [
			RoleCategory::Department,
			RoleCategory::Team,
		], true);
	}

	private function checkAccessToEditRoleCategory(RoleCategory $roleCategory, ?int $userId = null): bool
	{
		$structureAction = $roleCategory === RoleCategory::Department
			? StructureActionDictionary::ACTION_USERS_ACCESS_EDIT
			: StructureActionDictionary::ACTION_TEAM_ACCESS_EDIT
		;

		return StructureAccessController::can(
			$userId ?? (int)CurrentUser::get()->getId(),
			$structureAction,
		);
	}
}