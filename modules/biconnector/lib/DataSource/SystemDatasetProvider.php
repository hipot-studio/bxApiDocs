<?php

namespace Bitrix\BIConnector\DataSource;

use Bitrix\BIConnector\DataSourceConnector\Connector;
use Bitrix\BIConnector\ExternalSource\Internal\ExternalDatasetTable;
use Bitrix\BIConnector\ExternalSource\Type;
use Bitrix\BIConnector\Manager;
use Bitrix\BIConnector\Service;

class SystemDatasetProvider
{
	private const USER_DATASETS_CACHE_TTL = 86400;

	private ?Service $service;
	private array $connectorCache = [];

	public function __construct()
	{
		$this->service = $this->initializeService();
	}

	protected function initializeService(): ?Service
	{
		$manager = Manager::getInstance();
		$service = $manager->createService('superset');
		$service?->setLanguage(LANGUAGE_ID);

		return $service;
	}

	/**
	 * Returns system datasets formatted for grid display.
	 *
	 * @param array $filter PHP-level filter.
	 * @return array
	 */
	public function getList(array $filter = []): array
	{
		if ($this->service === null)
		{
			return [];
		}

		$connectors = $this->service->getDataSourceConnectors();
		$userDatasetNamesMap = array_flip($this->getUserDatasetNames());

		$rows = [];
		foreach ($connectors as $connector)
		{
			$name = $connector->getName();
			if (isset($userDatasetNamesMap[$name]))
			{
				continue;
			}

			$fullDescription = $connector->rawInfo['TABLE_DESCRIPTION_FULL'] ?? '';
			$shortDescription = $connector->rawInfo['TABLE_DESCRIPTION'] ?? '';
			$description = $fullDescription !== '' ? $fullDescription : (is_array($shortDescription) ? '' : (string)$shortDescription);

			$rows[] = [
				'ID' => $name,
				'NAME' => $name,
				'TYPE' => Type::System->value,
				'DESCRIPTION' => $description,
				'CREATED_BY_ID' => 0,
				'UPDATED_BY_ID' => 0,
				'DATE_CREATE' => null,
				'DATE_UPDATE' => null,
				'SOURCE' => null,
				'IS_SYSTEM' => true,
			];
		}

		return $this->applyFilter($rows, $filter);
	}

	/**
	 * Returns a single system dataset with fields (for detail slider).
	 */
	public function getByName(string $name): ?array
	{
		$connector = $this->getConnector($name);
		if ($connector === null)
		{
			return null;
		}

		return [
			'NAME' => $name,
			'TABLE_DESCRIPTION' => $connector->rawInfo['TABLE_DESCRIPTION'] ?? '',
			'TABLE_DESCRIPTION_FULL' => $connector->rawInfo['TABLE_DESCRIPTION_FULL'] ?? '',
			'FIELDS' => $connector->getFields()->toArray(),
		];
	}

	/**
	 * Returns first N rows of a system table for preview.
	 *
	 * @param string $name System table name (connector identifier).
	 * @param int $limit Maximum number of rows to return. Also propagated as SQL-level LIMIT.
	 * @param array $parameters Extra connector query parameters (e.g. 'dateRange' => ['startDate' => 'YYYY-MM-DD', 'endDate' => 'YYYY-MM-DD']).
	 * @return array[] Indexed array of rows, each row is indexed array of values.
	 */
	public function getPreviewData(string $name, int $limit = 20, array $parameters = []): array
	{
		$connector = $this->getConnector($name);
		if ($connector === null)
		{
			return [];
		}

		$parameters['limit'] = $limit;

		$rows = [];
		foreach ($connector->query($parameters, $limit) as $row)
		{
			$rows[] = array_values($row);
		}

		return $rows;
	}

	private function getConnector(string $name): ?Connector\Base
	{
		if ($this->service === null)
		{
			return null;
		}

		if (!array_key_exists($name, $this->connectorCache))
		{
			$this->connectorCache[$name] = $this->service->getDataSourceConnector($name);
		}

		return $this->connectorCache[$name];
	}

	/**
	 * Returns names of user-created datasets from ExternalDatasetTable.
	 * Used to exclude them from system table list (Service::getTableList() returns both).
	 *
	 * @return string[]
	 */
	protected function getUserDatasetNames(): array
	{
		$rows = ExternalDatasetTable::getList([
			'select' => ['NAME'],
			'cache' => ['ttl' => self::USER_DATASETS_CACHE_TTL],
		])->fetchAll();

		return array_column($rows, 'NAME');
	}

	private function applyFilter(array $rows, array $filter): array
	{
		if ($this->isExcludedByFilter($filter))
		{
			return [];
		}

		$nameFilter = $this->extractNameFilter($filter);
		if ($nameFilter === '')
		{
			return $rows;
		}

		return array_values(array_filter(
			$rows,
			static fn(array $row): bool => mb_stripos($row['NAME'] ?? '', $nameFilter) !== false,
		));
	}

	/**
	 * Whitelist approach: system rows only support NAME, TYPE, CREATED_BY_ID.
	 * Any other field in the filter (with any ORM prefix like >=, <=, !, % etc.)
	 * means system rows cannot match — exclude them.
	 */
	private function isExcludedByFilter(array $filter): bool
	{
		$allowedFields = ['NAME', 'TYPE', 'CREATED_BY_ID'];

		foreach (array_keys($filter) as $key)
		{
			$fieldName = preg_replace('/^[!=<>%@~#\*]+/', '', $key);
			if (!in_array($fieldName, $allowedFields, true))
			{
				return true;
			}
		}

		// TYPE filter set but 'system' not included
		if (!empty($filter['TYPE']) && !in_array(Type::System->value, (array)$filter['TYPE'], true))
		{
			return true;
		}

		// CREATED_BY_ID filter set but 0 (system creator) not included
		if (!empty($filter['CREATED_BY_ID']) && !in_array(0, array_map('intval', (array)$filter['CREATED_BY_ID'])))
		{
			return true;
		}

		return false;
	}

	private function extractNameFilter(array $filter): string
	{
		$name = $filter['NAME'] ?? '';
		if (is_array($name))
		{
			$name = reset($name);
		}

		return trim((string)$name, "% \t\n\r\0\x0B");
	}
}
