<?php

namespace Bitrix\BIConnector\Internal\Services\Dataset;

use Bitrix\BIConnector\Manager;

/**
 * Source of the dataset full description, resolved by technical table name.
 *
 * Sibling of {@see TableDescriptionProvider}: that one carries the short label — the second element
 * of a table-list row — while this one carries the full description, the third. Both read the same
 * catalog in the dashboard language.
 *
 * Unlike the label, the full description has no technical-name fallback baked in, so its absence is
 * simply an empty value; a value equal to the table name is dropped as carrying nothing.
 */
class TableFullDescriptionProvider
{
	/**
	 * @var array<string, array<string, mixed>>
	 */
	private array $descriptionsByLang = [];

	/**
	 * Full description of the table, or null when it does not exist.
	 */
	public function getDescription(string $tableName, string $dashboardLang): ?string
	{
		$description = $this->getDescriptions($dashboardLang)[$tableName] ?? null;
		if (!is_string($description))
		{
			return null;
		}

		$description = trim($description);
		if ($description === '' || $description === $tableName)
		{
			return null;
		}

		return $description;
	}

	/**
	 * Catalog read, separated so a test double can answer without a live catalog.
	 *
	 * @return array<string, mixed>
	 */
	protected function loadCatalog(string $dashboardLang): array
	{
		$service = Manager::getInstance()->createService('superset');
		if ($service === null)
		{
			return [];
		}

		$service->setLanguage($dashboardLang);

		$descriptions = [];
		foreach ($service->getTableList() as $row)
		{
			$tableName = $row[0] ?? null;
			if (!is_string($tableName) || $tableName === '')
			{
				continue;
			}

			// Row shape: [table_name, human label, full description]. The third element is absent on
			// instances that predate the full-description channel.
			$descriptions[$tableName] = $row[2] ?? null;
		}

		return $descriptions;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function getDescriptions(string $dashboardLang): array
	{
		return $this->descriptionsByLang[$dashboardLang] ??= $this->loadCatalog($dashboardLang);
	}
}
