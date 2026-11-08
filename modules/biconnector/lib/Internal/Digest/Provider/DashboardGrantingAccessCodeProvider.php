<?php

declare(strict_types=1);

namespace Bitrix\BIConnector\Internal\Digest\Provider;

use Bitrix\BIConnector\Access\Permission\PermissionDictionary;
use Bitrix\BIConnector\Access\Permission\PermissionTable;
use Bitrix\BIConnector\Access\Role\RoleRelationTable;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboardGroupBindingTable;
use Bitrix\Main\Loader;

/**
 * Access codes that let a person through the rules of {@see \Bitrix\BIConnector\Access\AccessController}
 * for a dashboard as a whole: the relations of the roles granting an action on a group of the dashboard
 * and the groups of administrators. Nothing is kept between calls, callers decide what to remember.
 */
class DashboardGrantingAccessCodeProvider
{
	private const ADMIN_GROUP_ID = 1;
	private const INTEGRATOR_GROUP_CODE = 'INTEGRATOR';

	/**
	 * Relation codes of the roles granting the permission on a group of the dashboard.
	 *
	 * @param int $permissionCode {@see PermissionDictionary::BIC_DASHBOARD_VIEW} or
	 *     {@see PermissionDictionary::BIC_DASHBOARD_EDIT}; a role granting every value counts as well.
	 *
	 * @return string[]
	 */
	public function getRoleRelationCodes(int $dashboardId, int $permissionCode): array
	{
		$groupIds = array_column(
			SupersetDashboardGroupBindingTable::query()
				->setSelect(['GROUP_ID'])
				->where('DASHBOARD_ID', $dashboardId)
				->fetchAll()
			,
			'GROUP_ID',
		);
		if ($groupIds === [])
		{
			return [];
		}

		$permissionIds = array_map(
			static fn($groupId): string => PermissionDictionary::getDashboardGroupPermissionId((int)$groupId),
			$groupIds,
		);
		$roleIds = array_column(
			PermissionTable::query()
				->setSelect(['ROLE_ID'])
				->whereIn('PERMISSION_ID', $permissionIds)
				->whereIn('VALUE', [$permissionCode, PermissionDictionary::VALUE_VARIATION_ALL])
				->fetchAll()
			,
			'ROLE_ID',
		);
		if ($roleIds === [])
		{
			return [];
		}

		$relations = RoleRelationTable::query()
			->setSelect(['RELATION'])
			->setDistinct()
			->whereIn('ROLE_ID', array_unique($roleIds))
			->fetchAll()
		;

		return array_values(array_unique(array_column($relations, 'RELATION')));
	}

	/**
	 * Group codes of the people {@see \Bitrix\BIConnector\Access\Model\UserAccessItem::isAdmin()} lets through.
	 *
	 * @return string[]
	 */
	public function getAdminAccessCodes(): array
	{
		$codes = ['G' . self::ADMIN_GROUP_ID];
		if (Loader::includeModule('bitrix24'))
		{
			$integratorGroupId = (int)\CGroup::GetIDByCode(self::INTEGRATOR_GROUP_CODE);
			if ($integratorGroupId > 0)
			{
				$codes[] = 'G' . $integratorGroupId;
			}
		}

		return $codes;
	}
}
