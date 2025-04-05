<?php

namespace Bitrix\Crm\Security\Role;

use Bitrix\Crm\Category\PermissionEntityTypeHelper;
use Bitrix\Crm\Item;
use Bitrix\Crm\Security\AttributesProvider;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\UserPermissions;

class PermissionLevel
{
	private array $maxAttribute = [];
	private array $attributeToRoleRelations = [];
	private array $settingsToRoleRelations = [];
	private array $settingsValuesByField = [];

	private const EMPTY_FIELD_VALUE = '';

	public function __construct(
		private readonly int $userId,
		private readonly string $permissionEntity,
		private readonly string $permissionType,
		private readonly AttributesProvider $attributesProvider,
		private readonly bool $isAdmin,
	)
	{
	}

	public function addValueAttribute(string $attribute, int $roleId, string $field, string $fieldValue): self
	{
		$field = $this->normalizeField($field);
		$fieldKey = $this->getFieldKey($field, $fieldValue);

		$this->maxAttribute[$fieldKey] = max($attribute, $this->maxAttribute[$fieldKey] ?? UserPermissions::PERMISSION_NONE);
		if (!isset($this->attributeToRoleRelations[$fieldKey]))
		{
			$this->attributeToRoleRelations[$fieldKey] = [];
		}
		if (!isset($this->attributeToRoleRelations[$fieldKey][$attribute]))
		{
			$this->attributeToRoleRelations[$fieldKey][$attribute] = [];
		}
		$this->attributeToRoleRelations[$fieldKey][$attribute][] = $roleId;

		return $this;
	}

	public function addValueSettings(array $settings, int $roleId, string $field, string $fieldValue): self
	{
		$field = $this->normalizeField($field);
		$fieldKey = $this->getFieldKey($field, $fieldValue);

		foreach ($settings as $setting)
		{
			if (!isset($this->settingsToRoleRelations[$fieldKey]))
			{
				$this->settingsToRoleRelations[$fieldKey] = [];
			}
			if (!isset($this->settingsToRoleRelations[$fieldKey][$setting]))
			{
				$this->settingsToRoleRelations[$fieldKey][$setting] = [];
			}
			$this->settingsToRoleRelations[$fieldKey][$setting][] = $roleId;

			if (!isset($this->settingsValuesByField[$fieldValue]))
			{
				$this->settingsValuesByField[$fieldValue] = [];
			}
			$this->settingsValuesByField[$fieldValue][$setting] = $setting;
		}

		return $this;
	}

	public function hasPermission(): bool
	{
		// @todo also use $this->settingsToRoleRelations
		if (empty($this->maxAttribute))
		{
			return false;
		}

		return max($this->maxAttribute) > UserPermissions::PERMISSION_NONE;
	}

	public function hasPermissionLevel(string $level): bool
	{
		// @todo also use $this->settingsToRoleRelations
		if (empty($this->maxAttribute))
		{
			return false;
		}

		return max($this->maxAttribute) >= $level;
	}


	/**
	 * @internal
	 * @deprecated
	 * Used in backward compatibility methods only
	 * Will be removed soon!
	 */
	public function getPermissionAttributeByEntityAttributes(array $entityAttributes): string
	{
		if ($this->isAdmin)
		{
			return UserPermissions::PERMISSION_ALL;
		}

		if (empty($this->attributeToRoleRelations) && empty($this->settingsToRoleRelations))
		{
			return UserPermissions::PERMISSION_NONE;
		}

		if(
			$this->permissionType === UserPermissions::OPERATION_READ
			&& (
				in_array( UserPermissions::ATTRIBUTES_READ_ALL, $entityAttributes, true)
				|| in_array(UserPermissions::ATTRIBUTES_CONCERNED_USER_PREFIX.$this->userId, $entityAttributes, true)
			)
		)
		{
			return UserPermissions::PERMISSION_ALL;
		}

		if (empty($entityAttributes))
		{
			$attributes = $this->attributeToRoleRelations[self::EMPTY_FIELD_VALUE];

			return empty($attributes) ? UserPermissions::PERMISSION_NONE : max(array_keys($attributes));
		}

		foreach ($this->attributeToRoleRelations as $fieldKey => $attributes)
		{
			if ($fieldKey === self::EMPTY_FIELD_VALUE)
			{
				continue;
			}

			if (in_array($fieldKey, $entityAttributes))
			{
				return max(array_keys($attributes));
			}
		}
		$attributes = $this->attributeToRoleRelations[self::EMPTY_FIELD_VALUE];

		return empty($attributes) ? UserPermissions::PERMISSION_NONE : max(array_keys($attributes));
	}

	public function hasPermissionByEntityAttributes(array $entityAttributes): bool
	{
		return $this->getPermissionAttributeByEntityAttributes($entityAttributes) > UserPermissions::PERMISSION_NONE;
	}


	/**
	 * @internal
	 * @deprecated
	 * Used in backward compatibility methods only
	 * Will be removed soon!
	 */
	public function compareUserAttributesWithEntityAttributes(array $entityAttributes): bool
	{
		$entityAttributeForUser = $this->getPermissionAttributeByEntityAttributes($entityAttributes);
		if ($entityAttributeForUser == UserPermissions::PERMISSION_NONE)
		{
			return false;
		}
		if ($entityAttributeForUser == UserPermissions::PERMISSION_ALL)
		{
			return true;
		}
		if ($entityAttributeForUser == UserPermissions::PERMISSION_OPENED)
		{
			if((in_array(UserPermissions::ATTRIBUTES_OPENED, $entityAttributes) || in_array(UserPermissions::ATTRIBUTES_USER_PREFIX . $this->userId, $entityAttributes)))
			{
				return true;
			}
		}

		if ($entityAttributeForUser >= UserPermissions::PERMISSION_SELF && in_array(UserPermissions::ATTRIBUTES_USER_PREFIX . $this->userId, $entityAttributes))
		{
			return true;
		}

		$userAttributes = $this->attributesProvider->getUserAttributes();

		if ($entityAttributeForUser >= UserPermissions::PERMISSION_DEPARTMENT && is_array($userAttributes['INTRANET']))
		{
			// PERM_OPEN: user may access to not opened entities in his department
			foreach ($userAttributes['INTRANET'] as $departmentId)
			{
				if (in_array($departmentId, $entityAttributes))
				{
					return true;
				}
			}
		}
		if ($entityAttributeForUser >= UserPermissions::PERMISSION_SUBDEPARTMENT && is_array($userAttributes['SUBINTRANET']))
		{
			// PERM_OPEN: user may access to not opened entities in his intranet
			foreach ($userAttributes['SUBINTRANET'] as $departmentId)
			{
				if (in_array($departmentId, $entityAttributes))
				{
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * @internal
	 * @deprecated
	 * Used in backward compatibility methods only
	 * Will be removed soon!
	 */
	public function getEntityListAttributes(): array
	{
		$result = [];
		if (!$this->hasPermission())
		{
			return $result;
		}

		$userAttributes = $this->attributesProvider->getUserAttributes();

		$attributes = $this->attributeToRoleRelations[self::EMPTY_FIELD_VALUE] ?? null;
		$defaultPermission = empty($attributes) ? UserPermissions::PERMISSION_NONE : max(array_keys($attributes));

		if (!is_null($attributes) && count($this->attributeToRoleRelations) == 1)
		{
			$permission = $defaultPermission;

			$result = array_merge(
				$result,
				$this->prepareAttributesByPermission($userAttributes, $permission)
			);
		}
		$attributeToRoleRelationsForFields = [];
		foreach ($this->attributeToRoleRelations as $fieldKey => $attributes)
		{
			if ($fieldKey !== self::EMPTY_FIELD_VALUE)
			{
				$attributeToRoleRelationsForFields[$fieldKey] = $attributes;
			}
		}

		if (!empty($attributeToRoleRelationsForFields))
		{
			$stageFieldKeys = $this->getEntityStageFieldKeys($this->permissionEntity);
			foreach ($stageFieldKeys as $stageFieldKey)
			{
				$permission = $defaultPermission;
				if (!empty($attributeToRoleRelationsForFields[$stageFieldKey]))
				{
					$permission = max(array_keys($attributeToRoleRelationsForFields[$stageFieldKey]));
				}
				$result = array_merge(
					$result,
					$this->prepareAttributesByPermission($userAttributes, $permission, $stageFieldKey)
				);
			}
		}

		return $result;
	}

	public function isPermissionLevelEqualsToByEntityAttributes(string $permissionAttribute, array $entityAttributes): bool
	{
		return $this->getPermissionAttributeByEntityAttributes($entityAttributes) === $permissionAttribute;
	}

	private function normalizeField(string $field): string
	{
		if ($field === '-')
		{
			$field = self::EMPTY_FIELD_VALUE;
		}

		return $field;
	}

	private function getFieldKey(string $field, string $fieldValue): string
	{
		return $field . $fieldValue;
	}

	public function getSettingsForStage(string $stageId): array
	{
		$result = [];

		$settingsForStage = $this->settingsValuesByField[$stageId] ?? null;
		if (is_array($settingsForStage))
		{
			if (array_values($settingsForStage) === [UserPermissions::SETTINGS_INHERIT])
			{
				$result = $this->settingsValuesByField[self::EMPTY_FIELD_VALUE] ?? [];
			}
			else
			{
				$result = $settingsForStage;
			}
		}
		else
		{
			$result = $this->settingsValuesByField[self::EMPTY_FIELD_VALUE] ?? [];
		}

		return array_filter(array_values($result));
	}

	private function prepareAttributesByPermission(array $userAttributes, string $permission, $statusRestriction = null): array
	{
		$result = [];
		$partOfResult = [];

		if ($permission == UserPermissions::PERMISSION_NONE)
		{
			return [];
		}
		elseif ($permission == UserPermissions::PERMISSION_OPENED)
		{
			$partOfResult[] = UserPermissions::ATTRIBUTES_OPENED;
			foreach ($userAttributes['USER'] as $userId)
			{

				$result[] = $statusRestriction ? [$statusRestriction, $userId] : [$userId];
			}
		}
		elseif ($permission != UserPermissions::PERMISSION_ALL)
		{
			if ($permission >= UserPermissions::PERMISSION_SELF)
			{
				foreach ($userAttributes['USER'] as $userId)
				{
					$result[] =  $statusRestriction ? [$statusRestriction, $userId] : [$userId];
				}
			}
			if ($permission >= UserPermissions::PERMISSION_DEPARTMENT && isset($userAttributes['INTRANET']))
			{
				foreach ($userAttributes['INTRANET'] as $departmentId)
				{
					//HACK: SKIP IU code it is not required for this method
					if ($departmentId != '' && mb_substr($departmentId, 0, 2) === 'IU')
					{
						continue;
					}

					if (!in_array($departmentId, $partOfResult))
					{
						$partOfResult[] = $departmentId;
					}
				}
			}
			if ($permission >= UserPermissions::PERMISSION_SUBDEPARTMENT && isset($userAttributes['SUBINTRANET']))
			{
				foreach ($userAttributes['SUBINTRANET'] as $departmentId)
				{
					if ($departmentId != '' && mb_substr($departmentId, 0, 2) === 'IU')
					{
						continue;
					}

					if (!in_array($departmentId, $partOfResult))
					{
						$partOfResult[] = $departmentId;
					}
				}
			}
		}
		else //self::PERM_ALL
		{
			$result[] = $statusRestriction ? [$statusRestriction] : [];
		}

		if (!empty($partOfResult))
		{
			$result[] = $statusRestriction
				? array_merge([$statusRestriction], $partOfResult)
				: $partOfResult;
		}

		return $result;
	}

	protected function getEntityStageFieldKeys(string $permissionEntityType): array
	{
		static $cache = [];
		if (isset($cache[$permissionEntityType]))
		{
			return $cache[$permissionEntityType];
		}

		$stageFieldKEys = [];
		$categoryIdentifier = PermissionEntityTypeHelper::extractEntityAndCategoryFromPermissionEntityType($permissionEntityType);
		$entityTypeId = $categoryIdentifier?->getEntityTypeId();
		$factory = Container::getInstance()->getFactory($entityTypeId);
		if (
			$factory && $factory->isStagesSupported()
		)
		{
			$stageFieldName = $factory->getEntityFieldNameByMap(Item::FIELD_NAME_STAGE_ID);

			$categoryId = $categoryIdentifier?->getCategoryId();
			$stages = $factory->getStages((int)$categoryId);
			foreach ($stages->getAll() as $stage)
			{
				$stageFieldKEys[] = $stageFieldName . $stage->getStatusId();
			}

		}
		$cache[$permissionEntityType] = $stageFieldKEys;

		return $stageFieldKEys;
	}
}
