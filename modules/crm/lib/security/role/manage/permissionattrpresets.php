<?php

namespace Bitrix\Crm\Security\Role\Manage;

use Bitrix\Crm\Security\Role\Manage\AttrPreset\UserDepartmentAndOpened;
use Bitrix\Crm\Security\Role\Manage\Permissions\Add;
use Bitrix\Crm\Security\Role\Manage\Permissions\Automation;
use Bitrix\Crm\Security\Role\Manage\Permissions\Delete;
use Bitrix\Crm\Security\Role\Manage\Permissions\Export;
use Bitrix\Crm\Security\Role\Manage\Permissions\HideSum;
use Bitrix\Crm\Security\Role\Manage\Permissions\Import;
use Bitrix\Crm\Security\Role\Manage\Permissions\MyCardView;
use Bitrix\Crm\Security\Role\Manage\Permissions\ObserversWrite;
use Bitrix\Crm\Security\Role\Manage\Permissions\Permission;
use Bitrix\Crm\Security\Role\Manage\Permissions\Read;
use Bitrix\Crm\Security\Role\Manage\Permissions\Transition;
use Bitrix\Crm\Security\Role\Manage\Permissions\Write;
use Bitrix\Crm\Security\Role\UIAdapters\AccessRights\ControlMapper\BaseControlMapper;
use Bitrix\Crm\Security\Role\UIAdapters\AccessRights\ControlMapper\DependentVariables;
use Bitrix\Crm\Security\Role\UIAdapters\AccessRights\ControlMapper\Toggler;
use Bitrix\Crm\Security\Role\UIAdapters\AccessRights\ControlMapper\Variables;
use Bitrix\Crm\Security\Role\UIAdapters\AccessRights\Variants;
use Bitrix\Crm\Service\UserPermissions;
use Bitrix\Main\Localization\Loc;
use Bitrix\Ui\Public\Enum\IconSet\Outline;

class PermissionAttrPresets
{
	/**
	 * @return Permission[]
	 */
	public static function crmEntityPreset(
		?string $inheritDescription = null,
		bool $withObserversWrite = false,
		bool $withObserversWriteByStages = false,
	): array
	{
		$permissionPreset = (new UserDepartmentAndOpened())
			->setInheritDescription($inheritDescription);
		$variants = $permissionPreset->getVariants();

		$dependentVariablesAsSettings = (new DependentVariables\UserDepartmentAndOpenedAsSettings())
			->setPermissionPreset($permissionPreset)
			->addSelectedVariablesAlias(
				[
					UserDepartmentAndOpened::SELF,
					UserDepartmentAndOpened::DEPARTMENT,
					UserDepartmentAndOpened::SUBDEPARTMENTS,
					UserDepartmentAndOpened::TEAM,
					UserDepartmentAndOpened::SUBTEAMS,
					UserDepartmentAndOpened::OPEN,
					UserDepartmentAndOpened::ALL,
				],
				Loc::getMessage('CRM_SECURITY_ROLE_PERMS_TYPE_X'),
			)
		;

		return self::createCrmEntityPreset(
			$variants,
			$dependentVariablesAsSettings,
			$withObserversWrite,
			$withObserversWriteByStages,
		);
	}

	public static function crmEntityPresetWithoutTeams(
		?string $inheritDescription = null,
		bool $withObserversWrite = false,
		bool $withObserversWriteByStages = false,
	): array
	{
		$permissionPreset = (new UserDepartmentAndOpened())
			->setInheritDescription($inheritDescription);
		$permissionPreset
			->exclude(UserDepartmentAndOpened::TEAM)
			->exclude(UserDepartmentAndOpened::SUBTEAMS)
		;
		$variants = $permissionPreset->getVariants();

		$dependentVariablesAsSettingsWithoutTeams = (new DependentVariables\UserDepartmentAndOpenedAsSettings())
			->setPermissionPreset($permissionPreset)
			->addSelectedVariablesAlias(
				[
					UserDepartmentAndOpened::SELF,
					UserDepartmentAndOpened::DEPARTMENT,
					UserDepartmentAndOpened::SUBDEPARTMENTS,
					UserDepartmentAndOpened::OPEN,
					UserDepartmentAndOpened::ALL,
				],
				Loc::getMessage('CRM_SECURITY_ROLE_PERMS_TYPE_X'),
			)
		;

		return self::createCrmEntityPreset(
			$variants,
			$dependentVariablesAsSettingsWithoutTeams,
			$withObserversWrite,
			$withObserversWriteByStages,
		);
	}

	public static function crmEntityPresetAutomation(
		bool $withTeams = true,
		?string $inheritDescription = null,
		bool $withObserversWrite = false,
		bool $withObserversWriteByStages = false,
	): array
	{
		return array_merge(
			$withTeams
					? self::crmEntityPreset($inheritDescription, $withObserversWrite, $withObserversWriteByStages)
					: self::crmEntityPresetWithoutTeams(
						$inheritDescription,
						$withObserversWrite,
						$withObserversWriteByStages,
					),
			[
				new Automation(self::readWrite()),
			]
		);
	}

	public static function crmEntityKanbanHideSum(): array
	{
		$control = (new Variables())->addAttrMapping(HideSum::INHERIT, null);

		return [
			new HideSum(self::hideSum(), $control),
		];
	}

	public static function crmStageTransition(array $stages = [], ?string $inheritDescription = null): array
	{
		$stageIds = array_keys($stages);

		$variants = new Variants();
		$variants->add(
			Transition::TRANSITION_INHERIT,
			(string)Loc::getMessage('CRM_SECURITY_ROLE_PERMS_TYPE_TRANSITION_INHERITED_MSGVER_1'),
			[
				'conflictsWith' => array_merge($stageIds, [Transition::TRANSITION_ANY, Transition::TRANSITION_BLOCKED]),
				'hideInSection' => true,
				'useAsEmptyInSubsection' => true,
				'secondary' => true,
				'isUseGroupHeadValuesInHint' => true,
				'preset' => [
					'icon' => Outline::STAGES->value,
					'description' => $inheritDescription,
					'showGroupHeadItems' => true,
				],
			]
		);
		$variants->add(
			Transition::TRANSITION_ANY,
			(string)Loc::getMessage('CRM_SECURITY_ROLE_PERMS_TYPE_TRANSITION_ANY_MSGVER_2'),
			[
				'conflictsWith' => array_merge(
					$stageIds,
					[Transition::TRANSITION_INHERIT, Transition::TRANSITION_BLOCKED],
				),
				'defaultInSection' => (new Transition())->getDefaultSettings() === [Transition::TRANSITION_ANY],
				'preset' => [
					'icon' => Outline::STAGES->value,
					'description' => (string)Loc::getMessage('CRM_SECURITY_ROLE_PERMS_TYPE_TRANSITION_ANY_TAB_DESCRIPTION'),
					'showGroupHeadItems' => false,
				],
			]
		);
		$variants->add(
			Transition::TRANSITION_BLOCKED,
			(string)Loc::getMessage('CRM_SECURITY_ROLE_PERMS_TYPE_TRANSITION_BLOCKED_MSGVER_1'),
			[
				'conflictsWith' => array_merge($stageIds, [Transition::TRANSITION_ANY, Transition::TRANSITION_INHERIT]),
				'useAsEmptyInSection' => true,
				'useAsNothingSelectedInSubsection' => true,
				'defaultInSection' => (new Transition())->getDefaultSettings() === [Transition::TRANSITION_BLOCKED],
			]
		);
		foreach ($stages as $stageId => $stageName)
		{
			$variants->add(
				$stageId,
				$stageName,
				[
					'hideInSubsection' => $stageId,
					'conflictsWith' => [
						Transition::TRANSITION_ANY,
						Transition::TRANSITION_INHERIT,
						Transition::TRANSITION_BLOCKED,
					],
				]
			);
		}

		return [
			new Transition($variants),
		];
	}

	/**
	 * Builds the localized "stage inherits access rights..." description for the
	 * given entity. Entity/funnel names are user-defined, so they are escaped and
	 * wrapped in <b>; the surrounding phrase is a controlled localization string.
	 * The result is rendered via v-html on the frontend.
	 */
	public static function stageInheritDescription(
		?int $entityTypeId,
		?string $entityTitle,
		?string $funnelName,
	): string
	{
		$prefix = 'CRM_SECURITY_ROLE_PERMS_TYPE_TRANSITION_INHERITED_TAB_DESCRIPTION';
		$hasFunnel = $funnelName !== null && $funnelName !== '';

		// entityTypeId => [key suffix, supports the "in funnel #FUNNEL#" variant]
		$plainEntities = [
			\CCrmOwnerType::Deal => ['_DEAL', true],
			\CCrmOwnerType::SmartInvoice => ['_INVOICE', true],
			\CCrmOwnerType::Lead => ['_LEAD', false],
			\CCrmOwnerType::Quote => ['_QUOTE', false],
		];

		if ($entityTypeId !== null && isset($plainEntities[$entityTypeId]))
		{
			[$suffix, $supportsFunnel] = $plainEntities[$entityTypeId];
			$messageId = $prefix . $suffix . ($hasFunnel && $supportsFunnel ? '_FUNNEL' : '');
		}
		elseif ($entityTypeId !== null && \CCrmOwnerType::isPossibleDynamicTypeId($entityTypeId))
		{
			$hasTitle = $entityTitle !== null && $entityTitle !== '';
			$messageId = $prefix . match (true) {
				!$hasTitle => '_DYNAMIC',
				$hasFunnel => '_DYNAMIC_NAMED_FUNNEL',
				default => '_DYNAMIC_NAMED',
			};
		}
		else
		{
			$messageId = $prefix;
		}

		return (string)Loc::getMessage($messageId, [
			'#ENTITY#' => self::boldName($entityTitle),
			'#FUNNEL#' => self::boldName($funnelName),
		]);
	}

	private static function boldName(?string $value): string
	{
		return '<b>' . htmlspecialcharsbx((string)$value) . '</b>';
	}

	public static function userHierarchy(): Variants
	{
		$variants = Variants::createFromArray([
			BX_CRM_PERM_SELF => (string)GetMessage('CRM_SECURITY_ROLE_PERMS_TYPE_A'),
			BX_CRM_PERM_DEPARTMENT => (string)GetMessage('CRM_SECURITY_ROLE_PERMS_TYPE_D'),
			BX_CRM_PERM_SUBDEPARTMENT => (string)GetMessage('CRM_SECURITY_ROLE_PERMS_TYPE_F'),
			BX_CRM_PERM_ALL => (string)GetMessage('CRM_SECURITY_ROLE_PERMS_TYPE_X'),
		]);

		$variants->add(
			'',
			(string)GetMessage('CRM_SECURITY_ROLE_PERMS_TYPE_'),
			[
				'useAsEmptyInSection' => true,
			]
		);
		$variants->moveToTopOfList('');

		return $variants;
	}

	public static function userHierarchyAndOpen(): Variants
	{
		$variants = Variants::createFromArray([
			BX_CRM_PERM_SELF => (string)GetMessage('CRM_SECURITY_ROLE_PERMS_TYPE_A'),
			BX_CRM_PERM_DEPARTMENT => (string)GetMessage('CRM_SECURITY_ROLE_PERMS_TYPE_D'),
			BX_CRM_PERM_SUBDEPARTMENT => (string)GetMessage('CRM_SECURITY_ROLE_PERMS_TYPE_F'),
			BX_CRM_PERM_OPEN => (string)GetMessage('CRM_SECURITY_ROLE_PERMS_TYPE_O'),
			BX_CRM_PERM_ALL => (string)GetMessage('CRM_SECURITY_ROLE_PERMS_TYPE_X'),
		]);

		$variants->add(
			'',
			(string)GetMessage('CRM_SECURITY_ROLE_PERMS_TYPE_'),
			[
				'useAsEmptyInSection' => true,
			]
		);
		$variants->moveToTopOfList('');

		return $variants;
	}

	public static function switchAll(): Variants
	{
		$variants = new Variants();

		$variants->add(
			'',
			(string)GetMessage('CRM_SECURITY_ROLE_PERMS_TYPE_'),
			[
				'useAsEmptyInSection' => true,
			]
		);

		$variants->add(
			BX_CRM_PERM_ALL,
			(string)GetMessage('CRM_SECURITY_ROLE_PERMS_TYPE_X'),
		);

		return $variants;
	}

	private static function hideSum(): Variants
	{
		$variants = new Variants();

		$variants->add(
			'',
			(string)GetMessage('CRM_SECURITY_ROLE_PERMS_HIDE_SUM'),
			[
				'useAsEmptyInSection' => true,
				'useAsNothingSelectedInSubsection' => true,
				'defaultInSection' => (new HideSum())->getDefaultAttribute() === UserPermissions::PERMISSION_NONE,
			],
		);

		$variants->add(
			BX_CRM_PERM_ALL,
			(string)GetMessage('CRM_SECURITY_ROLE_PERMS_SHOW_SUM'),
			['defaultInSection' => (new HideSum())->getDefaultAttribute() === UserPermissions::PERMISSION_ALL],
		);

		$variants->add(
			HideSum::INHERIT,
			(string)Loc::getMessage('CRM_SECURITY_ROLE_PERMS_HIDE_SUM_INHERIT_MSGVER_1'),
			[
				'hideInSection' => true,
				'useAsEmptyInSubsection' => true,
				'isUseGroupHeadValuesInHint' => true,
			]
		);

		return $variants;
	}

	/**
	 * Variants of the observers write permission in a block where it is configured per stage:
	 * the block itself allows or forbids, a stage may also inherit the value of the block.
	 */
	private static function observersWriteWithStages(): Variants
	{
		$variants = new Variants();

		$variants->add(
			'',
			(string)Loc::getMessage('CRM_SECURITY_ROLE_PERMS_TYPE_ALLOWED_NO'),
			[
				'useAsEmptyInSection' => true,
				'useAsNothingSelectedInSubsection' => true,
				'defaultInSection' => (new ObserversWrite())->getDefaultAttribute() === UserPermissions::PERMISSION_NONE,
			],
		);

		$variants->add(
			BX_CRM_PERM_ALL,
			(string)Loc::getMessage('CRM_SECURITY_ROLE_PERMS_TYPE_ALLOWED_YES'),
			['defaultInSection' => (new ObserversWrite())->getDefaultAttribute() === UserPermissions::PERMISSION_ALL],
		);

		$variants->add(
			ObserversWrite::INHERIT,
			// this control mirrors HideSum; reuse its already-localized "inherit" label instead of a duplicate phrase
			(string)Loc::getMessage('CRM_SECURITY_ROLE_PERMS_HIDE_SUM_INHERIT_MSGVER_1'),
			[
				'hideInSection' => true,
				'useAsEmptyInSubsection' => true,
				'isUseGroupHeadValuesInHint' => true,
			],
		);

		return $variants;
	}

	public static function readWrite(): Variants
	{
		$variants = new Variants();

		$variants->add(
			'',
			(string)GetMessage('CRM_SECURITY_ROLE_PERMS_TYPE_AUTOMATION_NONE_MSGVER_1'),
			['useAsEmptyInSection' => true],
		);

		$variants->add(BX_CRM_PERM_ALL, (string)GetMessage('CRM_SECURITY_ROLE_PERMS_TYPE_AUTOMATION_ALL'));

		return $variants;
	}

	public static function allowedYesNo(): Variants
	{
		$variants = new Variants();

		$variants->add(
			'',
			(string)GetMessage('CRM_SECURITY_ROLE_PERMS_TYPE_ALLOWED_NO'),
			['useAsEmptyInSection' => true],
		);

		$variants->add(BX_CRM_PERM_ALL, (string)GetMessage('CRM_SECURITY_ROLE_PERMS_TYPE_ALLOWED_YES'));

		return $variants;
	}

	private static function createCrmEntityPreset(
		Variants $variants,
		BaseControlMapper $controlMapper,
		bool $withObserversWrite,
		bool $withObserversWriteByStages,
	): array
	{
		return [
			new Read($variants, $controlMapper),
			new Add($variants, $controlMapper),
			new Write($variants, $controlMapper),
			// observers write is a sub-case of write, so it follows write and its per-stage rows
			...($withObserversWrite ? [self::createObserversWrite($withObserversWriteByStages)] : []),
			new Delete($variants, $controlMapper),
			new Export($variants, $controlMapper),
			new Import($variants, $controlMapper),
			new MyCardView(self::allowedYesNo(), (new Toggler())->setDefaultValue(
				(new MyCardView())->getDefaultAttribute() === UserPermissions::PERMISSION_ALL,
			)),
		];
	}

	private static function createObserversWrite(bool $canAssignPermissionToStages): ObserversWrite
	{
		if ($canAssignPermissionToStages)
		{
			return new ObserversWrite(
				self::observersWriteWithStages(),
				(new Variables())
					->addAttrMapping(ObserversWrite::INHERIT, null)
					// "allow for all roles" must allow; the attribute-level max stays "none" to keep presets off
					->setMaxValue(UserPermissions::PERMISSION_ALL),
				canAssignPermissionToStages: true,
			);
		}

		return new ObserversWrite(self::allowedYesNo(), (new Toggler())->setDefaultValue(
			(new ObserversWrite())->getDefaultAttribute() === UserPermissions::PERMISSION_ALL,
		));
	}
}
