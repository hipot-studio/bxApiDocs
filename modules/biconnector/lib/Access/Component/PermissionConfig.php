<?php

namespace Bitrix\BIConnector\Access\Component;

use Bitrix\BIConnector\Access\Component\PermissionConfig\RoleMembersInfo;
use Bitrix\BIConnector\Access\Component\PermissionSection\SectionSet;
use Bitrix\BIConnector\Access\Permission\PermissionDictionary;
use Bitrix\BIConnector\Access\Permission\PermissionTable;
use Bitrix\BIConnector\Access\Role\RoleDictionary;
use Bitrix\BIConnector\Access\Role\RoleUtil;
use Bitrix\BIConnector\Access\Service\TableGroupMembershipService;
use Bitrix\BIConnector\Public\Command\TableCell\TableRights;
use Bitrix\BIConnector\Public\Provider\TableCell\TableCellProvider;
use Bitrix\BIConnector\Public\Features\PublicLinksControl;
use Bitrix\Main\Config\Feature as FeatureFlag;
use Bitrix\Main\Localization\Loc;

final class PermissionConfig
{
	public const SECTION_MAIN_RIGHTS = 'SECTION_RIGHTS_MAIN';
	public const SECTION_DASHBOARD_GROUP_RIGHTS = 'SECTION_RIGHTS_GROUP';
	public const SECTION_TABLE_GROUP_RIGHTS = 'SECTION_RIGHTS_TABLE_GROUP';

	/**
	 * The matrix draws the shown sections, all of them at once, and lets the person set up the editable
	 * ones: a shown section outside the editable set is drawn read only, so the person sees the values of
	 * the roles there and changes nothing.
	 *
	 * @param SectionSet $sections sections the screen draws, in the order of the screen
	 * @param SectionSet|null $editableSections sections the person may set up; null - every shown one
	 */
	public function __construct(
		private readonly SectionSet $sections,
		private readonly ?SectionSet $editableSections = null,
	)
	{
	}

	public function isSectionEditable(string $code): bool
	{
		return ($this->editableSections ?? $this->sections)->hasSection($code);
	}

	/**
	 * Access rights of the shown sections, in the order of the screen.
	 *
	 * @return array in format of ExternalAccessRightSection type of BX.UI.AccessRights.V2.App.
	 */
	public function getAccessRights(): array
	{
		$result = [];
		foreach ($this->sections->getSections() as $section)
		{
			$isReadOnly = !$this->isSectionEditable($section->getCode());

			$item = [
				'sectionCode' => $section->getCode(),
				'sectionTitle' => $section->getTitle(),
				'sectionHint' => $section->getHint(),
				'rights' => $isReadOnly ? $this->lockRows($section->getRights()) : $section->getRights(),
				'isReadOnly' => $isReadOnly,
			];

			if ($isReadOnly)
			{
				$item['readOnlyHint'] = (string)Loc::getMessage('BICONNECTOR_CONFIG_PERMISSION_SECTION_READ_ONLY_HINT');
			}
			elseif ($section->canAddGroup())
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
		$tableRowValues = $this->sections->hasSection(self::SECTION_TABLE_GROUP_RIGHTS)
			? $this->getTableRowValuesMap()
			: []
		;

		$roles = [];
		foreach (RoleUtil::getRoles() as $row)
		{
			$roleId = (int) $row['ID'];

			$roles[] = [
				'id' => $roleId,
				'title'  => RoleDictionary::getRoleName($row['NAME']),
				'accessRights' => [
					...$this->keepRightsOfSections($accessRights[$roleId] ?? []),
					...($tableRowValues[$roleId] ?? []),
				],
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
	 * Rows of a role in the sections the screen draws.
	 *
	 * The rows of a read only section are carried too, so that it shows the values; whether the save
	 * writes them is decided by the save, not by this matrix.
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
	 * Values of the table rows: an override of a table is shown in the row of the table under each of its groups.
	 * A table without an override has no value, its rows follow the groups.
	 *
	 * @return array in format `[roleId => [ [id => 'T<group>:<table>', value => ...], ... ]]`
	 */
	private function getTableRowValuesMap(): array
	{
		$groupPermissions = PermissionDictionary::getTableGroupPermissions();

		$result = [];
		foreach ((new TableCellProvider())->getOverrides() as $cell)
		{
			if ($cell->rights === null)
			{
				continue;
			}

			$values = $this->getTableRowValues($cell->rights);
			foreach (TableGroupMembershipService::getGroupIdsByTableCode($cell->tableCode) as $groupId)
			{
				if (!isset($groupPermissions[PermissionDictionary::getTableGroupPermissionId($groupId)]))
				{
					continue;
				}

				$rowId = PermissionDictionary::getTableRowPermissionId($groupId, $cell->tableCode);
				foreach ($values as $value)
				{
					$result[$cell->roleId][] = [
						'id' => $rowId,
						'value' => $value,
					];
				}
			}
		}

		return $result;
	}

	/**
	 * @param TableRights $rights
	 *
	 * @return string[]
	 */
	private function getTableRowValues(TableRights $rights): array
	{
		$values = [];
		if ($rights->view)
		{
			$values[] = (string)PermissionDictionary::BIC_TABLE_VIEW;
		}
		if ($rights->edit)
		{
			$values[] = (string)PermissionDictionary::BIC_TABLE_EDIT;
		}
		if ($rights->export)
		{
			$values[] = (string)PermissionDictionary::BIC_TABLE_EXPORT;
		}

		return $values ?: ['0'];
	}

	/**
	 * Rows of a read only section: none of them opens a popup or is deleted.
	 *
	 * The rows are copies, so the cached dictionary the access checks read stays as it is.
	 *
	 * @param array $rights
	 *
	 * @return array
	 */
	private function lockRows(array $rights): array
	{
		foreach ($rights as $index => $right)
		{
			$rights[$index]['isClickable'] = false;
			$rights[$index]['isDeletable'] = false;
		}

		return $rights;
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
