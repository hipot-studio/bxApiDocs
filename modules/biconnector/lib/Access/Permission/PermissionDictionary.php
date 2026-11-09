<?php

namespace Bitrix\BIConnector\Access\Permission;

use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboardGroupTable;
use Bitrix\BIConnector\Superset\MarketDashboardManager;
use Bitrix\BIConnector\Access\Rule\TableRule;
use Bitrix\BIConnector\Access\Rule\VariableRule;
use Bitrix\BIConnector\Access\Service\TableGroupMembershipService;
use Bitrix\BIConnector\Access\TableGroup\TableGroupTable;
use Bitrix\Main\Access\Permission;
use Bitrix\Main\Localization\Loc;

final class PermissionDictionary extends Permission\PermissionDictionary
{
	public const VALUE_VARIATION_ALL = -1;

	public const BIC_ACCESS = 1;

	public const BIC_SETTINGS_ACCESS = 3;
	public const BIC_SETTINGS_EDIT_RIGHTS = 4;
	public const BIC_DASHBOARD_TAG_MODIFY = 5;


	public const BIC_EXTERNAL_DASHBOARD_CONFIG = 7;
	public const BIC_DELETE_ALL_UNUSED_ELEMENTS = 8;
	public const BIC_DASHBOARD_SHARE = 9;
	public const BIC_SETTINGS_EDIT_TABLE_RIGHTS = 10;
	public const BIC_SHARE_CONTROL = 11;

	public const BIC_SECTION_EDIT_DASHBOARD_RIGHTS = 12;

	public const BIC_DASHBOARD = 100;
	public const BIC_DASHBOARD_VIEW = 101;
	public const BIC_DASHBOARD_EDIT = 102;
	public const BIC_DASHBOARD_DELETE = 103;
	public const BIC_DASHBOARD_EXPORT = 104;
	public const BIC_DASHBOARD_COPY = 105;
	public const BIC_DASHBOARD_MODIFY_SETTINGS = 106;

	public const BIC_TABLE = 200;
	public const BIC_TABLE_VIEW = 201;
	public const BIC_TABLE_EDIT = 202;
	public const BIC_TABLE_EXPORT = 203;

	/** @var int deprecated */
	public const BIC_GROUP_MODIFY = 6;
	public const BIC_DASHBOARD_CREATE = 2;
	
	private static ?array $groupPermissions = null;

	private static ?array $tableGroupPermissions = null;

	private static ?array $tableGroupSubtitles = null;

	public static function getPermission($permissionId): array
	{
		$groupPermission = is_string($permissionId) ? self::getGroupPermission($permissionId) : null;
		$permission = $groupPermission ?? parent::getPermission($permissionId);

		if ($permissionId === self::BIC_ACCESS)
		{
			$permission['title'] = Loc::getMessage('BIC_ACCESS_MSGVER_1');
			$permission['hint'] = Loc::getMessage('BIC_ACCESS_HINT_MSGVER_1');
		}

		if ($permissionId === self::BIC_DASHBOARD_EDIT)
		{
			$permission['title'] = Loc::getMessage('BIC_DASHBOARD_EDIT_MSGVER_1');
			$permission['hint'] = Loc::getMessage('HINT_BIC_DASHBOARD_EDIT_MSGVER_2');
		}

		if ($permissionId === self::BIC_DASHBOARD_EXPORT)
		{
			$permission['hint'] = Loc::getMessage('HINT_BIC_DASHBOARD_EXPORT_MSGVER_2');
		}

		if ($permissionId === self::BIC_DELETE_ALL_UNUSED_ELEMENTS)
		{
			$permission['title'] = Loc::getMessage('BIC_DELETE_ALL_UNUSED_ELEMENTS_MSGVER_2');
		}

		if ($permissionId === self::BIC_SETTINGS_EDIT_RIGHTS)
		{
			$permission['title'] = Loc::getMessage('BIC_SETTINGS_EDIT_RIGHTS_MSGVER_3');
		}

		if ($permission['type'] === Permission\PermissionDictionary::TYPE_TOGGLER)
		{
			$permission['minValue'] = '0';
			$permission['maxValue'] = '1';
		}
		elseif ($permission['type'] === Permission\PermissionDictionary::TYPE_DEPENDENT_VARIABLES)
		{
			$isTableGroup = is_string($permissionId) && self::isTableGroupPermission($permissionId);
			$permissionCodes = $isTableGroup ? self::getTablePermissionCodes() : self::getDashboardPermissionCodes();

			$permission['minValue'] = ['0'];
			$permission['maxValue'] = $permissionCodes;

			$separator = '|';
			$allSelectedKey = implode($separator, $permissionCodes);
			$permission['selectedVariablesAliases'] = [
				'separator' => $separator,
				$allSelectedKey => Loc::getMessage($isTableGroup ? 'BIC_TABLE_ACCESS_ALL' : 'BIC_DASHBOARD_ACCESS_ALL'),
			];
			$permission['dependentVariablesPopupHint'] = Loc::getMessage(
				$isTableGroup ? 'BIC_TABLE_GROUP_VARIABLES_HINT' : 'BIC_GROUP_VARIABLES_HINT'
			);
		}
		if (in_array($permissionId, self::getOldVariablesPermissions(), true))
		{
			$permission['type'] = Permission\PermissionDictionary::TYPE_MULTIVARIABLES;
		}

		return $permission;
	}

	public static function getDefaultPermissionValue($permissionId): int
	{
		$permission = self::getPermission($permissionId);
		if (
			$permission['type'] === self::TYPE_MULTIVARIABLES
			|| $permission['type'] === self::TYPE_DEPENDENT_VARIABLES
		)
		{
			return self::VALUE_VARIATION_ALL;
		}

		return self::VALUE_YES;
	}

	/**
	 * @return int[]
	 */
	public static function getDefaultPermissionGroupValues(): array
	{
		$groupPermissions = [
			self::BIC_DASHBOARD_VIEW,
			self::BIC_DASHBOARD_EDIT,
			self::BIC_DASHBOARD_DELETE,
			self::BIC_DASHBOARD_COPY,
		];

		if (MarketDashboardManager::getInstance()->isExportEnabled())
		{
			$groupPermissions[] = self::BIC_DASHBOARD_EXPORT;
		}

		return $groupPermissions;
	}

	private static function getOldVariablesPermissions(): array
	{
		return [
			self::BIC_DASHBOARD_VIEW,
			self::BIC_DASHBOARD_EDIT,
			self::BIC_DASHBOARD_COPY,
			self::BIC_DASHBOARD_DELETE,
			self::BIC_DASHBOARD_EXPORT,
		];
	}

	public static function getDashboardGroupPermissionId(int $groupId): string
	{
		return "G{$groupId}";
	}

	public static function getDashboardGroupIdFromPermission(string $permission): int
	{
		return (int)str_replace('G', '', $permission);
	}

	public static function isDashboardGroupPermission(string $permission): bool
	{
		return str_contains($permission, 'G');
	}

	public static function getTableGroupPermissionId(int $groupId): string
	{
		return "T{$groupId}";
	}

	/**
	 * The pattern lives in a method, not in a constant: the dictionary of the core builds its list of
	 * permissions by reflection over class constants, and a constant of any visibility lands in that list.
	 *
	 * @return string
	 */
	private static function getTableGroupPermissionPattern(): string
	{
		// (0|[1-9]\d*) and the D modifier keep the recognizer reversible: no leading zeros, no trailing line break
		return '/^T(0|[1-9]\d*)$/D';
	}

	public static function getTableGroupIdFromPermission(string $permission): int
	{
		if (preg_match(self::getTableGroupPermissionPattern(), $permission, $matches) !== 1)
		{
			return 0;
		}

		return (int)$matches[1];
	}

	public static function isTableGroupPermission(string $permission): bool
	{
		return preg_match(self::getTableGroupPermissionPattern(), $permission) === 1;
	}

	/**
	 * @deprecated the reset covers both axes, use clearGroupPermissions()
	 */
	public static function clearDashboardGroupPermissions(): void
	{
		self::clearGroupPermissions();
	}

	public static function clearGroupPermissions(): void
	{
		self::$groupPermissions = null;
		self::$tableGroupPermissions = null;
		self::$tableGroupSubtitles = null;

		VariableRule::clearCache();
		TableRule::clearCache();
		TableGroupMembershipService::clearCache();
	}

	/**
	 * Return the description of a group permission of the axis the id belongs to, null for any other id.
	 * The axes are asked apart: an id belongs to one of them, and building the dictionary of the other
	 * one would cost a read of its groups on every hit that has nothing to do with that axis.
	 *
	 * @param string $permissionId
	 *
	 * @return array|null
	 */
	private static function getGroupPermission(string $permissionId): ?array
	{
		$groupPermissions = self::isTableGroupPermission($permissionId)
			? self::getTableGroupPermissions()
			: self::getDashboardGroupPermissions()
		;

		return !empty($groupPermissions[$permissionId]) ? $groupPermissions[$permissionId] : null;
	}

	public static function getDashboardGroupPermissions(): array
	{
		if (self::$groupPermissions !== null)
		{
			return self::$groupPermissions;
		}

		self::$groupPermissions = [];
		$groups = SupersetDashboardGroupTable::getList([
			'select' => ['ID', 'NAME', 'TYPE', 'DASHBOARDS', 'SCOPE'],
			'cache' => ['ttl' => 3600],
		]);

		$variables = self::getDashboardPermissionVariables();

		while ($group = $groups->fetchObject())
		{
			$id = self::getDashboardGroupPermissionId($group['ID']);
			self::$groupPermissions[$id] = [
				'id' => $id,
				'title' => $group->getName(),
				'type' => self::TYPE_DEPENDENT_VARIABLES,
				'variables' => $variables,
				'emptyValue' => 0,
				'groupHead' => false,
				'subtitle' => Loc::getMessagePlural('BIC_DASHBOARD_GROUP_SUBTITLE', count($group->getDashboards()), ['#COUNT#' => count($group->getDashboards())]),
				'iconClass' => self::getGroupIconClass($group->getType() === SupersetDashboardGroupTable::GROUP_TYPE_SYSTEM),
				'isClickable' => true,
				'isDeletable' => $group->getType() === SupersetDashboardGroupTable::GROUP_TYPE_CUSTOM,
			];
		}

		return self::$groupPermissions;
	}

	public static function getTableGroupPermissions(): array
	{
		if (self::$tableGroupPermissions !== null)
		{
			return self::$tableGroupPermissions;
		}

		self::$tableGroupPermissions = [];
		$groups = TableGroupTable::getList([
			'select' => ['ID', 'NAME', 'TYPE'],
			'order' => ['SORT' => 'ASC', 'ID' => 'ASC'],
			'cache' => ['ttl' => 3600],
		]);

		$variables = self::getTablePermissionVariables();

		while ($group = $groups->fetchObject())
		{
			$id = self::getTableGroupPermissionId($group->getId());
			self::$tableGroupPermissions[$id] = [
				'id' => $id,
				'title' => $group->getName(),
				'type' => self::TYPE_DEPENDENT_VARIABLES,
				'variables' => $variables,
				'emptyValue' => 0,
				'groupHead' => false,
				'iconClass' => self::getGroupIconClass($group->isSystem()),
				'isClickable' => true,
				'isDeletable' => $group->isSystem() === false,
			];
		}

		return self::$tableGroupPermissions;
	}

	/**
	 * Captions of the table group rows: the number of tables each group holds.
	 *
	 * Asked apart from the permission list on purpose: the permission list is read on every access
	 * check, where the caption is never looked at, while counting the tables walks the composition of
	 * every group.
	 *
	 * @return array<string, string> Permission id of the group to its caption.
	 */
	public static function getTableGroupSubtitles(): array
	{
		if (self::$tableGroupSubtitles !== null)
		{
			return self::$tableGroupSubtitles;
		}

		self::$tableGroupSubtitles = [];
		foreach (array_keys(self::getTableGroupPermissions()) as $permissionId)
		{
			$groupId = self::getTableGroupIdFromPermission((string)$permissionId);
			$tableCount = count(TableGroupMembershipService::getTableCodesByGroupId($groupId));

			self::$tableGroupSubtitles[$permissionId] = Loc::getMessagePlural(
				'BIC_TABLE_GROUP_SUBTITLE',
				$tableCount,
				['#COUNT#' => $tableCount],
			);
		}

		return self::$tableGroupSubtitles;
	}

	/**
	 * @return int[]
	 */
	public static function getTablePermissionCodes(): array
	{
		return [
			self::BIC_TABLE_VIEW,
			self::BIC_TABLE_EDIT,
			self::BIC_TABLE_EXPORT,
		];
	}

	private static function getTablePermissionVariables(): array
	{
		$permissionCodes = self::getTablePermissionCodes();

		$variables = [
			[
				'id' => '0',
				'title' => Loc::getMessage('BIC_TABLE_NO_ACCESS'),
				'useAsNothingSelectedInSubsection' => true,
				'useAsEmpty' => true,
				'conflictsWith' => $permissionCodes,
			],
		];

		foreach ($permissionCodes as $permissionCode)
		{
			$permission = self::getPermission($permissionCode);
			$variable = [
				'id' => $permissionCode,
				'title' => $permission['title'],
				'hint' => $permission['hint'],
			];
			if ($permissionCode !== self::BIC_TABLE_VIEW)
			{
				$variable['requires'] = [
					self::BIC_TABLE_VIEW,
				];
			}

			$variables[] = $variable;
		}

		return $variables;
	}

	public static function getDashboardPermissionCodes(): array
	{
		$permissionCodes = [
			self::BIC_DASHBOARD_VIEW,
			self::BIC_DASHBOARD_EDIT,
			self::BIC_DASHBOARD_COPY,
			self::BIC_DASHBOARD_DELETE,
		];
		if (MarketDashboardManager::getInstance()->isExportEnabled())
		{
			$permissionCodes[] = self::BIC_DASHBOARD_EXPORT;
		}

		return $permissionCodes;
	}

	private static function getDashboardPermissionVariables(): array
	{
		$permissionCodes = self::getDashboardPermissionCodes();

		$variables = [
			[
				'id' => '0',
				'title' => Loc::getMessage('BIC_DASHBOARD_NO_ACCESS'),
				'useAsNothingSelectedInSubsection' => true,
				'useAsEmpty' => true,
				'conflictsWith' => $permissionCodes,
			],
		];


		foreach ($permissionCodes as $permissionCode)
		{
			$permission = self::getPermission($permissionCode);
			$variable = [
				'id' => $permissionCode,
				'title' => $permission['title'],
				'hint' => $permission['hint'],
			];
			if ($permissionCode === self::BIC_DASHBOARD_EDIT || $permissionCode === self::BIC_DASHBOARD_COPY)
			{
				$variable['requires'] = [
					self::BIC_DASHBOARD_VIEW,
				];
			}
			if ($permissionCode === self::BIC_DASHBOARD_DELETE)
			{
				$variable['requires'] = [
					self::BIC_DASHBOARD_VIEW,
					self::BIC_DASHBOARD_EDIT,
				];
			}
			if (MarketDashboardManager::getInstance()->isExportEnabled())
			{
				if ($permissionCode === self::BIC_DASHBOARD_EXPORT)
				{
					$variable['requires'] = [
						self::BIC_DASHBOARD_VIEW,
						self::BIC_DASHBOARD_EDIT,
						self::BIC_DASHBOARD_DELETE,
					];
				}
			}
			$variables[] = $variable;
		}

		return $variables;
	}

	private static function getGroupIconClass(bool $isSystem): string
	{
		return $isSystem
			? 'ui-icon ui-icon-file-air-folder-24'
			: 'ui-icon ui-icon-file-air-folder-person'
		;
	}

	public static function getNewGroupPermissions(): array
	{
		$separator = '|';
		$allSelectedKey = implode($separator, self::getDashboardPermissionCodes());

		return [
			'id' => 'new_G0',
			'title' => Loc::getMessage('BIC_DASHBOARD_GROUP_TITLE'),
			'type' => self::TYPE_DEPENDENT_VARIABLES,
			'variables' => self::getDashboardPermissionVariables(),
			'subtitle' => Loc::getMessagePlural('BIC_DASHBOARD_GROUP_SUBTITLE', 0, ['#COUNT#' => 0]),
			'minValue' => ['0'],
			'maxValue' => self::getDashboardPermissionCodes(),
			'selectedVariablesAliases' => [
				'separator' => $separator,
				$allSelectedKey => Loc::getMessage('BIC_DASHBOARD_ACCESS_ALL'),
			],
			'emptyValue' => 0,
			'groupHead' => false,
			'iconClass' => self::getGroupIconClass(false),
			'isClickable' => true,
			'isDeletable' => true,
			'isNew' => true,
			'isModified' => true,
			'dependentVariablesPopupHint' => Loc::getMessage('BIC_GROUP_VARIABLES_HINT'),
		];
	}

	/**
	 * Placeholder row of a table group that does not exist yet: it lives in the matrix until it is
	 * saved, so its id carries the new_ prefix the strict T<id> recognizer never accepts.
	 *
	 * @return array
	 */
	public static function getNewTableGroupPermissions(): array
	{
		$separator = '|';
		$permissionCodes = self::getTablePermissionCodes();
		$allSelectedKey = implode($separator, $permissionCodes);

		return [
			'id' => 'new_T0',
			'title' => Loc::getMessage('BIC_TABLE_GROUP_TITLE'),
			'type' => self::TYPE_DEPENDENT_VARIABLES,
			'variables' => self::getTablePermissionVariables(),
			'subtitle' => Loc::getMessagePlural('BIC_TABLE_GROUP_SUBTITLE', 0, ['#COUNT#' => 0]),
			'minValue' => ['0'],
			'maxValue' => $permissionCodes,
			'selectedVariablesAliases' => [
				'separator' => $separator,
				$allSelectedKey => Loc::getMessage('BIC_TABLE_ACCESS_ALL'),
			],
			'emptyValue' => 0,
			'groupHead' => false,
			'iconClass' => self::getGroupIconClass(false),
			'isClickable' => true,
			'isDeletable' => true,
			'isNew' => true,
			'isModified' => true,
			'dependentVariablesPopupHint' => Loc::getMessage('BIC_TABLE_GROUP_VARIABLES_HINT'),
		];
	}
}
