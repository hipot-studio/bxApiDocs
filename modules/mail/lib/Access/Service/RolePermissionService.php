<?php

namespace Bitrix\Mail\Access\Service;

use Bitrix\Mail\Access\Permission\PermissionDictionary;
use Bitrix\Mail\Access\Repository\PermissionRepository;
use Bitrix\Mail\Access\Repository\RoleRelationRepository;
use Bitrix\Mail\Access\Repository\RoleRepository;
use Bitrix\Mail\Access\Role\RoleDictionary;
use Bitrix\Mail\Access\SectionDictionary;
use Bitrix\Main\Access\AccessCode;
use Bitrix\Main\UI\AccessRights\DataProvider;

class RolePermissionService
{
	private RoleRepository $roleRepository;
	private PermissionRepository $permissionRepository;
	private RoleRelationRepository $roleRelationRepository;

	public function __construct()
	{
		$this->roleRepository = new RoleRepository();
		$this->permissionRepository = new PermissionRepository();
		$this->roleRelationRepository = new RoleRelationRepository();
	}

	/**
	 * @param $userGroups array<array{
	 *    id: string,
	 *    title: string,
	 *    accessRights: array<array{
	 *      id: string,
	 *      value: string
	 *    }>,
	 *    accessCodes?: array<string, string>
	 *  }>
	 */
	public function saveRolePermissions(array $userGroups): array
	{
		$updatedUserGroups = [];
		$roleIds = [];
		$permissionsToSave = [];

		$availablePermissionIds = $this->getAvailablePermissionIds();

		foreach ($userGroups as $group)
		{
			$roleId = (int)$group['id'];
			$roleTitle = (string)$group['title'];

			$roleId = $this->saveRole($roleTitle, $roleId);
			if (!$roleId)
			{
				continue;
			}

			$updatedGroup = $group;
			$updatedGroup['id'] = $roleId;
			$updatedUserGroups[] = $updatedGroup;

			$roleIds[] = $roleId;

			if (isset($group['accessRights']))
			{
				foreach ($group['accessRights'] as $right)
				{
					if (!in_array($right['id'], $availablePermissionIds, true))
					{
						continue;
					}

					$permissionsToSave[] = [
						'ROLE_ID' => $roleId,
						'PERMISSION_ID' => $right['id'],
						'VALUE' => (int)$right['value'],
					];
				}
			}
		}

		if (!empty($roleIds) && !empty($availablePermissionIds))
		{
			$this->permissionRepository->deleteByRoleIdsAndPermissionIds($roleIds, $availablePermissionIds);
		}

		if (!empty($permissionsToSave))
		{
			$this->permissionRepository->addMultiple($permissionsToSave);
		}

		return $updatedUserGroups;
	}

	public function saveRole(string $name, int $roleId = 0): int
	{
		if ($roleId > 0)
		{
			$this->roleRepository->updateTitle($roleId, $name);

			return $roleId;
		}

		return $this->roleRepository->create($name);
	}

	public function deleteRoles(array $roleIds): void
	{
		$roleIds = array_filter($roleIds, 'is_numeric');
		$roleIds = array_map('intval', $roleIds);

		if (empty($roleIds))
		{
			return;
		}

		$this->permissionRepository->deleteByRoleIds($roleIds);
		$this->roleRelationRepository->deleteRelationsForRoles($roleIds);
		$this->roleRepository->deleteByIds($roleIds);
	}

	/**
	 * @return array<array{
	 *     sectionTitle: string,
	 *     rights: array<array{
	 *         id: string,
	 *         type: string,
	 *         title: string,
	 *         hint: string,
	 *         variables: array<array{
	 *             id: int,
	 *             title: string
	 *         }>
	 *     }>,
	 *     sectionCode: string
	 * }>
	 */
	public function getAccessRights(): array
	{
		$sections = SectionDictionary::getMap();

		$res = [];
		foreach ($sections as $sectionId => $permissionIds)
		{
			$rights = [];
			foreach ($permissionIds as $permissionId)
			{
				$permissionType = PermissionDictionary::getType($permissionId);
				$right = [
					'id' => $permissionId,
					'type' => $permissionType,
					'title' => PermissionDictionary::getTitle($permissionId),
					'hint' => PermissionDictionary::getHint($permissionId),
					'variables' => PermissionDictionary::getVariables($permissionId),
				];

				$minValue = PermissionDictionary::getMinValueByTypeOrNull($permissionType);
				if ($minValue !== null)
				{
					$right['minValue'] = $minValue;
					$right['emptyValue'] = $minValue;
				}

				$maxValue = PermissionDictionary::getMaxValueByTypeOrNull($permissionType);
				if ($maxValue !== null)
				{
					$right['maxValue'] = $maxValue;
				}

				$rights[] = $right;
			}

			$section = [
				'sectionTitle' => SectionDictionary::getTitle($sectionId),
				'rights' => $rights,
				'sectionCode' => "code.$sectionId",
			];

			$res[] = $section;
		}

		return $res;
	}

	/**
	 * @return array<array{
	 *     id: int,
	 *     title: string,
	 *     accessRights: array<
	 *         array{id: string, value: int}
	 *     >,
	 *     members: array<
	 *         string,
	 *	       array{
	 *             type: string,
	 *             id: int,
	 *             name: string,
	 *             url: string,
	 *             avatar: string,
	 *         }
	 *	   >
	 *	 }>
	 *
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\ObjectPropertyException
	 * @throws \Bitrix\Main\SystemException
	 */
	public function getUserGroups(): array
	{
		$userGroups = [];
		$rolesData = $this->roleRepository->getRoleList();

		foreach ($rolesData as $roleData)
		{
			$roleId = (int)$roleData['ID'];

			$userGroups[] = [
				'id' => $roleId,
				'title' => RoleDictionary::getRoleName($roleData['NAME']),
				'accessRights' => $this->getRoleAccessRights($roleId),
				'members' => $this->getRoleMembers($roleId),
			];
		}

		return $userGroups;
	}

	/**
	 * @return array<array{id: string, value: int}>
	 *
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\ObjectPropertyException
	 * @throws \Bitrix\Main\SystemException
	 */
	private function getRoleAccessRights(int $roleId): array
	{
		$accessRights = [];
		$rolePermissions = $this->permissionRepository->getPermissionsForRole($roleId);

		foreach ($rolePermissions as $id => $value)
		{
			$accessRights[] = [
				'id' => $id,
				'value' => (int)$value,
			];
		}

		return $accessRights;
	}

	/**
	 * @return array{
	 *          string,
	 *           array{
	 *              type: string,
	 *              id: int,
	 *              name: string,
	 *              url: string,
	 *              avatar: string,
	 *          }
	 *     }
	 * @return array
	 * @throws \Bitrix\Main\UI\AccessRights\Exception\UnknownEntityTypeException
	 */
	private function getRoleMembers(int $roleId): array
	{
		$members = [];
		$relations = $this->roleRelationRepository->getRelationsByRoleId($roleId);
		$provider = new DataProvider();

		foreach ($relations as $relation)
		{
			$accessCode = $relation['RELATION'];
			$accessCodeObject = new AccessCode($accessCode);
			$entity = $provider->getEntity($accessCodeObject->getEntityType(), $accessCodeObject->getEntityId());
			$members[$accessCode] = $entity->getMetaData();
		}

		return $members;
	}

	/**
	 * @return array<string>
	 */
	private function getAvailablePermissionIds(): array
	{
		$permissionIds = [];

		foreach (SectionDictionary::getMap() as $sectionPermissions)
		{
			foreach ($sectionPermissions as $permissionId)
			{
				$permissionIds[] = $permissionId;
			}
		}

		return $permissionIds;
	}
}
