<?php

namespace Bitrix\HumanResources\Service\Access;

use Bitrix\HumanResources\Access\Enum\PermissionValueType;
use Bitrix\HumanResources\Access\Permission\Mapper\TeamPermissionMapper;
use Bitrix\HumanResources\Access\Permission\PermissionDictionary;
use Bitrix\HumanResources\Access\Permission\PermissionVariablesDictionary;
use Bitrix\HumanResources\Access\Permission\RolePermissionValidator;
use Bitrix\HumanResources\Access\Role\RoleDictionary;
use Bitrix\HumanResources\Access\SectionDictionary;
use Bitrix\HumanResources\Exception\WrongStructureItemException;
use Bitrix\HumanResources\Item;
use Bitrix\HumanResources\Item\Collection\Access\PermissionCollection;
use Bitrix\HumanResources\Model\Access\AccessPermissionTable;
use Bitrix\HumanResources\Model\Access\AccessRoleRelationTable;
use Bitrix\HumanResources\Model\Access\AccessRoleTable;
use Bitrix\HumanResources\Repository\Access\PermissionRepository;
use Bitrix\HumanResources\Repository\Access\RoleRepository;
use Bitrix\HumanResources\Service\Container;
use Bitrix\Main\Access\AccessCode;
use Bitrix\Main\Access\Permission\PermissionDictionary as PermissionDictionaryAlias;
use Bitrix\Main\Application;
use Bitrix\Main\DB\Connection;
use Bitrix\Main\DB\SqlQueryException;
use Bitrix\Main\Text\Encoding;
use Bitrix\Main\UI\AccessRights\DataProvider;

class RolePermissionService
{
	private const DB_ERROR_KEY = "HUMAN_RESOURCES_CONFIG_PERMISSIONS_DB_ERROR";
	private const INVALID_SETTINGS_ERROR = 'Invalid role permission settings format.';

	private ?RoleRelationService $roleRelationService;
	private ?PermissionRepository $permissionRepository;
	private ?RoleRepository $roleRepository;
	private RolePermissionValidator $rolePermissionValidator;
	private Connection $connection;
	private \Bitrix\HumanResources\Enum\Access\RoleCategory $category;

	public function __construct(
		?RoleRelationService $roleRelationService = null,
		?PermissionRepository $permissionRepository = null,
		?RoleRepository $roleRepository = null,
		?RolePermissionValidator $rolePermissionValidator = null,
		?Connection $connection = null,
	)
	{
		$this->roleRelationService = $roleRelationService ?? Container::getAccessRoleRelationService();
		$this->permissionRepository = $permissionRepository ?? Container::getAccessPermissionRepository();
		$this->roleRepository =  $roleRepository ?? Container::getAccessRoleRepository();
		$this->rolePermissionValidator = $rolePermissionValidator ?? new RolePermissionValidator();
		$this->connection = $connection ?? Application::getConnection();
		$this->category = \Bitrix\HumanResources\Enum\Access\RoleCategory::Department;
	}

	/**
	 * @param array<array{
	 *     id: int|string,
	 *     title: string,
	 *     type: string,
	 *     accessRights: array<array{id: string, value: string}> }> $permissionSettings
	 *
	 * @return void
	 * @throws SqlQueryException|WrongStructureItemException|\DomainException
	 */
	public function saveRolePermissions(array &$permissionSettings): void
	{
		$this->assertPermissionSettingsShape($permissionSettings);

		$existingRoleIds = array_values(array_filter(
			array_map(
				static fn(array $setting): int => (int)$setting['id'],
				$permissionSettings,
			),
			static fn(int $roleId): bool => $roleId > 0,
		));
		$rolesById = $this->getRolesById($existingRoleIds);
		$normalizedRightsBySetting = [];

		foreach ($permissionSettings as $index => $setting)
		{
			$roleId = (int)$setting['id'];
			if ($roleId > 0)
			{
				$this->resolveRoleNameForUpdate((string)$setting['title'], $roleId, $rolesById);
			}
			else
			{
				$this->assertRoleNameIsNotReserved((string)$setting['title']);
			}

			$normalizedRightsBySetting[$index] = $this->validateRights($setting['accessRights'] ?? []);
		}

		$this->connection->startTransaction();

		try
		{
			$roleIds = [];
			$permissionCollection = new PermissionCollection();
			foreach ($permissionSettings as $index => &$setting)
			{
				$roleId = (int)$setting['id'];
				$roleTitle = (string)$setting['title'];

				$roleId = $this->saveRoleWithRolesById($roleTitle, $roleId, $rolesById);
				if (!$roleId)
				{
					throw new SqlQueryException(self::DB_ERROR_KEY);
				}

				$setting['id'] = $roleId;
				$roleIds[] = $roleId;

				$this->appendPermissions(
					$permissionCollection,
					$roleId,
					$normalizedRightsBySetting[$index],
				);
			}
			unset($setting);

			$this->permissionRepository->deleteByRoleIds($roleIds);
			if (!$permissionCollection->empty())
			{
				$this->permissionRepository->createByCollection($permissionCollection);
			}

			$this->connection->commitTransaction();
		}
		catch (\Throwable $exception)
		{
			$this->connection->rollbackTransaction();

			throw $exception;
		}

		$this->cleanCaches();
	}

	/**
	 * @throws \DomainException
	 */
	private function assertPermissionSettingsShape(array $permissionSettings): void
	{
		foreach ($permissionSettings as $setting)
		{
			if (!is_array($setting) || !isset($setting['id'], $setting['title']))
			{
				throw new \DomainException(self::INVALID_SETTINGS_ERROR);
			}

			if (!is_scalar($setting['id']) || !is_scalar($setting['title']))
			{
				throw new \DomainException(self::INVALID_SETTINGS_ERROR);
			}

			$this->assertAccessRightsShape($setting['accessRights'] ?? null);
		}

		$this->roleRelationService->assertSettingsShape($permissionSettings);
	}

	/**
	 * @throws \DomainException
	 */
	private function assertAccessRightsShape(mixed $accessRights): void
	{
		if ($accessRights === null)
		{
			return;
		}

		if (!is_array($accessRights))
		{
			throw new \DomainException(self::INVALID_SETTINGS_ERROR);
		}

		foreach ($accessRights as $right)
		{
			if (!is_array($right) || !is_scalar($right['id'] ?? null))
			{
				throw new \DomainException(self::INVALID_SETTINGS_ERROR);
			}
		}
	}

	/**
	 * @param array<int> $roleIds
	 */
	public function deleteRoles(array $roleIds): void
	{
		$rolesById = $this->getRolesById();

		$roleIds = array_values(array_filter(
			array_map('intval', $roleIds),
			static fn(int $roleId): bool => isset($rolesById[$roleId]),
		));

		if (empty($roleIds))
		{
			return;
		}

		$this->assertRolesCanBeDeleted($roleIds, $rolesById);

		$this->connection->startTransaction();
		try
		{
			$this->permissionRepository->deleteByRoleIds($roleIds);
			$this->roleRelationService->deleteRelationsByRoleIds($roleIds);
			$this->roleRepository->deleteByIds($roleIds);
			$this->connection->commitTransaction();
		}
		catch (\Throwable $exception)
		{
			$this->connection->rollbackTransaction();

			throw $exception;
		}

		$this->cleanCaches();
	}

	/**
	 * @param array<int> $roleIds
	 */
	public function validateRolesCanBeDeleted(array $roleIds): void
	{
		$roleIds = array_values(array_unique(array_filter(
			array_map('intval', $roleIds),
			static fn(int $roleId): bool => $roleId > 0,
		)));
		if (empty($roleIds))
		{
			return;
		}

		$this->assertRolesCanBeDeleted($roleIds, $this->getRolesById($roleIds));
	}

	/**
	 * @param array<int> $roleIds
	 * @param array<int, array{ID: int|string, NAME: string, CATEGORY?: string}> $rolesById
	 */
	private function assertRolesCanBeDeleted(array $roleIds, array $rolesById): void
	{
		foreach ($roleIds as $roleId)
		{
			if (isset($rolesById[$roleId]) && $this->isPredefinedRole((string)$rolesById[$roleId]['NAME']))
			{
				throw new \DomainException('Predefined roles cannot be deleted.');
			}
		}
	}

	public function deleteRole(int $roleId): void
	{
		$this->deleteRoles([$roleId]);
	}

	/**
	 * @param string $name
	 * @param int $roleId
	 *
	 * @return int
	 * @throws \DomainException
	 * @throws SqlQueryException
	 */
	public function saveRole(string $name, int $roleId = 0): int
	{
		$roleId = $this->saveRoleWithRolesById($name, $roleId);
		$this->cleanCaches();

		return $roleId;
	}

	/**
	 * @param array<int, array{ID: int|string, NAME: string, CATEGORY?: string}>|null $rolesById
	 */
	private function saveRoleWithRolesById(string $name, int $roleId = 0, ?array $rolesById = null): int
	{
		$name = Encoding::convertEncodingToCurrent($name);
		if ($roleId > 0)
		{
			$rolesById ??= $this->getRolesById();
			$name = $this->resolveRoleNameForUpdate($name, $roleId, $rolesById);
			if ($name === (string)$rolesById[$roleId]['NAME'])
			{
				return $roleId;
			}

			$result = $this->roleRepository->updateName($roleId, $name);
			if (!$result->isSuccess())
			{
				throw new SqlQueryException(self::DB_ERROR_KEY);
			}

			return $roleId;
		}

		$this->assertRoleNameIsNotReserved($name);
		$result = $this->roleRepository->create($name, $this->category);
		if (!$result->isSuccess())
		{
			throw new SqlQueryException(self::DB_ERROR_KEY);
		}

		return (int)$result->getId();
	}

	public function getRoleList(): array
	{
		return $this->roleRepository->getRoleList(
			$this->category,
		);
	}

	public function getUserGroups(): array
	{
		$res = $this->getRoleList();
		$accessRightsByRoleId = $this->getRoleAccessRightsMap(array_map(
			static fn(array $role): int => (int)$role['ID'],
			$res,
		));
		$roles = [];
		foreach ($res as $row)
		{
			$roles[] = [
				'id' => (int)$row['ID'],
				'title' => RoleDictionary::getRoleName($row['NAME']),
				'accessRights' => $accessRightsByRoleId[(int)$row['ID']] ?? [],
				'members' => $this->getRoleMembers((int)$row['ID']),
			];
		}

		return $roles;
	}

	public function getRoleAccessRights(int $roleId): array
	{
		return $this->getRoleAccessRightsMap([$roleId])[$roleId] ?? [];
	}

	public function getCanonicalRoleAccessRights(int $roleId): array
	{
		return $this->getCanonicalRoleAccessRightsMap([$roleId])[$roleId] ?? [];
	}

	/**
	 * @param array<int> $roleIds
	 * @return array<int, list<array{id: string, value: int}>>
	 */
	public function getRoleAccessRightsMap(array $roleIds): array
	{
		$settings = $this->getSettings($roleIds);

		$accessRightsByRoleId = [];
		foreach ($settings as $roleId => $roleSettings)
		{
			$accessRights = [];
			foreach ($roleSettings as $permissionId => $permissionValue)
			{
				$defaultPermissionId = explode('_', $permissionId, 2)[0];
				if (PermissionDictionary::isTeamDependentVariablesPermission($defaultPermissionId))
				{
					$teamAccessRights = TeamPermissionMapper::transformPermissionToAccessRights($permissionId, $permissionValue);
					$accessRights = array_merge($accessRights, $teamAccessRights);

					continue;
				}

				$accessRights[] = [
					'id' => (string)$permissionId,
					'value' => $permissionValue,
				];
			}
			$accessRightsByRoleId[$roleId] = $accessRights;
		}

		return $accessRightsByRoleId;
	}

	/**
	 * @param array<int> $roleIds
	 * @return array<int, list<array{id: string, value: int}>>
	 */
	public function getCanonicalRoleAccessRightsMap(array $roleIds): array
	{
		$settings = $this->getSettings($roleIds);
		$accessRightsByRoleId = [];

		foreach ($settings as $roleId => $roleSettings)
		{
			$accessRights = [];
			$dependentRights = [];

			foreach ($roleSettings as $permissionId => $permissionValue)
			{
				$defaultPermissionId = explode('_', $permissionId, 2)[0];
				if (!PermissionDictionary::isTeamDependentVariablesPermission($defaultPermissionId))
				{
					$accessRights[] = [
						'id' => (string)$permissionId,
						'value' => $permissionValue,
					];

					continue;
				}

				$valueType = TeamPermissionMapper::getTeamValueTypeByPermissionId($permissionId);
				if ($permissionValue === PermissionVariablesDictionary::VARIABLE_NONE)
				{
					continue;
				}

				$dependentRights[$defaultPermissionId][$valueType->value] = $permissionValue;
			}

			foreach ($dependentRights as $permissionId => $values)
			{
				foreach ([PermissionValueType::TeamValue, PermissionValueType::DepartmentValue] as $valueType)
				{
					if (!array_key_exists($valueType->value, $values))
					{
						continue;
					}

					$accessRights[] = [
						'id' => (string)$permissionId,
						'value' => $values[$valueType->value],
					];
				}
			}

			$accessRightsByRoleId[$roleId] = $accessRights;
		}

		return $accessRightsByRoleId;
	}

	public function getAccessRights(): array
	{
		$sections = SectionDictionary::getMap($this->category);

		$res = [];

		foreach ($sections as $sectionId => $permissions)
		{
			$rights = [];
			foreach ($permissions as $permissionId)
			{
				$permissionType = PermissionDictionary::getType($permissionId);
				$right = [
					'id' => $permissionId,
					'type' => $permissionType,
					'title' => PermissionDictionary::getTitle($permissionId),
					'hint' => PermissionDictionary::getHint($permissionId),
					'variables' => $permissionType !== PermissionDictionaryAlias::TYPE_TOGGLER
						? PermissionDictionary::getVariables($permissionId)
						: []
					,
				];
				$minValue = PermissionDictionary::getMinValueByTypeOrNull($permissionType);
				$maxValue = PermissionDictionary::getMaxValueByTypeOrNull($permissionType);
				$right += PermissionVariablesDictionary::getTeamPermissionSelectedVariablesAliases();
				if ($minValue !== null)
				{
					$right['minValue'] = $minValue;
					$right['emptyValue'] = $minValue;
				}
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
			$sectionIcon = SectionDictionary::getIcon($sectionId);
			if ($sectionIcon)
			{
				$section['sectionIcon'] = $sectionIcon;
			}

			$res[] = $section;
		}

		return $res;
	}

	public function getRoleById(int $roleId): ?array
	{
		return $this->roleRepository->getRoleById($roleId, $this->category);
	}

	private function getMemberInfo(string $code): array
	{
		$accessCode = new AccessCode($code);
		$member = (new DataProvider())->getEntity($accessCode->getEntityType(), $accessCode->getEntityId());

		return $member->getMetaData();
	}

	private function getRoleMembers(int $roleId): array
	{
		$members = [];

		$relations = $this
			->roleRelationService
			->getRelationList(["filter" =>["=ROLE_ID" => $roleId]])
		;

		foreach ($relations as $row)
		{
			$accessCode = $row['RELATION'];
			$members[$accessCode] = $this->getMemberInfo($accessCode);
		}

		return $members;
	}

	private function getSettings(array $roleIds): array
	{
		$settings = [];
		$permissionCollection = $this->permissionRepository->getPermissionListByRoleIds($roleIds);

		foreach ($permissionCollection as $permission)
		{
			$settings[$permission->roleId][$permission->permissionId] = $permission->value;
		}

		return $settings;
	}

	/**
	 * @param list<array{id: string, value: mixed}> $rights
	 * @return list<array{id: string, value: int}>
	 */
	private function validateRights(array $rights): array
	{
		$normalizedRights = array_map(
			static fn(array $right): array => [
				'id' => (string)($right['id'] ?? ''),
				'value' => is_numeric($right['value'] ?? null) ? (int)$right['value'] : $right['value'] ?? null,
			],
			$rights,
		);

		return $this->rolePermissionValidator->validate($this->category, $normalizedRights);
	}

	/**
	 * @param list<array{id: string, value: int}> $rights
	 */
	private function appendPermissions(
		PermissionCollection $permissionCollection,
		int $roleId,
		array $rights,
	): void
	{
		$teamPermissions = [];
		foreach ($rights as $permission)
		{
			if (PermissionDictionary::isTeamDependentVariablesPermission($permission['id']))
			{
				$teamPermissions[$permission['id']][] = $permission;

				continue;
			}

			$permissionCollection->add(new Item\Access\Permission(
				roleId: $roleId,
				permissionId: $permission['id'],
				value: $permission['value'],
			));
		}

		foreach ($teamPermissions as $permissionValues)
		{
			$teamPermissionMapper = TeamPermissionMapper::createFromArray($permissionValues);
			$permissionCollection->add(new Item\Access\Permission(
				roleId: $roleId,
				permissionId: $teamPermissionMapper->getTeamPermissionId(),
				value: $teamPermissionMapper->getTeamPermissionValue(),
			));
			$permissionCollection->add(new Item\Access\Permission(
				roleId: $roleId,
				permissionId: $teamPermissionMapper->getDepartmentPermissionId(),
				value: $teamPermissionMapper->getDepartmentPermissionValue(),
			));
		}
	}

	private function cleanCaches(): void
	{
		AccessPermissionTable::cleanCache();
		AccessRoleRelationTable::cleanCache();
		AccessRoleTable::cleanCache();

		if (\Bitrix\Main\Loader::includeModule('intranet'))
		{
			\CIntranetUtils::clearMenuCache();
		}
	}

	public function setCategory(\Bitrix\HumanResources\Enum\Access\RoleCategory $category): static
	{
		$this->category = $category;

		return $this;
	}

	/**
	 * @return array<int, array{ID: int|string, NAME: string, CATEGORY?: string}>
	 */
	private function getRolesById(?array $roleIds = null): array
	{
		if ($roleIds === [])
		{
			return [];
		}

		$rolesById = [];
		$roles = $roleIds === null
			? $this->roleRepository->getRoleList($this->category)
			: $this->roleRepository->getRolesByIds($roleIds, $this->category);
		foreach ($roles as $role)
		{
			$rolesById[(int)$role['ID']] = $role;
		}

		return $rolesById;
	}

	/**
	 * @param array<int, array{ID: int|string, NAME: string, CATEGORY?: string}> $rolesById
	 */
	private function resolveRoleNameForUpdate(string $name, int $roleId, array $rolesById): string
	{
		if (!isset($rolesById[$roleId]))
		{
			throw new \DomainException('Role does not belong to the selected category.');
		}

		$name = Encoding::convertEncodingToCurrent($name);
		$currentName = (string)$rolesById[$roleId]['NAME'];
		if (!$this->isPredefinedRole($currentName))
		{
			$this->assertRoleNameIsNotReserved($name);

			return $name;
		}

		if ($name !== $currentName && $name !== RoleDictionary::getRoleName($currentName))
		{
			throw new \DomainException('Predefined roles cannot be renamed.');
		}

		return $currentName;
	}

	private function assertRoleNameIsNotReserved(string $name): void
	{
		$name = Encoding::convertEncodingToCurrent($name);
		if ($this->isPredefinedRole($name))
		{
			throw new \DomainException('Predefined role names are reserved.');
		}
	}

	private function isPredefinedRole(string $name): bool
	{
		static $predefinedRoles = null;
		$predefinedRoles ??= RoleDictionary::getConstants();

		return isset($predefinedRoles[$name]);
	}
}
