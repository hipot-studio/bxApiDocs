<?php

namespace Bitrix\BIConnector\Access\Component;

use Bitrix\BIConnector\Access\Component\PermissionConfig\RoleMembersInfo;
use Bitrix\BIConnector\Access\Component\PermissionSection\SectionSet;
use Bitrix\BIConnector\Access\Permission\PermissionDictionary;
use Bitrix\BIConnector\Access\Permission\PermissionTable;
use Bitrix\BIConnector\Access\Role\RoleDictionary;
use Bitrix\BIConnector\Access\Role\RoleUtil;
use Bitrix\BIConnector\Public\Features\PublicLinksControl;
use Bitrix\Main\Config\Feature as FeatureFlag;
use Bitrix\Main\Localization\Loc;

final class PermissionConfig
{
	public const SECTION_MAIN_RIGHTS = 'SECTION_RIGHTS_MAIN';
	public const SECTION_DASHBOARD_GROUP_RIGHTS = 'SECTION_RIGHTS_GROUP';
	public const SECTION_TABLE_GROUP_RIGHTS = 'SECTION_RIGHTS_TABLE_GROUP';

	/**
	 * The matrix is built of the sections the person may edit, all of them at once: the screen is
	 * one, and a section he holds no right of is not a part of his screen.
	 */
	public function __construct(
		private readonly SectionSet $sections,
	)
	{
	}

	/**
	 * Access rights of the sections the config was built for, in the order of the screen.
	 *
	 * @return array in format of ExternalAccessRightSection type of BX.UI.AccessRights.V2.App.
	 */
	public function getAccessRights(): array
	{
		$result = [];
		foreach ($this->sections->getSections() as $section)
		{
			$item = [
				'sectionCode' => $section->getCode(),
				'sectionTitle' => $section->getTitle(),
				'sectionHint' => $section->getHint(),
				'rights' => $section->getRights(),
			];

			if ($section->canAddGroup())
			{
				$item['action'] = [
					'buttonText' => Loc::getMessage('BICONNECTOR_CONFIG_PERMISSION_SECTION_BUTTON_ADD_GROUP'),
				];
			}

			$result[] = $item;
		}

		return $result;
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
				'accessRights' => $this->keepRightsOfSections($accessRights[$roleId] ?? []),
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
	 * Rows of a role the screen of this person draws.
	 *
	 * A row of a section outside his set is not the business of this matrix: he may not edit it,
	 * and carrying it here would return it in the payload of a save that never showed it.
	 *
	 * @param array $accessRights
	 *
	 * @return array
	 */
	private function keepRightsOfSections(array $accessRights): array
	{
		return array_values(
			array_filter(
				$accessRights,
				fn(array $accessRight): bool => $this->sections->ownsPermissionId($accessRight['id']),
			)
		);
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
