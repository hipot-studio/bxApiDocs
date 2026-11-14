<?php

namespace Bitrix\Crm\Update;

use Bitrix\Crm\WebForm\ResponsibleQueue;
use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Update\Stepper;

/**
 * Removes the legacy options that kept the position of the responsible queue of a CRM form.
 *
 * The position moved to the persistent storage, so nothing reads these options any more. A step
 * deletes its batch with one statement and resets the option cache of the module once: deleting
 * the options one by one would reset the cache for every option.
 */
final class RemoveWebFormResponsibleQueueOptionsStepper extends Stepper
{
	private const BATCH_SIZE = 500;
	private const MODULE_ID = ResponsibleQueue::OPTION_MODULE_ID;
	private const NAME_PREFIX = ResponsibleQueue::OPTION_LAST_NAME;

	protected static $moduleId = 'crm';

	public function execute(array &$option)
	{
		$lastName = (string)($option['lastName'] ?? '');

		$scannedNames = $this->getNextNames($lastName);
		$names = array_filter($scannedNames, self::isQueueOptionName(...));
		if (!empty($names))
		{
			$this->deleteOptions($names);
		}

		if (count($scannedNames) < self::BATCH_SIZE)
		{
			return self::FINISH_EXECUTION;
		}

		$option['lastName'] = end($scannedNames);

		return self::CONTINUE_EXECUTION;
	}

	private static function isQueueOptionName(string $name): bool
	{
		return preg_match('/^' . preg_quote(self::NAME_PREFIX, '/') . '\d+$/', $name) === 1;
	}

	/**
	 * @return string[]
	 */
	private function getNextNames(string $lastName): array
	{
		$connection = Application::getConnection();
		$helper = $connection->getSqlHelper();

		// The legacy code wrote the options without a site, so b_option holds every name; copies in
		// b_option_site are removed together with them. "_" of the prefix matches any character in LIKE,
		// the exact name is checked by isQueueOptionName().
		$rows = $connection->query(
			"SELECT NAME FROM b_option"
			. " WHERE MODULE_ID = '" . $helper->forSql(self::MODULE_ID) . "'"
			. " AND NAME LIKE '" . $helper->forSql(self::NAME_PREFIX) . "%'"
			. " AND NAME > '" . $helper->forSql($lastName) . "'"
			. ' ORDER BY NAME',
			self::BATCH_SIZE,
		);

		$names = [];
		while ($row = $rows->fetch())
		{
			$names[] = (string)$row['NAME'];
		}

		return $names;
	}

	/**
	 * @param string[] $names
	 */
	private function deleteOptions(array $names): void
	{
		// The last option is deleted through the API, which resets the option cache of the module.
		$lastName = array_pop($names);

		if (!empty($names))
		{
			$connection = Application::getConnection();
			$helper = $connection->getSqlHelper();

			$nameList = implode(
				', ',
				array_map(
					static fn(string $name): string => "'" . $helper->forSql($name) . "'",
					$names,
				),
			);
			$condition = "MODULE_ID = '" . $helper->forSql(self::MODULE_ID) . "' AND NAME IN ({$nameList})";

			$connection->queryExecute("DELETE FROM b_option WHERE {$condition}");
			$connection->queryExecute("DELETE FROM b_option_site WHERE {$condition}");
		}

		Option::delete(self::MODULE_ID, ['name' => $lastName]);
	}
}
