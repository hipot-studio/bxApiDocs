<?php

namespace Bitrix\BIConnector\Access\Update\PermissionSections;

use Bitrix\BIConnector\Access\Permission\PermissionDictionary;
use Bitrix\BIConnector\Access\Permission\PermissionTable;
use Bitrix\BIConnector\Access\Role\RoleTable;
use Bitrix\BIConnector\Access\Role\RoleUtil;
use Bitrix\BIConnector\Access\TableGroup\SystemTableGroupMap;
use Bitrix\BIConnector\Access\TableGroup\TableGroupTable;
use Bitrix\BIConnector\Superset\Logger\Logger;
use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;

/**
 * Hands the rights the split of the screen brought with it over to the roles of a living portal.
 *
 * The screen is one and the roles are common to it, so nothing is copied and no role moves anywhere:
 * what the sections brought is new rights, and a right nobody holds closes to everybody what the
 * portal could do the day before.
 *
 * Two of them are handed out here. The right of the section of the report groups goes to whoever
 * configured the rights before the split. The axis of the tables goes to every role of the portal,
 * in full, on every system group: the standard roles grant no operation of it at all, and a portal
 * that got the axis without this would find every table closed to everybody.
 *
 * Both steps are marked by options of the module and not by the data they write. A right taken away
 * on the screen deletes its row, so "the row is absent" would mean both "it was never granted" and
 * "a person has taken it away" - and the second pass would hand back what somebody has just
 * refused. What the mark answers is only the first question.
 */
final class Migrator
{
	public const AGENT_NAME = 'Bitrix\\BIConnector\\Access\\Update\\PermissionSections\\Migrator::runAgent();';

	private const AGENT_INTERVAL = 86400;

	private const LOG_SOURCE = 'permission_sections_transition';

	/**
	 * Codes of the system table groups the transition has already granted to the roles.
	 */
	private const GRANTED_GROUPS_OPTION = '~permission_sections_granted_table_groups';

	/**
	 * Mark of the one pass that handed the right of the report section out.
	 */
	private const DASHBOARD_RIGHT_OPTION = '~permission_sections_dashboard_right_granted';

	/**
	 * Puts the agent of the postponed step into the schedule, once.
	 */
	public static function schedule(): void
	{
		$existing = \CAgent::GetList([], [
			'NAME' => self::AGENT_NAME,
			'MODULE_ID' => 'biconnector',
		])->Fetch();

		if ($existing !== false)
		{
			return;
		}

		\CAgent::AddAgent(self::AGENT_NAME, 'biconnector', 'N', self::AGENT_INTERVAL);
	}

	/**
	 * @return string Own name to stay in the schedule, an empty string to be removed from it.
	 */
	public static function runAgent(): string
	{
		if (!Loader::includeModule('biconnector'))
		{
			return self::AGENT_NAME;
		}

		$result = (new self())->migrate();
		if (!$result->isSuccess() || ($result->getData()['isPostponed'] ?? false))
		{
			return self::AGENT_NAME;
		}

		return '';
	}

	public static function run(): Result
	{
		return (new self())->migrate();
	}

	private function migrate(): Result
	{
		$result = new Result();

		$rightResult = $this->grantDashboardSectionRight();
		if (!$rightResult->isSuccess())
		{
			$result->addErrors($rightResult->getErrors());
			self::log(implode('; ', $rightResult->getErrorMessages()));
			self::schedule();

			return $result->setData(['isPostponed' => true, 'grantedCodes' => []]);
		}

		$grantResult = $this->grantTableGroupRights();
		$result->addErrors($grantResult->getErrors());
		$result->setData($grantResult->getData());

		if ($grantResult->getData()['isPostponed'] ?? false)
		{
			// The step that could not be done in this hit is not dropped: the agent comes back for it.
			self::schedule();
		}

		return $result;
	}

	/**
	 * The right of the report section goes to whoever managed the rights before the split.
	 *
	 * The general right stays where it is: after the split it means the rights screen itself, and the
	 * right of the table section is granted by the release of that axis and not from here.
	 */
	private function grantDashboardSectionRight(): Result
	{
		$result = new Result();

		if (Option::get('biconnector', self::DASHBOARD_RIGHT_OPTION, 'N') === 'Y')
		{
			return $result;
		}

		$rows = [];
		foreach ($this->fetchRoleIdsWithRight(PermissionDictionary::BIC_SETTINGS_EDIT_RIGHTS) as $roleId)
		{
			if ($this->hasRight($roleId, PermissionDictionary::BIC_SECTION_EDIT_DASHBOARD_RIGHTS))
			{
				continue;
			}

			$rows[] = [
				'ROLE_ID' => $roleId,
				'PERMISSION_ID' => (string)PermissionDictionary::BIC_SECTION_EDIT_DASHBOARD_RIGHTS,
				'VALUE' => 1,
			];
		}

		$connection = Application::getInstance()->getConnection();
		$connection->startTransaction();

		try
		{
			RoleUtil::insertPermissions($rows);
			Option::set('biconnector', self::DASHBOARD_RIGHT_OPTION, 'Y');
		}
		catch (\Throwable $exception)
		{
			$connection->rollbackTransaction();

			return $result->addError(new Error($exception->getMessage()));
		}

		$connection->commitTransaction();

		return $result;
	}

	/**
	 * Every operation of the table axis, on every system group, to every role of the portal.
	 *
	 * The mark keeps the codes of the groups the grant has already covered, and a code once written
	 * there is never granted a second time.
	 *
	 * The codes and not the identifiers: the codes are declared by the map and are the same on every
	 * portal, so "the whole map is covered" is a question with one answer, while the identifiers are
	 * each portal's own. A group created by the installer of the axis after this pass is caught up by
	 * the next one; a group somebody creates by hand gets nothing, and the same mark tells them
	 * apart - a custom group is no part of the map.
	 *
	 * @return Result Data: ['isPostponed' => bool, 'grantedCodes' => string[]].
	 */
	public function grantTableGroupRights(): Result
	{
		$result = new Result();

		$grantedCodes = self::getGrantedGroupCodes();
		$pendingGroups = $this->fetchSystemGroupsToGrant($grantedCodes);
		$newlyGranted = [];

		if (!empty($pendingGroups))
		{
			$roleIds = $this->fetchRoleIds([]);
			if (empty($roleIds))
			{
				// A portal without a single role is a portal in the middle of its installation: marking
				// the groups as covered now would leave the roles created after this pass without the
				// axis for good.
				self::log('The portal has no role to grant the table axis to, the grant waits');

				return $result->setData(['isPostponed' => true, 'grantedCodes' => []]);
			}

			foreach ($pendingGroups as $groupId => $groupCode)
			{
				$groupResult = $this->grantGroupToRoles($groupId, $groupCode, $roleIds);
				if (!$groupResult->isSuccess())
				{
					$result->addErrors($groupResult->getErrors());
					self::log(implode('; ', $groupResult->getErrorMessages()));

					return $result->setData(['isPostponed' => true, 'grantedCodes' => $newlyGranted]);
				}

				$grantedCodes[] = $groupCode;
				$newlyGranted[] = $groupCode;
			}
		}

		// The agent leaves the schedule only when every group of the map is behind: on a portal where
		// the installer of the axis has not created them all yet the rest is caught up later.
		$isPostponed = array_diff(SystemTableGroupMap::getSystemGroupCodes(), $grantedCodes) !== [];
		if ($isPostponed)
		{
			self::log('The portal has no system group of tables yet, the grant of the table axis waits');
		}

		return $result->setData(['isPostponed' => $isPostponed, 'grantedCodes' => $newlyGranted]);
	}

	/**
	 * The rows of one group and the mark of its code, written as one: a mark without the rows would
	 * close the group to everybody for good, and the rows without the mark would be written again
	 * after an administrator takes them away.
	 *
	 * @param int[] $roleIds
	 */
	private function grantGroupToRoles(int $groupId, string $groupCode, array $roleIds): Result
	{
		$result = new Result();

		$permissionId = PermissionDictionary::getTableGroupPermissionId($groupId);
		$rows = $this->buildGrantRows($permissionId, $roleIds);

		$connection = Application::getInstance()->getConnection();
		$connection->startTransaction();

		try
		{
			RoleUtil::insertPermissions($rows);
			self::rememberGrantedGroupCode($groupCode);
		}
		catch (\Throwable $exception)
		{
			$connection->rollbackTransaction();

			return $result->addError(new Error($exception->getMessage()));
		}

		$connection->commitTransaction();

		return $result;
	}

	/**
	 * A pair the role already carries is not written twice: the storage keeps no unique index of its
	 * own, so a repeated row would show up in the matrix as a duplicated value.
	 *
	 * @param int[] $roleIds
	 *
	 * @return array[] Rows for RoleUtil::insertPermissions().
	 */
	private function buildGrantRows(string $permissionId, array $roleIds): array
	{
		$existing = [];
		$storedRows = PermissionTable::getList([
			'select' => ['ROLE_ID', 'VALUE'],
			'filter' => ['=PERMISSION_ID' => $permissionId],
		])->fetchAll();
		foreach ($storedRows as $storedRow)
		{
			$existing[(int)$storedRow['ROLE_ID'] . ':' . (int)$storedRow['VALUE']] = true;
		}

		$rows = [];
		foreach ($roleIds as $roleId)
		{
			foreach (PermissionDictionary::getTablePermissionCodes() as $operation)
			{
				if (isset($existing[$roleId . ':' . $operation]))
				{
					continue;
				}

				$rows[] = [
					'ROLE_ID' => $roleId,
					'PERMISSION_ID' => $permissionId,
					'VALUE' => $operation,
				];
			}
		}

		return $rows;
	}

	/**
	 * @param string[] $grantedCodes
	 *
	 * @return array<int, string> Identifier of the group => its code.
	 */
	private function fetchSystemGroupsToGrant(array $grantedCodes): array
	{
		$granted = array_flip($grantedCodes);

		$groups = TableGroupTable::getList([
			'select' => ['ID', 'CODE'],
			'filter' => ['=TYPE' => TableGroupTable::GROUP_TYPE_SYSTEM],
		])->fetchAll();

		$pending = [];
		foreach ($groups as $group)
		{
			$code = (string)$group['CODE'];
			if (isset($granted[$code]))
			{
				continue;
			}

			$pending[(int)$group['ID']] = $code;
		}

		return $pending;
	}

	/**
	 * @return string[] Codes of the groups the grant has already covered.
	 */
	public static function getGrantedGroupCodes(): array
	{
		$stored = Option::get('biconnector', self::GRANTED_GROUPS_OPTION, '');
		if ($stored === '')
		{
			return [];
		}

		$codes = json_decode($stored, true);

		return is_array($codes) ? array_values(array_map('strval', $codes)) : [];
	}

	private static function rememberGrantedGroupCode(string $groupCode): void
	{
		$codes = self::getGrantedGroupCodes();
		$codes[] = $groupCode;

		Option::set('biconnector', self::GRANTED_GROUPS_OPTION, json_encode(array_values(array_unique($codes))));
	}

	/**
	 * Forgets what the transition has done.
	 *
	 * The reinstallation of the module drops the roles, the rights and the groups of tables alike, so
	 * the portal after it is a portal before the transition: a mark left over would leave the newly
	 * created roles and groups without the rights the transition hands out.
	 */
	public static function forgetGrantedGroupCodes(): void
	{
		Option::delete('biconnector', ['name' => self::GRANTED_GROUPS_OPTION]);
		Option::delete('biconnector', ['name' => self::DASHBOARD_RIGHT_OPTION]);
	}

	private static function log(string $reason): void
	{
		Logger::logWarning(
			[new Error($reason)],
			[
				'source' => self::LOG_SOURCE,
				'message' => 'The transition to the sections of the rights screen did not finish in this pass',
			],
		);
	}

	private function hasRight(int $roleId, int|string $permissionId): bool
	{
		$row = PermissionTable::getList([
			'select' => ['ROLE_ID'],
			'filter' => [
				'=ROLE_ID' => $roleId,
				'=PERMISSION_ID' => (string)$permissionId,
			],
			'limit' => 1,
		])->fetch();

		return $row !== false;
	}

	/**
	 * @return int[]
	 */
	private function fetchRoleIds(array $filter): array
	{
		$rows = RoleTable::getList([
			'select' => ['ID'],
			'filter' => $filter,
		])->fetchAll();

		return array_map('intval', array_column($rows, 'ID'));
	}

	/**
	 * @return int[] Roles holding the right with a value of one.
	 */
	private function fetchRoleIdsWithRight(int|string $permissionId): array
	{
		$rows = PermissionTable::getList([
			'select' => ['ROLE_ID'],
			'filter' => [
				'=PERMISSION_ID' => (string)$permissionId,
				'=VALUE' => 1,
			],
		])->fetchAll();

		return array_unique(array_map('intval', array_column($rows, 'ROLE_ID')));
	}
}
