<?php

namespace Bitrix\BIConnector\Access\Component;

use Bitrix\BIConnector\Access\Component\PermissionConfig\RoleMembersInfo;
use Bitrix\BIConnector\Access\Permission\PermissionDictionary;
use Bitrix\BIConnector\Access\Permission\PermissionTable;
use Bitrix\BIConnector\Access\Role\RoleDictionary;
use Bitrix\BIConnector\Access\Role\RoleUtil;
use Bitrix\BIConnector\Configuration\Feature;
use Bitrix\BIConnector\Public\Features\PublicLinksControl;
use Bitrix\Main\Config\Feature as FeatureFlag;
use Bitrix\Main\Localization\Loc;

final class PermissionConfig
{
	public const SECTION_MAIN_RIGHTS = 'SECTION_RIGHTS_MAIN';
	public const SECTION_DASHBOARD_GROUP_RIGHTS = 'SECTION_RIGHTS_GROUP';
	public const SECTION_TABLE_GROUP_RIGHTS = 'SECTION_RIGHTS_TABLE_GROUP';

	/** Sections a group row can be added to; both group axes are added by the same button. */
	private const GROUP_SECTION_CODES = [
		self::SECTION_DASHBOARD_GROUP_RIGHTS,
		self::SECTION_TABLE_GROUP_RIGHTS,
	];

	/**
	 * Access rights.
	 *
	 * @return array in format of ExternalAccessRightSection type of BX.UI.AccessRights.V2.App.
	 */
	public function getAccessRights(): array
	{
		$result = [];

		$sections = $this->getSections();

		foreach ($sections as $sectionCode => $permissions)
		{
			$rights = [];
			foreach ($permissions as $permissionId)
			{
				$rights[] = PermissionDictionary::getPermission($permissionId);
			}

			if ($sectionCode === self::SECTION_TABLE_GROUP_RIGHTS)
			{
				$rights = $this->withTableGroupSubtitles($rights);
			}

			$section = [
				'sectionCode' => $sectionCode,
				'sectionTitle' => Loc::getMessage("BICONNECTOR_CONFIG_PERMISSION_{$sectionCode}"),
				'sectionHint' => Loc::getMessage("HINT_BICONNECTOR_CONFIG_PERMISSION_{$sectionCode}"),
				'rights' => $rights,
			];

			if (in_array($sectionCode, self::GROUP_SECTION_CODES, true))
			{
				$section['action'] = [
					'buttonText' => Loc::getMessage('BICONNECTOR_CONFIG_PERMISSION_SECTION_BUTTON_ADD_GROUP'),
				];
			}

			$result[] = $section;
		}

		return $result;
	}

	/**
	 * Adds the caption to every row of the table axis.
	 *
	 * The caption costs a read of the whole table catalog, so the dictionary builds it only when
	 * asked: the slider is the only reader that shows it.
	 *
	 * @param array $rights
	 *
	 * @return array
	 */
	private function withTableGroupSubtitles(array $rights): array
	{
		$subtitles = PermissionDictionary::getTableGroupSubtitles();

		foreach ($rights as $index => $right)
		{
			$rights[$index]['subtitle'] = $subtitles[$right['id']] ?? '';
		}

		return $rights;
	}

	/**
	 * Get saved user roles.
	 *
	 * @return array in format of ExternalUserGroup type of BX.UI.AccessRights.V2.App.
	 */
	public function getUserGroups(): array
	{
		$members = $this->getRoleMembersMap();
		$accessRights = $this->getRoleAccessRightsMap();

		$roles = [];
		foreach (RoleUtil::getRoles() as $row)
		{
			$roleId = (int) $row['ID'];

			$roles[] = [
				'id' => $roleId,
				'title'  => RoleDictionary::getRoleName($row['NAME']),
				'accessRights' => $accessRights[$roleId] ?? [],
				'members' => $members[$roleId] ?? [],
			];
		}

		return $roles;
	}

	/**
	 * Rights the editor does not show right now: the matrix it sends back knows nothing of them,
	 * so the save has to keep their stored values.
	 *
	 * @return string[]
	 */
	public static function getHiddenPermissionIds(): array
	{
		return FeatureFlag::isEnabled(PublicLinksControl::class) ? [] : [(string)PermissionDictionary::BIC_SHARE_CONTROL];
	}

	/**
	 * Get sections for view on rights settings page.
	 *
	 * @return array
	 */
	private function getSections(): array
	{
		$dashboardPermissions = PermissionDictionary::getDashboardGroupPermissions();

		$shareControlRights =
			in_array((string)PermissionDictionary::BIC_SHARE_CONTROL, self::getHiddenPermissionIds(), true)
				? []
				: [PermissionDictionary::BIC_SHARE_CONTROL]
		;

		$mainRights = [
			PermissionDictionary::BIC_ACCESS,
			PermissionDictionary::BIC_DASHBOARD_TAG_MODIFY,
			PermissionDictionary::BIC_DASHBOARD_SHARE,
			...$shareControlRights,
			PermissionDictionary::BIC_SETTINGS_ACCESS,
			PermissionDictionary::BIC_SETTINGS_EDIT_RIGHTS,
		];

		if (Feature::isTablePermissionsEnabled())
		{
			$mainRights[] = PermissionDictionary::BIC_SETTINGS_EDIT_TABLE_RIGHTS;
		}

		if (Feature::isExternalEntitiesEnabled())
		{
			$mainRights[] = PermissionDictionary::BIC_EXTERNAL_DASHBOARD_CONFIG;
			$mainRights[] = PermissionDictionary::BIC_DELETE_ALL_UNUSED_ELEMENTS;
		}

		$sections = [
			self::SECTION_MAIN_RIGHTS => $mainRights,
			self::SECTION_DASHBOARD_GROUP_RIGHTS => array_keys($dashboardPermissions),
		];

		if (Feature::isTablePermissionsEnabled())
		{
			$tablePermissions = PermissionDictionary::getTableGroupPermissions();
			$sections[self::SECTION_TABLE_GROUP_RIGHTS] = array_keys($tablePermissions);
		}

		return $sections;
	}

	/**
	 * All roles members.
	 *
	 * @return array
	 */
	private function getRoleMembersMap(): array
	{
		return (new RoleMembersInfo)->getMemberInfos();
	}

	/**
	 * All roles access rights.
	 *
	 * @return array in format `[roleId => [ [id => ..., value => ...], [id => ..., value => ...], ... ]]`
	 */
	private function getRoleAccessRightsMap(): array
	{
		$result = [];

		$rows = PermissionTable::getList([
			'select' => [
				'ROLE_ID',
				'PERMISSION_ID',
				'VALUE',
			],
		]);
		foreach ($rows as $row)
		{
			$roleId = $row['ROLE_ID'];

			$result[$roleId][] = [
				'id' => $row['PERMISSION_ID'],
				'value' => $row['VALUE']
			];
		}

		return $result;
	}
}
