<?php

namespace Bitrix\BIConnector\Access\Install;

use Bitrix\BIConnector\Access\Service\TableGroupLocalizationService;
use Bitrix\BIConnector\Access\Service\TableGroupService;
use Bitrix\BIConnector\Access\TableGroup\SystemTableGroupMap;
use Bitrix\BIConnector\Access\TableGroup\TableGroupTable;
use Bitrix\BIConnector\Integration\Superset\CultureFormatter;
use Bitrix\BIConnector\Superset\Logger\Logger;
use Bitrix\Main\Result;

/**
 * Creates the system table groups declared by SystemTableGroupMap.
 *
 * Creation only: a group that already exists is left alone, position included. Until the declared
 * order changes there is nothing to repair, and the release that reorders the declaration calls
 * restoreDeclaredOrder() for the existing rows - see .ai/knowledge-base/notes/table-groups-map.md.
 *
 * Only the groups themselves: the bindings that put a table into a group are written by
 * TableGroupBindingInstaller, which runs right after this one. The two are not merged on purpose -
 * the groups have to exist before anything is bound to them, and a portal whose catalog is silent
 * still gets its groups.
 *
 * The run is idempotent and is not wrapped into a transaction as a whole: an interrupted run is
 * finished by the next call.
 */
final class TableGroupInstaller
{
	public static function install(): Result
	{
		$result = new Result();

		$existingGroups = TableGroupTable::getList([
			'select' => ['CODE'],
			'filter' => ['=TYPE' => TableGroupTable::GROUP_TYPE_SYSTEM],
		])
			->fetchAll()
		;

		$existingCodes = array_flip(array_column($existingGroups, 'CODE'));

		// The names follow the reports language setting, not the language of the current hit.
		$language = CultureFormatter::getLanguageCode();

		foreach (SystemTableGroupMap::getSystemGroupCodes() as $groupCode)
		{
			if (isset($existingCodes[$groupCode]))
			{
				continue;
			}

			$createResult = TableGroupService::createSystemGroup(
				$groupCode,
				self::getDefaultGroupName($groupCode, $language),
				SystemTableGroupMap::getSystemGroupSort($groupCode),
			);
			if (!$createResult->isSuccess())
			{
				$result->addErrors($createResult->getErrors());
			}
		}

		if (!$result->isSuccess())
		{
			// The write boundary turns a failed query into a soft error, and every caller of the run
			// is a lifecycle point that ignores the result, so without this the portal keeps silent
			// about being left without the user tables group.
			Logger::logErrors($result->getErrors(), ['source' => 'table_group_install']);
		}

		return $result;
	}

	/**
	 * Brings the positions of the existing system groups to the declared order.
	 *
	 * The repair a release brings when it changes the declaration: install() creates a new group with
	 * its declared position and leaves the existing ones where they are, so a group added ahead of the
	 * user tables group would otherwise share its position with it. Nothing but the installer writes
	 * the position of a system group, so a position that differs from the declaration is a stale one.
	 * Only the rows that differ are written, so a repeated call writes nothing.
	 */
	public static function restoreDeclaredOrder(): Result
	{
		$result = new Result();

		$groups = TableGroupTable::getList([
			'select' => ['ID', 'CODE', 'SORT'],
			'filter' => ['=TYPE' => TableGroupTable::GROUP_TYPE_SYSTEM],
		])
			->fetchAll()
		;

		$systemGroupCodes = SystemTableGroupMap::getSystemGroupCodes();
		foreach ($groups as $group)
		{
			if (!in_array($group['CODE'], $systemGroupCodes, true))
			{
				continue;
			}

			$sort = SystemTableGroupMap::getSystemGroupSort($group['CODE']);
			if ((int)$group['SORT'] === $sort)
			{
				continue;
			}

			$updateResult = TableGroupTable::update((int)$group['ID'], ['SORT' => $sort]);
			if (!$updateResult->isSuccess())
			{
				$result->addErrors($updateResult->getErrors());
			}
		}

		if (!$result->isSuccess())
		{
			Logger::logErrors($result->getErrors(), ['source' => 'table_group_install']);
		}

		return $result;
	}

	public static function getDefaultGroupName(string $groupCode, ?string $language = null): string
	{
		return TableGroupLocalizationService::getGroupName($groupCode, $language);
	}
}
