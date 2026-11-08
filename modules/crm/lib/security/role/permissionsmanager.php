<?php

namespace Bitrix\Crm\Security\Role;

use Bitrix\Crm\Security\Role\Manage\Permissions\ObserversWrite;
use Bitrix\Crm\Security\Role\Model\RoleRelationTable;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\UserPermissions;

/**
 * @internal
 * Do not use directly. Use \Bitrix\Crm\Service\Container::getInstance()->getUserPermissions() to check permissions
 */
class PermissionsManager
{
	private const CACHE_TIME = 8640000; // 100 days
	private const CACHE_PATH = '/crm/user_permission_roles/';

	private ?array $userRoles = null;

	private array $loadedEntities = [
		'byEntity' => [],
		'byPermission' => [],
		'data' => [],
	];

	private static array $instances = [];

	public static function getInstance(int $userId): self
	{
		if (!isset(self::$instances[$userId]))
		{
			self::$instances[$userId] = new self($userId);
		}

		return self::$instances[$userId];
	}

	public static function clearCache(): void
	{
		foreach (self::$instances as $instance)
		{
			$instance->userRoles = null;
			$instance->loadedEntities = [
				'byEntity' => [],
				'byPermission' => [],
				'data' => [],
			];
		}
	}

	private function __construct(
		private readonly int $userId,
	)
	{
	}

	public function hasPermission(string $entity, string $permissionType): bool
	{
		if ($this->isAdmin())
		{
			return true;
		}

		return $this->load($entity, $permissionType)->hasPermission();
	}

	public function hasPermissionLevel(string $entity, string $permissionType, string $level): bool
	{
		if ($this->isAdmin())
		{
			return true;
		}

		return $this->load($entity, $permissionType)->hasPermissionLevel($level);
	}

	/**
	 * Card observers are allowed to update the cards they observe.
	 *
	 * The coarse, block-wide reading: "the right is enabled on at least one stage of this block". It belongs to
	 * the coarse steps of the AND-composition the callers build
	 * ({@see \Bitrix\Crm\Service\UserPermissions\EntityPermissions\Item::hasPermissions()},
	 * {@see \CCrmAuthorizationHelper::checkPermissionForTypeAndAttributes()},
	 * {@see \Bitrix\Crm\Service\UserPermissions\EntityPermissions\Type::hasTypeLevelPermission()}), none of which
	 * knows the card and therefore its stage. Replacing it with the exact reading below would hand those steps the
	 * value of the block and close the cards standing on a stage where the right is granted.
	 */
	public function isObserversWriteEnabled(string $entity): bool
	{
		return $this->hasPermissionLevel($entity, ObserversWrite::CODE, UserPermissions::PERMISSION_ALL);
	}

	/**
	 * The exact reading of the same right: its effective value for a card carrying these attributes - the stage
	 * row of the right when one of them matches a stage attribute, and the value of the block otherwise.
	 *
	 * The only consumer is the observers branch of {@see PermissionLevel::getValueByEntityAttributes()}. The
	 * threshold is the one of the coarse reading above - the effective attribute compared to PERMISSION_ALL - and
	 * not the "any permissive attribute" of {@see self::hasPermissionByEntityAttributes()}.
	 */
	public function isObserversWriteEnabledByEntityAttributes(string $entity, array $entityAttributes): bool
	{
		if ($this->isAdmin())
		{
			return true;
		}

		return $this->getPermissionLevel($entity, ObserversWrite::CODE)
			->isPermissionLevelEqualsToByEntityAttributes(UserPermissions::PERMISSION_ALL, $entityAttributes)
		;
	}

	public function hasMaxPermissionLevel(string $entity, string $permissionType): bool
	{
		if ($this->isAdmin())
		{
			return true;
		}

		return $this->load($entity, $permissionType)->hasMaxPermissionLevel();
	}

	public function hasPermissionByEntityAttributes(string $entity, string $permissionType, array $entityAttributes): bool
	{
		if ($this->isAdmin())
		{
			return true;
		}

		return $this->load($entity, $permissionType)->hasPermissionByEntityAttributes($entityAttributes);
	}

	/**
	 * @deprecated
	 * Used in backward compatibility methods only
	 * Will be removed soon!
	 */
	public function getPermissionAttributeByEntityAttributes(string $entity, string $permissionType, array $entityAttributes): string
	{
		return $this->load($entity, $permissionType)->getPermissionAttributeByEntityAttributes($entityAttributes);
	}

	public function doUserAttributesMatchesToEntityAttributes(string $entity, string $permissionType, mixed $entityAttributes): bool
	{
		return $this->load($entity, $permissionType)->compareUserAttributesWithEntityAttributes($entityAttributes);
	}

	public function getPermissionLevel(string $entity, string $permissionType): PermissionLevel
	{
		return $this->load($entity, $permissionType);
	}

	public function preloadEntities(array $entities): void
	{
		if ($this->isAdmin())
		{
			return;
		}

		$entities = array_values(array_unique(array_filter(
			$entities,
			static fn(mixed $entity): bool => is_string($entity) && $entity !== '',
		)));
		$entities = array_values(array_filter(
			$entities,
			fn(string $entity): bool => !isset($this->loadedEntities['byEntity'][$entity]),
		));
		if ($entities === [])
		{
			return;
		}

		foreach ($entities as $entity)
		{
			$this->loadedEntities['byEntity'][$entity] = true;
		}

		$roleIds = $this->getUserRoles();
		if ($roleIds === [])
		{
			return;
		}

		$this->loadPermissions([
			'@ENTITY' => $entities,
			'@ROLE_ID' => $roleIds,
		]);
	}

	private function load(string $entity, string $permissionType): PermissionLevel
	{
		$resultKey = $entity . '_' . $permissionType;

		if (
			isset($this->loadedEntities['byEntity'][$entity])
			|| isset($this->loadedEntities['byPermission'][$permissionType])
		)
		{
			// the entity is already loaded, so an absent key means "no rows": build that empty level once
			return $this->loadedEntities['data'][$resultKey] ??= $this->createPermissionLevel($entity, $permissionType);
		}
		$roleIds = $this->getUserRoles();

		$filter = [];

		if ($permissionType === UserPermissions::OPERATION_READ) // Read permissions should be loaded all together for optimization
		{
			$filter['=PERM_TYPE'] = $permissionType;
			$this->loadedEntities['byPermission'][$permissionType] = true;
		}
		else
		{
			$filter['=ENTITY'] = $entity;
			$this->loadedEntities['byEntity'][$entity] = true;
		}

		if (empty($roleIds))
		{
			return $this->createPermissionLevel($entity, $permissionType);
		}

		$filter['@ROLE_ID'] = $roleIds;

		$this->loadPermissions($filter);

		return $this->loadedEntities['data'][$resultKey] ?? $this->createPermissionLevel($entity, $permissionType);
	}

	private function loadPermissions(array $filter): void
	{
		$rolePermissions = \Bitrix\Crm\Security\Role\Model\RolePermissionTable::getList([
			'filter' => $filter,
			'select' => [
				'ROLE_ID',
				'ENTITY',
				'FIELD',
				'FIELD_VALUE',
				'ATTR',
				'PERM_TYPE',
				'SETTINGS',
			],
			'cache' => [
				'ttl' => 84600,
			]
		]);

		while ($rolePermission = $rolePermissions->fetchObject())
		{
			$roleId = (int)$rolePermission->getRoleId();
			$attribute = (string)$rolePermission->getAttr();
			$field = (string)$rolePermission->getField();
			$fieldValue = (string)$rolePermission->getFieldValue();
			$entity = (string)$rolePermission->getEntity();
			$permType = (string)$rolePermission->getPermType();
			$settings = $rolePermission->getSettings() ?? [];

			$permissionKey = $entity . '_' . $permType;

			$permissionLevel = $this->loadedEntities['data'][$permissionKey] ?? $this->createPermissionLevel($entity, $permType);
			$permissionLevel
				->addValueAttribute($attribute, $roleId, $field, $fieldValue)
				->addValueSettings($settings, $roleId, $field, $fieldValue)
			;
			$this->loadedEntities['data'][$permissionKey] = $permissionLevel;
		}
	}

	public function getUserRoles(): array
	{
		if (is_array($this->userRoles))
		{
			return $this->userRoles;
		}

		$this->userRoles = [];

		if($this->userId <= 0)
		{
			$this->userRoles = [];

			return $this->userRoles;
		}

		$userAccessCodes = \Bitrix\Crm\Service\Container::getInstance()
			->getUserPermissions($this->userId)
			->getAttributesProvider()
			->getUserAttributesCodes()
		;

		if (!empty($userAccessCodes))
		{
			$rolesRelations = RoleRelationTable::getList([
				'filter' => [
					'@RELATION' => $userAccessCodes,
				],
				'select' => [
					'ROLE_ID'
				],
				'cache' => ['ttl' => self::CACHE_TIME]
			]);
			while ($roleRelation = $rolesRelations->fetch())
			{
				$this->userRoles[] = $roleRelation['ROLE_ID'];
			}
		}

		return $this->userRoles;
	}

	private function createPermissionLevel(string $permissionEntity, string $permissionType): PermissionLevel
	{
		return new PermissionLevel(
			$this->userId,
			$permissionEntity,
			$permissionType,
			$this->getUserPermissions()->getAttributesProvider(),
			$this->isAdmin()
		);
	}

	private function isAdmin(): bool
	{
		return $this->getUserPermissions()->isAdmin();
	}

	private function getUserPermissions(): UserPermissions
	{
		return Container::getInstance()->getUserPermissions($this->userId);
	}
}
