<?php

namespace Bitrix\BIConnector\Access\Component;

use Bitrix\BIConnector\Access\AccessController;
use Bitrix\BIConnector\Access\ActionDictionary;
use Bitrix\BIConnector\Access\Permission\PermissionDictionary;
use Bitrix\BIConnector\Access\Role\RoleDictionary;
use Bitrix\BIConnector\Access\Role\RoleRelationTable;
use Bitrix\BIConnector\Access\Role\RoleUtil;

/**
 * The roles themselves are managed by the general right of the rights screen and by nothing else.
 *
 * The screen is one for everybody, and the rows of the axes are zoned by the sections of the person.
 * The roles are not: they are common to all the sections, so a person holding the right of a single
 * axis edits the rights inside the existing roles and does not create, rename, delete them or change
 * who is in them. The matrix of ui.accessrights.v2 has no way to hide these controls, so his screen
 * does offer them; a request that actually changes a role is therefore refused as a whole, and a
 * request that carries the roles as they are stored goes through.
 *
 * The portal administrator passes the check of the general right itself, so he is not named apart.
 */
final class RoleManagementGuard
{
	/**
	 * Whether the payload may be applied as it came.
	 *
	 * @param array[] $userGroups roles of the payload
	 * @param string[] $deletedUserGroups roles the payload removes
	 */
	public function check(array $userGroups, array $deletedUserGroups): bool
	{
		if (!$this->isManagementRequested($userGroups, $deletedUserGroups))
		{
			return true;
		}

		return $this->canManageRoles();
	}

	public function canManageRoles(): bool
	{
		return AccessController::getCurrent()->check(ActionDictionary::ACTION_BIC_SETTINGS_EDIT_RIGHTS);
	}

	/**
	 * Rows of the rights that open the screen, dropped unless the person holds the general right.
	 *
	 * These rows belong to the section of the module rights, so the zoning of the payload drops them
	 * already. The check is made once more by the rights themselves: who may hand out the screen must
	 * not depend on which section a right happens to be drawn in.
	 *
	 * @param array[] $accessRights rows of the matrix, each with its permission id
	 *
	 * @return array[]
	 */
	public function dropSettingRights(array $accessRights): array
	{
		if ($this->canManageRoles())
		{
			return $accessRights;
		}

		$settingIds = array_map('strval', self::getSettingPermissionIds());

		return array_values(array_filter(
			$accessRights,
			static fn($accessRight): bool => !in_array((string)($accessRight['id'] ?? ''), $settingIds, true),
		));
	}

	/**
	 * @return int[]
	 */
	private static function getSettingPermissionIds(): array
	{
		return [
			PermissionDictionary::BIC_SETTINGS_EDIT_RIGHTS,
			PermissionDictionary::BIC_SETTINGS_EDIT_TABLE_RIGHTS,
			PermissionDictionary::BIC_SECTION_EDIT_DASHBOARD_RIGHTS,
		];
	}

	/**
	 * Whether the payload changes the roles themselves and not only the rights inside them.
	 *
	 * The comparison is made against the stored state and not against the shape of the request: the
	 * screen of any person carries every role with its name and its members, so the presence of
	 * these keys says nothing - what says it is a value that differs from the saved one.
	 *
	 * @param array[] $userGroups
	 * @param string[] $deletedUserGroups
	 */
	private function isManagementRequested(array $userGroups, array $deletedUserGroups): bool
	{
		if (!empty($deletedUserGroups))
		{
			return true;
		}

		$storedTitles = $this->getStoredRoleTitles();
		$storedMembers = $this->getStoredRoleMembers();

		foreach ($userGroups as $userGroup)
		{
			$roleId = (int)($userGroup['id'] ?? 0);
			// a role with no id of the storage behind it is a role the payload creates
			if ($roleId <= 0 || !isset($storedTitles[$roleId]))
			{
				return true;
			}

			$title = (string)($userGroup['title'] ?? '');
			if ($title !== $storedTitles[$roleId])
			{
				return true;
			}

			if ($this->areMembersChanged($storedMembers[$roleId] ?? [], $userGroup))
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * The members are written by the whole list: the save deletes the relations of the role and puts
	 * the codes of the payload in their place, so a request without them empties the role as surely
	 * as a request with other codes replaces it.
	 *
	 * @param string[] $storedCodes
	 * @param array $userGroup
	 */
	private function areMembersChanged(array $storedCodes, array $userGroup): bool
	{
		$payloadCodes = [];
		if (isset($userGroup['accessCodes']) && is_array($userGroup['accessCodes']))
		{
			$payloadCodes = array_map('strval', array_keys($userGroup['accessCodes']));
		}

		$storedCodes = self::normalizeMemberCodes($storedCodes);
		$payloadCodes = self::normalizeMemberCodes($payloadCodes);

		return $storedCodes !== $payloadCodes;
	}

	/**
	 * The screen knows no IU: ui.accessrights.v2 turns IU<n> into U<n> on load, so a stored IU code
	 * compared as is would read as a changed list on every save.
	 *
	 * @param string[] $codes
	 *
	 * @return string[]
	 */
	private static function normalizeMemberCodes(array $codes): array
	{
		$codes = array_unique(preg_replace('/^IU(\d+)$/', 'U$1', $codes));
		sort($codes);

		return $codes;
	}

	/**
	 * Titles of the stored roles the way the screen was given them: the name of a system role is
	 * drawn by its phrase, and that very phrase comes back in the payload.
	 *
	 * @return array<int, string>
	 */
	private function getStoredRoleTitles(): array
	{
		$titles = [];
		foreach (RoleUtil::getRoles() as $row)
		{
			$titles[(int)$row['ID']] = RoleDictionary::getRoleName((string)$row['NAME']);
		}

		return $titles;
	}

	/**
	 * Codes of the members and not their metadata: the question here is whether the list has
	 * changed, and the names behind the codes are the business of the screen.
	 *
	 * @return array<int, string[]> codes of every role that has members
	 */
	private function getStoredRoleMembers(): array
	{
		$members = [];
		$rows = RoleRelationTable::getList([
			'select' => ['ROLE_ID', 'RELATION'],
		]);
		foreach ($rows as $row)
		{
			$members[(int)$row['ROLE_ID']][] = (string)$row['RELATION'];
		}

		return $members;
	}
}
