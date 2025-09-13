<?php

namespace Bitrix\HumanResources\Access\Rule\Factory;

use Bitrix\HumanResources\Access\Permission\PermissionDictionary;
use Bitrix\Main\Access\AccessibleController;
use Bitrix\Main\Access\Rule\Factory\RuleControllerFactory;
use Bitrix\HumanResources\Access\StructureActionDictionary;

class RuleFactory extends RuleControllerFactory
{
	protected const STRUCTURE_BASE_RULE = 'StructureBase';
	protected const STRUCTURE_TEAM_BASE_RULE = 'StructureTeamBase';
	protected const STRUCTURE_BASE_TOGGLE_RULE = 'StructureBaseToggle';

	protected function getClassName(string $action, AccessibleController $controller): ?string
	{
		$actionName = StructureActionDictionary::getActionName($action);
		if (!$actionName)
		{
			return null;
		}

		$actionName = explode('_', $actionName);
		$actionName = array_map(fn($el) => ucfirst(mb_strtolower($el)), $actionName);
		$ruleClass = $this->getNamespace($controller) . implode($actionName) . static::SUFFIX;

		if (class_exists($ruleClass))
		{
			return $ruleClass;
		}

		$actionPermissionMap = StructureActionDictionary::getActionPermissionMap();
		if (array_key_exists($action, $actionPermissionMap))
		{
			$permissionId = (string)$actionPermissionMap[$action];
			if (PermissionDictionary::isTogglePermission($permissionId))
			{
				return $this->getNamespace($controller) . static::STRUCTURE_BASE_TOGGLE_RULE . static::SUFFIX;
			}

			if (PermissionDictionary::isTeamDependentVariablesPermission($permissionId))
			{
				return $this->getNamespace($controller) . static::STRUCTURE_TEAM_BASE_RULE . static::SUFFIX;
			}

			return $this->getNamespace($controller) . static::STRUCTURE_BASE_RULE . static::SUFFIX;
		}

		return null;
	}
}