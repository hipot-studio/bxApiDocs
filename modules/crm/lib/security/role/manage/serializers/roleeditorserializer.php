<?php

namespace Bitrix\Crm\Security\Role\Manage\Serializers;

use Bitrix\Crm\Security\Role\Manage\DTO\Restrictions;
use Bitrix\Crm\Security\Role\Manage\EntitiesBuilder;
use Bitrix\Crm\Security\Role\Manage\Permissions\Permission;
use Bitrix\Crm\Security\Role\Manage\RoleData;

class RoleEditorSerializer
{
	public function serialize(RoleData $roleData): array
	{
		return [
			'role' => [
				'id' => $roleData->role()->id(),
				'name' => $roleData->role()->name(),
			],
			'availablePermissions' => $this->availablePermissions(),
			'permissionEntities' => $this->permissionEntities($roleData),
			'roleAssignedPermissions' => $this->roleAssignedPermissions($roleData),
			'restriction' => $this->prepareRestriction($roleData->restriction()),
		];
	}

	private function permissionEntities(RoleData $roleData): array
	{
		$result = [];
		foreach ($roleData->entities() as $entity)
		{
			$permissions = [];
			foreach ($entity->permissions() as $permission)
			{
				$permissions[$permission->code()] = $permission->variants();
			}

			$hasStages = !empty($entity->fields());
			$result[] = [
				'entityCode' => $entity->code(),
				'name' => $entity->name(),
				'hasStages' => $hasStages,
				'permissions' => $permissions,
			];

			if (!$hasStages)
			{
				continue;
			}

			foreach ($entity->fields() as $fieldCode => $values)
			{
				foreach ($values as $valueCode => $valueName)
				{
					$result[] = [
						'entityCode' => $entity->code(),
						'stageField' => $fieldCode,
						'stageCode' => $valueCode,
						'name' => $valueName,
						'permissions' => $permissions
					];
				}
			}
		}

		return $result;
	}

	private function availablePermissions(): array
	{
		$result = [];
		foreach ($this->getAllAvailablePermissions() as $permission)
		{
			$result[] = [
				'code' => $permission->code(),
				'name' => $permission->name(),
				'sortOrder' => $permission->sortOrder(),
				'canAssignPermissionToStages' => $permission->canAssignPermissionToStages(),
			];
		}

		return $result;
	}

	/**
	 * @return Permission[]
	 */
	private function getAllAvailablePermissions(): array
	{
		return EntitiesBuilder::allPermissions();
	}

	private function roleAssignedPermissions(RoleData $roleData): array
	{
		$result = [];

		foreach ($roleData->userAssigned() as $item)
		{

			$entityCode = $item['ENTITY'];
			$permType = $item['PERM_TYPE'];
			$field = $item['FIELD'] ?? null;
			$fieldValue = $item['FIELD_VALUE'] ?? null;
			$attr = $item['ATTR'] ?? '';

			if (!isset($result[$entityCode]))
			{
				$result[$entityCode] = [];
			}

			if (!isset($result[$entityCode][$permType]))
			{
				$result[$entityCode][$permType] = [];
			}

			if ($field === '-')
			{
				$result[$entityCode][$permType]['-'] = $attr;

				continue;
			}

			if (!isset($result[$entityCode][$permType][$field]))
			{
				$result[$entityCode][$permType][$field] = [];
			}

			$result[$entityCode][$permType][$field][$fieldValue] = $attr;
		}


		return $result;
	}

	private function prepareRestriction(Restrictions $restriction): array
	{
		return [
			'hasPermission' => $restriction->hasPermission(),
			'restrictionScript' => $restriction->restrictionScript()
		];
	}
}