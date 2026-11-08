<?php

namespace Bitrix\BIConnector\DataSource;

use Bitrix\BIConnector\Access\Table\TableVisibilityService;
use Bitrix\BIConnector\DataSourceConnector\Connector;
use Bitrix\BIConnector\ExternalSource\Internal\ExternalDatasetTable;
use Bitrix\BIConnector\ExternalSource\Type;
use Bitrix\BIConnector\Integration\Crm\ProductRowAmounts;
use Bitrix\BIConnector\Manager;
use Bitrix\BIConnector\Service;

class SystemDatasetProvider
{
	private const USER_DATASETS_CACHE_TTL = 86400;

	private ?Service $service;
	private array $connectorCache = [];
	private TableVisibilityService $visibility;

	/**
	 * @param TableVisibilityService|null $visibility The answer of the rights axis; the one of the
	 *                                               current user when omitted.
	 */
	public function __construct(?TableVisibilityService $visibility = null)
	{
		$this->service = $this->initializeService();
		$this->visibility = $visibility ?? new TableVisibilityService();
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

			if (!$this->visibility->isTableAllowed($name))
			{
				continue;
			}

			$fullDescription = $connector->rawInfo['TABLE_DESCRIPTION_FULL'] ?? '';
			$shortDescription = $connector->rawInfo['TABLE_DESCRIPTION'] ?? '';
			$description = $fullDescription !== '' ? $fullDescription : (is_array($shortDescription) ? '' : (string)$shortDescription);

			// Human-name rule (see Internal\Services\Dataset\TableDescriptionProvider): a human label
			// exists only when the short description is a non-empty string differing from the
			// technical name. Without one the name column stays empty — the code has its own column.
			$verboseName =
				is_string($shortDescription) && $shortDescription !== '' && $shortDescription !== $name
					? $shortDescription
					: ''
			;

			$rows[] = [
				'ID' => $name,
				'NAME' => $name,
				'VERBOSE_NAME' => $verboseName,
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
	 * Returns preview rows of a system table.
	 *
	 * The query parameters are provided by the connector itself: the base connector returns
	 * the first N rows, while API-backed connectors (e.g. expenses) load a recent time window.
	 *
	 * @param string $name System table name (connector identifier).
	 * @param int $limit Maximum number of rows to return. Also propagated as SQL-level LIMIT.
	 * @param array $parameters Extra connector query parameters (e.g. 'dateRange' => ['startDate' => 'YYYY-MM-DD', 'endDate' => 'YYYY-MM-DD']).
	 *     Values override connector preview defaults.
	 * @return array[] Indexed array of rows, each row is indexed array of values.
	 */
	public function getPreviewData(string $name, int $limit = 30, array $parameters = []): array
	{
		$connector = $this->getConnector($name);
		if ($connector === null)
		{
			return [];
		}

		$parameters = array_merge($connector->getPreviewParameters(), $parameters);
		$parameters['limit'] = $limit;
		$amountColumnIndexes = $this->getPreviewAmountColumnIndexes($name, $connector, $parameters);

		$rows = [];
		foreach ($connector->query($parameters, $limit) as $row)
		{
			foreach ($amountColumnIndexes as $index)
			{
				if (isset($row[$index]))
				{
					$row[$index] = ProductRowAmounts::formatPreviewAmount($row[$index]);
				}
			}

			$rows[] = array_values($row);
		}

		return $rows;
	}

	private function getPreviewAmountColumnIndexes(string $name, Connector\Base $connector, array $parameters): array
	{
		$amountFields = ProductRowAmounts::getPreviewAmountFields($name);
		if (empty($amountFields))
		{
			return [];
		}

		$schema = $connector->getFormattedData($parameters)['schema'] ?? [];
		$indexes = [];
		foreach ($schema as $index => $field)
		{
			if (in_array($field['ID'], $amountFields, true))
			{
				$indexes[] = $index;
			}
		}

		return $indexes;
	}

	/**
	 * The single funnel of the section to a connector of the catalog.
	 *
	 * The rights are asked before the memo of the request, never after: the answer "visible or not"
	 * belongs to the person asking, while the memo holds the description of the table and outlives
	 * every person within the hit. Asked after, a hit serving two people would hand the second the
	 * decision taken for the first.
	 */
	private function getConnector(string $name): ?Connector\Base
	{
		if ($this->service === null)
		{
			return null;
		}

		if (!$this->visibility->isTableAllowed($name))
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

		// The grid carries the technical name and the human one in two columns, so the quick search
		// looks into both: finding a table by its label is the point of the name column.
		return array_values(array_filter(
			$rows,
			static fn(array $row): bool => self::matchesName($row, $nameFilter),
		));
	}

	private static function matchesName(array $row, string $nameFilter): bool
	{
		foreach (['NAME', 'VERBOSE_NAME'] as $field)
		{
			$value = (string)($row[$field] ?? '');
			if ($value !== '' && mb_stripos($value, $nameFilter) !== false)
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * Whitelist approach: system rows only support NAME, TYPE, CREATED_BY_ID.
	 * Any other field in the filter (with any ORM prefix like >=, <=, !, % etc.)
	 * means system rows cannot match — exclude them.
	 */
	private function isExcludedByFilter(array $filter): bool
	{
		$allowedFields = ['NAME', 'TYPE', 'CREATED_BY_ID'];
		$sqlWhere = new \CSQLWhere();

		foreach ($filter as $key => $value)
		{
			$operation = $sqlWhere->MakeOperation($key);
			if (!in_array($operation['FIELD'], $allowedFields, true))
			{
				return true;
			}

			if ($operation['FIELD'] === 'TYPE' && $this->isSystemTypeExcluded($operation['OPERATION'], $value))
			{
				return true;
			}
		}

		// CREATED_BY_ID filter set but 0 (system creator) not included
		if (!empty($filter['CREATED_BY_ID']) && !in_array(0, array_map('intval', (array)$filter['CREATED_BY_ID'])))
		{
			return true;
		}

		return false;
	}

	private function isSystemTypeExcluded(string $operation, mixed $value): bool
	{
		$values = array_filter(array_map('strval', (array)$value), static fn(string $item): bool => $item !== '');
		if ($values === [])
		{
			return false;
		}

		$hasSystemType = in_array(Type::System->value, $values, true);

		return match ($operation)
		{
			'E', 'I' => !$hasSystemType,
			'N', 'NI' => $hasSystemType,
			// other comparisons cannot be evaluated on the in-memory rows, so system rows are excluded
			default => true,
		};
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
