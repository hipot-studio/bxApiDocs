<?php

namespace Bitrix\BIConnector\Access\Update;

use Bitrix\BIConnector\Access\Install\TableGroupBindingInstaller;
use Bitrix\BIConnector\Access\TableGroup\TableGroupBindingTable;
use Bitrix\BIConnector\ExternalSource\DatasetTableCodes;
use Bitrix\BIConnector\ExternalSource\SupersetServiceIntegration;
use Bitrix\BIConnector\Superset\Logger\Logger;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;

/**
 * Repeats the binding of the tables to the table groups until every table of this portal is bound,
 * and then removes itself from the schedule.
 *
 * The run of the updater is a single hit, and the catalog it needs is built by the data source
 * events of other modules: nothing guarantees that every supplier is registered in that hit. So the
 * arrangement is not done by the updater itself but repeated from here until it covers the catalog.
 * The arrangement itself belongs to TableGroupBindingInstaller; what is decided here is whether one
 * more pass is due. The tables to cover are the catalog plus the external datasets of the portal:
 * a dataset reaches the catalog only while the plan allows external entities, so counted by the
 * catalog alone it would be declared covered while being bound to nothing.
 *
 * Self-removal is tied to the state of the database and not to the fact that a pass happened. While
 * the agent is alive it also returns the composition a save from a page opened before the update
 * wipes out, so the day a portal is brought over costs it nothing.
 *
 * The class and the method stay in the module once the catalog is covered. A removed method leaves
 * an eternally failing row in the schedule - an exception inside an agent does not move its next
 * run - which is exactly what happened to TableGroupLocalizationService::restoreNames(). A release
 * that decides to drop them has to remove the row by its name first.
 */
final class TableGroupBindingAgent
{
	public const AGENT_NAME = 'Bitrix\\BIConnector\\Access\\Update\\TableGroupBindingAgent::run();';

	private const LOG_SOURCE = 'table_group_binding_agent';

	/**
	 * @return string Own name to stay in the schedule, an empty string to be removed from it.
	 */
	public static function run(): string
	{
		if (!Loader::includeModule('biconnector'))
		{
			return self::AGENT_NAME;
		}

		// The installer writes its own failures to the log under its own source, so they are not
		// repeated here: what this pass adds to them is the reason it stayed, and that is logged below.
		$installResult = TableGroupBindingInstaller::install();

		$catalogCodes = SupersetServiceIntegration::getTableList();
		if (empty($catalogCodes))
		{
			// An empty catalog is read as an answer nobody gave. The two cases are indistinguishable by
			// code, and the price of the two mistakes is not the same: staying costs one pass a day,
			// leaving costs a portal whose tables are in no group at all. The datasets are not asked
			// about at all here: the pass that could not read the catalog has arranged nothing anyway.
			return self::stay('The catalog answered with an empty list', $installResult->getErrorMessages());
		}

		// The datasets are counted apart from the catalog, which publishes them only while the plan
		// allows external entities and the switch of their type is on: covered by the catalog alone,
		// the agent would remove itself on a portal whose datasets are hidden today and leave them
		// without a group for the day the plan returns.
		$coveredCodes = array_unique([...$catalogCodes, ...DatasetTableCodes::getCodes()]);

		$unboundCodes = array_diff($coveredCodes, self::getBoundCodes());
		if (!empty($unboundCodes))
		{
			return self::stay(
				'The tables are not covered yet: ' . implode(', ', array_slice(array_values($unboundCodes), 0, 20)),
				$installResult->getErrorMessages(),
			);
		}

		return '';
	}

	/**
	 * @param string $reason
	 * @param string[] $installErrors
	 *
	 * @return string Own name, to be written back into the schedule.
	 */
	private static function stay(string $reason, array $installErrors): string
	{
		$errors = [new Error($reason)];
		foreach ($installErrors as $installError)
		{
			$errors[] = new Error($installError);
		}

		Logger::logWarning($errors, [
			'source' => self::LOG_SOURCE,
			'message' => 'The table group binding agent stays for another pass',
		]);

		return self::AGENT_NAME;
	}

	/**
	 * @return string[] Codes that have a binding to any group.
	 */
	private static function getBoundCodes(): array
	{
		$rows = TableGroupBindingTable::getList([
			'select' => ['TABLE_CODE'],
			'group' => ['TABLE_CODE'],
		])->fetchAll();

		return array_column($rows, 'TABLE_CODE');
	}
}
