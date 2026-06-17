<?php

namespace Bitrix\Crm\Security\Role\UIAdapters\AccessRights;


use Bitrix\Crm\Security\Role\Manage\RoleManagementModelBuilder;
use Bitrix\Crm\Security\Role\Manage\RoleSelectionManager;
use Bitrix\Crm\Security\Role\Queries\QueryRoles;
use Bitrix\Crm\Security\Role\Repositories\PermissionRepository;
use Bitrix\Crm\Security\Role\UIAdapters\AccessRights\Serializers\UserGroupSerializer;
use Bitrix\Crm\Security\Role\UIAdapters\AccessRights\Utils\PermCodeTransformer;
use Bitrix\Main\Loader;
use Bitrix\Main\Type\Collection;
use Bitrix\UI\AccessRights\V2\Contract;
use Bitrix\UI\AccessRights\V2\Options\RightSection;
use Bitrix\UI\AccessRights\V2\Options\UserGroup;

Loader::requireModule('ui');

final class UserGroupsProvider
{
	private PermissionRepository $permissionRepository;

	private array $targetAccessRightCodes;
	private bool $excludeRolesWithoutRights = false;

	/**
	 * @param string|null $strictGroupCode
	 * @param RightSection[] $accessRights
	 */
	public function __construct(
		private readonly ?string $strictGroupCode,
		array $accessRights,
	)
	{
		$this->permissionRepository = PermissionRepository::getInstance();

		$this->targetAccessRightCodes = [];
		foreach ($accessRights as $section)
		{
			$ids = array_map(static fn (RightSection\RightItem $item) => $item->getId(), $section->getRightItems());
			array_push($this->targetAccessRightCodes, ...$ids);
		}
	}

	/**
	 * @param RoleSelectionManager $manager
	 * @param RightSection[] $accessRights
	 *
	 * @return self
	 */
	public static function createByManager(RoleSelectionManager $manager, array $accessRights): self
	{
		$self = (new UserGroupsProvider($manager->getGroupCode(), $accessRights));

		if (!$manager->needShowRoleWithoutRights())
		{
			$self->excludeRolesWithoutRights();
		}

		return $self;
	}

	public function excludeRolesWithoutRights(bool $exclude = true): self
	{
		$this->excludeRolesWithoutRights = $exclude;

		return $this;
	}

	/**
	 * @return UserGroup[]
	 */
	public function loadAll(): array
	{
		$roles = $this->prepareQuery()->fetchAll();
		if (empty($roles))
		{
			return [];
		}

		$roleIds = $this->makeRoleIds($roles);

		$perms = $this->filterPermissionsByAccessRightsCodes($this->fetchPerms($roleIds));

		if ($this->excludeRolesWithoutRights)
		{
			$roleIdsToInclude = $this->getIdsOfRolesWithRights($perms);
			$roleIdsToIncludeInverted = array_flip($roleIdsToInclude);
			$roles = array_filter($roles, static fn (array $role): bool => isset($roleIdsToIncludeInverted[(int)$role['ID']]));

			if (empty($roles))
			{
				return [];
			}

			$roleIds = $this->makeRoleIds($roles);
		}

		$relations = $this->fetchRelations($roleIds);

		return $this->serialize($roles, $perms, $relations);
	}

	/**
	 * @return int[]
	 */
	public function loadAllIds(): array
	{
		$roleIds = $this->prepareQuery()->fetchAllIds();
		if (empty($roleIds))
		{
			return [];
		}

		if (!$this->excludeRolesWithoutRights)
		{
			return $roleIds;
		}

		$perms = $this->filterPermissionsByAccessRightsCodes($this->fetchPerms($roleIds));
		$roleIdsToInclude = $this->getIdsOfRolesWithRights($perms);

		return array_intersect($roleIds, $roleIdsToInclude);
	}

	private function prepareQuery(): QueryRoles
	{
		$query = new QueryRoles();

		$query->filterByGroup($this->strictGroupCode);

		if ($this->excludeRolesWithoutRights)
		{
			foreach ($this->makeEntityAndPermTypeFilter() as $entity => $permTypes)
			{
				$query->addNotEmptyEntityPermsFilter($entity, $permTypes);
			}
		}

		return $query;
	}

	private function makeEntityAndPermTypeFilter(): array
	{
		$entityToPermTypes = [];
		foreach ($this->targetAccessRightCodes as $rightCode)
		{
			$identifier = PermCodeTransformer::getInstance()->decodeAccessRightCode($rightCode);

			$entityToPermTypes[$identifier->entityCode][$identifier->permCode] = $identifier->permCode;
		}

		return array_map(
			array_values(...),
			$entityToPermTypes
		);
	}

	private function makeRoleIds(array $roles): array
	{
		$roleIds = array_column($roles, 'ID');
		Collection::normalizeArrayValuesByInt($roleIds);

		return $roleIds;
	}

	private function fetchPerms(array $roleIds): array
	{
		return $this->permissionRepository->queryActualPermsByRoleIds($roleIds);
	}

	private function fetchRelations(array $roleIds): array
	{
		return $this->permissionRepository->queryRolesRelations($roleIds);
	}

	protected function filterPermissionsByAccessRightsCodes(array $permissions): array
	{
		$result = [];

		foreach ($permissions as $permission)
		{
			$identifier = PermIdentifier::fromArray($permission);
			$code = PermCodeTransformer::getInstance()->makeAccessRightPermCode($identifier);

			if (in_array($code, $this->targetAccessRightCodes, true))
			{
				$result[] = $permission;
			}
		}

		return $result;
	}

	private function getIdsOfRolesWithRights(array $permissions): array
	{
		$roleIds = [];

		foreach ($permissions as $permission)
		{
			$roleId = (int)$permission['ROLE_ID'];
			if (isset($roleIds[$roleId]))
			{
				continue;
			}

			$identifier = PermIdentifier::fromArray($permission);
			$permissionEntity = RoleManagementModelBuilder::getInstance()->getPermissionByCode(
				$identifier->entityCode,
				$identifier->permCode,
			);

			if ($permissionEntity === null)
			{
				continue;
			}

			$isAllows = \Bitrix\Crm\Security\Role\Utils\RolePermissionChecker::isPermissionAllowsAnything(
				\Bitrix\Crm\Security\Role\Manage\DTO\PermissionModel::createFromDbArray($permission)
			);

			if ($isAllows)
			{
				$roleIds[$roleId] = $roleId;
			}
		}

		return array_values($roleIds);
	}

	private function serialize(array $roles, array $perms, array $relations): array
	{
		$roleIdToRoleMap = [];
		foreach ($roles as $role)
		{
			$roleIdToRoleMap[$role['ID']] = $role;
		}

		foreach ($perms as $perm)
		{
			$roleId = (int)$perm['ROLE_ID'];
			if (!isset($roleIdToRoleMap[$roleId]))
			{
				continue;
			}

			$roleIdToRoleMap[$roleId]['PERMISSIONS'][] = $perm;
		}

		foreach ($relations as $relation)
		{
			$roleId = (int)$relation['ROLE_ID'];
			if (!isset($roleIdToRoleMap[$roleId]))
			{
				continue;
			}

			$roleIdToRoleMap[$roleId]['RELATIONS'][] = $relation;
		}

		return (new UserGroupSerializer())->serialize(array_values($roleIdToRoleMap));
	}
}
