<?php

namespace Bitrix\Crm\Security\Role\UIAdapters\AccessRights\Serializers;

use Bitrix\Crm\Security\EntityPermission\ApproveCustomPermsToExistRole;
use Bitrix\Crm\Security\Role\Manage\DTO\EntityDTO;
use Bitrix\Crm\Security\Role\Manage\Permissions\Permission;
use Bitrix\Crm\Security\Role\UIAdapters\AccessRights\PermIdentifier;
use Bitrix\Crm\Security\Role\UIAdapters\AccessRights\Utils\PermCodeTransformer;
use Bitrix\Main\Loader;
use Bitrix\UI\AccessRights\V2\Options\RightSection;

Loader::requireModule('ui');

class AccessRightsEntitySerializer
{
	/**
	 * @param EntityDTO[] $entities
	 * @return RightSection[]
	 */
	public function serialize(array $entities): array
	{
		$accessRights = [];
		foreach ($entities as $entity)
		{
			$rights = [];

			foreach ($entity->permissions() as $perm)
			{
				$rightCode = PermCodeTransformer::getInstance()->makeAccessRightPermCode(
					new PermIdentifier($entity->code(), $perm->code())
				);
				if ((new ApproveCustomPermsToExistRole())->hasWaitingPermission($perm))
				{
					continue;
				}

				$permRight = $this->createRight(
					$perm->name(),
					$rightCode,
					$perm,
					$perm->variants()?->getValuesForSection()
				);

				$permRight->setIsGroupHead($perm->canAssignPermissionToStages() && !empty($entity->fields()));
				$rights[] = $permRight;

				if (!$perm->canAssignPermissionToStages())
				{
					continue;
				}

				foreach ($entity->fields() as $fieldName => $fieldValues)
				{
					foreach ($fieldValues as $valueCode => $valueName)
					{
						$fieldRightCode = PermCodeTransformer::getInstance()->makeAccessRightPermCode(
							new PermIdentifier($entity->code(), $perm->code(), $fieldName, $valueCode)
						);

						$right = $this->createRight(
							$valueName,
							$fieldRightCode,
							$perm,
							$perm->variants()?->getValuesForSubsection($valueCode),
							$rightCode
						);

						// prefer 'inherit' on group actions
						$right->setIsEmptyOnSetMinMaxValueInColumn(true);

						$rights[] = $right;
					}
				}
			}
			if (empty($rights))
			{
				continue;
			}

			$section = new RightSection($entity->name());

			$section
				->setCode($entity->code())
				->setRightItems($rights)
				->setSubTitle($entity->description())
				->setIconCode($entity->iconCode())
				->setIconBgColor($entity->iconColor())
			;

			$accessRights[] = $section;
		}

		return $accessRights;
	}

	private function createRight(
		string $rightName,
		string $rightCode,
		Permission $permission,
		?array $variables = null,
		?string $parentCode = null,
	): RightSection\RightItem
	{
		$controlType = $permission->getControlMapper();

		$options = [
			'id' => $rightCode,
			'title' => $rightName ?: $permission->name(),
			'hint' => $permission->explanation(),
			'group' => $parentCode,
			'type' => $controlType->getType(),
			'minValue' => $controlType->getMinValue(),
			'maxValue' => $controlType->getMaxValue(),
		];
		$options = array_merge($options, $controlType->getExtraOptions());

		if (!is_null($variables))
		{
			$options['variables'] = $variables;

			$emptyValue = $this->getEmptyValue($variables);
			if ($emptyValue !== null)
			{
				$options['emptyValue'] = $emptyValue;
			}

			$nothingSelectedValue = $this->getNothingSelectedValue($variables);
			if ($nothingSelectedValue !== null)
			{
				$options['nothingSelectedValue'] = $nothingSelectedValue;
			}

			$defaultValue = $this->getDefaultValue($variables);
			if ($defaultValue !== null)
			{
				$options['defaultValue'] = $defaultValue;
			}
		}

		return RightSection\RightItem::tryFromArray($options);
	}

	private function getEmptyValue(array $variables): ?string
	{
		foreach ($variables as $variable)
		{
			if ($variable['useAsEmpty'] ?? false)
			{
				return $variable['id'];
			}
		}

		return null;
	}

	private function getNothingSelectedValue(array $variables): ?string
	{
		foreach ($variables as $variable)
		{
			if ($variable['useAsNothingSelected'] ?? false)
			{
				return $variable['id'];
			}
		}

		return null;
	}

	private function getDefaultValue(array $variables): ?string
	{
		foreach ($variables as $variable)
		{
			if ($variable['default'] ?? false)
			{
				return $variable['id'];
			}
		}

		return null;
	}
}
