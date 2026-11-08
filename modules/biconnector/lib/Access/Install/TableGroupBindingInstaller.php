<?php

namespace Bitrix\BIConnector\Access\Install;

use Bitrix\BIConnector\Access\Service\TableGroupService;
use Bitrix\BIConnector\Access\TableGroup\SystemTableGroupMap;
use Bitrix\BIConnector\Access\TableGroup\TableGroupBindingTable;
use Bitrix\BIConnector\Access\TableGroup\TableGroupTable;
use Bitrix\BIConnector\ExternalSource\SupersetServiceIntegration;
use Bitrix\BIConnector\Superset\Logger\Logger;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

/**
 * Puts every table of the catalog into a group: the ones the map declares into their own group,
 * the rest into the user tables group.
 *
 * Only a code that has no binding at all is arranged. A code bound anywhere is already arranged -
 * by an earlier run or by a person - and is left alone, addition included. That single rule covers
 * three requirements at once: the run repeats without changing anything, an interrupted run is
 * finished by the next call, and a deliberate edit of the composition is never undone. A run that
 * brought the group to the composition the map declares would satisfy the first two and break the
 * third: the release that adds one dataset would resurrect the table an administrator has taken out.
 *
 * The catalog is read fresh rather than kept: the kept list feeds reading, where a stale answer
 * errs towards refusal, while here it would err towards writing. An empty catalog is a refusal,
 * not an empty portal: the list is built by the data source events of other modules, so an empty
 * answer means nobody supplied sources on this hit as often as it means there are no tables.
 *
 * The run is not wrapped into a transaction as a whole, and nothing here deletes.
 *
 * Nothing here is allowed to break the caller either: the run stands inside the installation of the
 * access subsystem and inside an agent, and the catalog it reads is built by the data source events
 * of other modules, where a failure of any supplier arrives here as an exception. So every failure
 * ends in the result and in the module log, and the roles that are installed after this run are
 * installed anyway.
 */
final class TableGroupBindingInstaller
{
	public const ERROR_NO_USER_TABLES_GROUP = 'BICONNECTOR_TABLE_GROUP_BINDING_NO_USER_TABLES_GROUP';
	public const ERROR_NO_SYSTEM_GROUP = 'BICONNECTOR_TABLE_GROUP_BINDING_NO_SYSTEM_GROUP';
	public const ERROR_EMPTY_CATALOG = 'BICONNECTOR_TABLE_GROUP_BINDING_EMPTY_CATALOG';
	public const ERROR_FAILED = 'BICONNECTOR_TABLE_GROUP_BINDING_FAILED';

	private const LOG_SOURCE = 'table_group_binding_install';

	public static function install(): Result
	{
		try
		{
			return self::arrange();
		}
		catch (\Throwable $exception)
		{
			return self::fail(new Result(), new Error($exception->getMessage(), self::ERROR_FAILED));
		}
	}

	/**
	 * Binds the tables of a release to a system group by the code of the group, without the catalog.
	 *
	 * Meant for the updater of a release that ships a table: the table is named in the map, and this
	 * call delivers the binding to the portals that are already arranged. The catalog is not read -
	 * the group of the table is known in advance, and a table bound before it appears joins its group
	 * at that moment. The same rule as in install() holds: a code that has a binding to any group is
	 * left alone, so a repeated call writes nothing and a table an administrator has moved stays put.
	 *
	 * The system groups are created by TableGroupInstaller, which has to stand earlier in the updater.
	 *
	 * @param string $groupCode Code of the system group, one of the SystemTableGroupMap::GROUP_* values.
	 * @param string[] $tableCodes Exact codes of the tables.
	 *
	 * @return Result Data: ['addedCodes' => string[]].
	 */
	public static function bindToSystemGroup(string $groupCode, array $tableCodes): Result
	{
		try
		{
			$result = new Result();

			$groupId = (int)(self::getSystemGroupIds()[$groupCode] ?? 0);
			if ($groupId <= 0)
			{
				return self::fail($result, new Error(
					"The system table group {$groupCode} does not exist.",
					self::ERROR_NO_SYSTEM_GROUP,
				));
			}

			$unboundCodes = array_values(array_diff($tableCodes, self::getBoundCodes()));
			if (empty($unboundCodes))
			{
				return $result->setData(['addedCodes' => []]);
			}

			$addResult = TableGroupService::addGroupBindings($groupId, $unboundCodes);
			if (!$addResult->isSuccess())
			{
				return self::fail($result, ...$addResult->getErrors());
			}

			return $result->setData(['addedCodes' => $addResult->getData()['addedCodes'] ?? []]);
		}
		catch (\Throwable $exception)
		{
			return self::fail(new Result(), new Error($exception->getMessage(), self::ERROR_FAILED));
		}
	}

	private static function arrange(): Result
	{
		$result = new Result();

		$groupIdByCode = self::getSystemGroupIds();
		$userTablesGroupId = (int)($groupIdByCode[SystemTableGroupMap::getUserTablesGroupCode()] ?? 0);
		if ($userTablesGroupId <= 0)
		{
			// Groups are created by TableGroupInstaller, which stands earlier in the updater. Without
			// the user tables group the run would arrange the declared part and leave the rest homeless.
			return self::fail($result, new Error(
				'The user tables group does not exist.',
				self::ERROR_NO_USER_TABLES_GROUP,
			));
		}

		$catalogCodes = SupersetServiceIntegration::getTableList();
		if (empty($catalogCodes))
		{
			return self::fail($result, new Error(
				'The table catalog answered with an empty list.',
				self::ERROR_EMPTY_CATALOG,
			));
		}

		// Taken once, before the first write: a code bound during this very run must not change the
		// answer to "was it already arranged".
		$boundCodes = self::getBoundCodes();

		$map = SystemTableGroupMap::getMap();
		$declaredCodes = [];

		foreach ($map as $groupCode => $groupTableCodes)
		{
			$declaredCodes = array_merge($declaredCodes, $groupTableCodes);

			$groupId = (int)($groupIdByCode[$groupCode] ?? 0);
			if ($groupId <= 0)
			{
				$result->addError(new Error(
					"The system table group {$groupCode} does not exist.",
					self::ERROR_NO_SYSTEM_GROUP,
				));

				continue;
			}

			// A declared code absent from the catalog is bound as well: the table it names is
			// conditional, and bound in advance it joins the group at the moment it appears instead
			// of falling into the user tables group and breaking the coverage.
			self::addBindings($result, $groupId, array_diff($groupTableCodes, $boundCodes));
		}

		$userTablesCodes = array_diff($catalogCodes, $declaredCodes, $boundCodes);
		self::addBindings($result, $userTablesGroupId, $userTablesCodes);

		if (!$result->isSuccess())
		{
			// Every caller of the run is a lifecycle point that ignores the result, so without the
			// log the portal keeps silent about being left without the composition.
			Logger::logErrors($result->getErrors(), ['source' => self::LOG_SOURCE]);
		}

		return $result;
	}

	/**
	 * @return array<string, int> Group id by group code.
	 */
	private static function getSystemGroupIds(): array
	{
		$rows = TableGroupTable::getList([
			'select' => ['ID', 'CODE'],
			'filter' => ['=TYPE' => TableGroupTable::GROUP_TYPE_SYSTEM],
		])->fetchAll();

		return array_map('intval', array_column($rows, 'ID', 'CODE'));
	}

	/**
	 * @return string[] Codes that have a binding to any group, custom groups included.
	 */
	private static function getBoundCodes(): array
	{
		$rows = TableGroupBindingTable::getList([
			'select' => ['TABLE_CODE'],
			'group' => ['TABLE_CODE'],
		])->fetchAll();

		return array_column($rows, 'TABLE_CODE');
	}

	private static function addBindings(Result $result, int $groupId, array $tableCodes): void
	{
		if (empty($tableCodes))
		{
			return;
		}

		$addResult = TableGroupService::addGroupBindings($groupId, array_values($tableCodes));
		if (!$addResult->isSuccess())
		{
			$result->addErrors($addResult->getErrors());
		}
	}

	private static function fail(Result $result, Error ...$errors): Result
	{
		$result->addErrors($errors);
		Logger::logErrors($result->getErrors(), ['source' => self::LOG_SOURCE]);

		return $result;
	}
}
