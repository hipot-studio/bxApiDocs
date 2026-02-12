<?php

namespace Bitrix\Crm\Security\Role\UIAdapters\AccessRights\Serializers;

use Bitrix\Crm\Security\Role\Manage\RoleManagementModelBuilder;
use Bitrix\Crm\Security\Role\UIAdapters\AccessRights\PermIdentifier;
use Bitrix\Crm\Security\Role\UIAdapters\AccessRights\Utils\PermCodeTransformer;
use Bitrix\Crm\Security\Role\Utils\RolePermissionLogContext;
use Bitrix\Crm\Service\Container;
use Bitrix\Main\Access\AccessCode;
use Bitrix\Main\Loader;
use Bitrix\Main\SystemException;
use Bitrix\Main\UI\AccessRights\DataProvider;
use Bitrix\UI\AccessRights\V2\Options\UserGroup;

Loader::requireModule('ui');

final class UserGroupSerializer
{
	/**
	 * @param array<array{
	 *     ID: int,
	 *     NAME: string,
	 *     PERMISSIONS: array[],
	 *     RELATIONS: array[],
	 * }> $roles
	 *
	 * @return UserGroup[]
	 */
	public function serialize(array $roles): array
	{
		$allAccessCodes = $this->getAllAccessCodes($roles);
		$allMembers = $this->getAllMembers($allAccessCodes);

		$result = [];
		foreach ($roles as $role)
		{
			$userGroup = new UserGroup(
				(int)$role['ID'],
				(string)$role['NAME'],
			);

			$userGroup
				->setAccessRights($this->serializePermissions($role['PERMISSIONS'] ?? []))
				->setMembers($this->getRoleMembers($role, $allMembers))
			;

			$result[] = $userGroup;
		}

		return $result;
	}

	private function getAllAccessCodes(array $roles): array
	{
		$allAccessCodes = [];
		foreach ($roles as $role)
		{
			if (!empty($role['RELATIONS']))
			{
				$accessCodes = array_column($role['RELATIONS'], 'RELATION');
				$allAccessCodes = [...$allAccessCodes, ...$accessCodes];
			}
		}

		return array_values(array_unique($allAccessCodes));
	}

	/**
	 * @return UserGroup\Member[]
	 * @throws SystemException
	 */
	private function getAllMembers(array $accessCodes): array
	{
		$provider = new DataProvider();

		$members = [];
		foreach ($accessCodes as $code)
		{
			$accessCodeParser = new AccessCode($code);
			$entity = $provider->getEntity($accessCodeParser->getEntityType(), $accessCodeParser->getEntityId());

			$member = UserGroup\Member::tryFromArray($entity->getMetaData());
			if (!$member)
			{
				Container::getInstance()->getLogger('Permissions')->critical(
					'Cant create member for access code: {accessCode} {metadata}',
					RolePermissionLogContext::getInstance()->appendTo([
						'accessCode' => $code,
						'metadata' => $entity->getMetaData(),
					])
				);

				continue;
			}

			$members[$code] = $member;
		}

		return $members;
	}

	/**
	 * @param array $role
	 * @param array<string, UserGroup\Member> $allMembers
	 *
	 * @return array<string, UserGroup\Member>
	 */
	private function getRoleMembers(array $role, array $allMembers): array
	{
		if (empty($role['RELATIONS']))
		{
			return [];
		}

		$members = [];
		foreach ($role['RELATIONS'] as $relation)
		{
			if (isset($relation['RELATION']) && isset($allMembers[$relation['RELATION']]))
			{
				$members[$relation['RELATION']] = $allMembers[$relation['RELATION']];
			}
		}

		return $members;
	}

	/**
	 * @return UserGroup\Member\Access[]
	 */
	private function serializePermissions(array $perms): array
	{
		$accessRights = [];

		foreach ($perms as $perm)
		{
			$permIdentifier = PermIdentifier::fromArray($perm);
			$rightId = PermCodeTransformer::getInstance()->makeAccessRightPermCode($permIdentifier);

			$value = RoleManagementModelBuilder::getInstance()
				->getPermissionByCode($permIdentifier->entityCode, $permIdentifier->permCode)
				?->getControlMapper()
				->getValueForUi($perm['ATTR'] ?? null, $perm['SETTINGS'] ?? null)
				?? $perm['ATTR'] ?? ''
			;

			foreach ((array)$value as $singleValue)
			{
				$accessRights[] = new UserGroup\Member\Access(
					$rightId,
					$singleValue,
				);
			}
		}

		return $accessRights;
	}
}
