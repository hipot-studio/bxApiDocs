<?php

namespace Bitrix\Crm\Security\Role\Manage;

use Bitrix\Crm\Restriction\RestrictionManager;
use Bitrix\Crm\Security\Role\Manage\DTO\EntityDTO;
use Bitrix\Crm\Security\Role\Manage\DTO\PermissionModel;
use Bitrix\Crm\Security\Role\Manage\DTO\Restrictions;
use Bitrix\Crm\Security\Role\Manage\DTO\RoleDTO;
use Bitrix\Crm\Security\Role\Model\RolePermissionTable;
use Bitrix\Crm\Traits\Singleton;
use Bitrix\Main\Application;
use Bitrix\Main\Error;
use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Main\Result;
use CCrmRole;

class PermissionRepository
{
	use Singleton;

	public function getRole(int $roleId): ?RoleDTO
	{
		$obRes = CCrmRole::GetList(array(), array('ID' => $roleId));
		$roleRow = $obRes->Fetch();

		if ($roleRow)
		{
			return RoleDTO::createFromDbRow($roleRow);
		}

		return null;
	}

	public function getRoleAssignedPermissions(int $roleId)
	{
		$ct = new ConditionTree();
		$ct->logic(ConditionTree::LOGIC_OR);
		$ct->where('ATTR', '<>', '');
		$ct->where('FIELD_VALUE', '<>', '');
		$ct->where('SETTINGS', '<>', '');


		$query = RolePermissionTable::query()
			->setSelect(['ENTITY', 'PERM_TYPE', 'FIELD', 'FIELD_VALUE', 'ATTR', 'SETTINGS'])
			->where('ROLE_ID', $roleId)
			->where($ct)
		;

		return $query->fetchAll();
	}

	/**
	 * @param EntityDTO[] $permissionEntities
	 * @return array
	 */
	public function getDefaultRoleAssignedPermissions(array $permissionEntities): array
	{
		$result = [];

		foreach ($permissionEntities as $entity)
		{
			foreach ($entity->permissions() as $permission)
			{
				$attr = $permission->getDefaultAttribute();
				$settings = $permission->getDefaultSettings();
				if ($attr === null && empty($settings))
				{
					continue;
				}

				$result[] = [
					'ENTITY' => $entity->code(),
					'PERM_TYPE' => $permission->code(),
					'FIELD' => '-',
					'FIELD_VALUE' => null,
					'ATTR' => $attr,
					'SETTINGS' => $settings,
				];
			}
		}

		return $result;
	}

	public function getTariffRestrictions(): Restrictions
	{
		$restriction = RestrictionManager::getPermissionControlRestriction();

		$hasPermission = $restriction->hasPermission();

		return new Restrictions(
			$hasPermission,
			$hasPermission ? null : $restriction->prepareInfoHelperScript(),
		);
	}

	/**
	 * @param int $roleId
	 * @param PermissionModel[] $removedPerms
	 * @param PermissionModel[] $changedPerms
	 * @return Result
	 */
	public function applyRolePermissionData(int $roleId, array $removedPerms, array $changedPerms): Result
	{
		$result = new Result();
		$connection = Application::getConnection();

		$connection->startTransaction();

		try {
			RolePermissionTable::removePermissions($roleId, $removedPerms);
			RolePermissionTable::appendPermissions($roleId, $changedPerms);

			$connection->commitTransaction();
		}
		catch (\Exception $e)
		{
			$connection->rollbackTransaction();
			$result->addError(new Error($e->getMessage()));
		}

		return $result;
	}
}