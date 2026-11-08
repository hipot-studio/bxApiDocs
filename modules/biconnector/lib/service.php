<?php

namespace Bitrix\BIConnector;

use Bitrix\BIConnector\DataSourceConnector\FieldCollection;
use Bitrix\BIConnector\DataSourceConnector\FieldDto;
use Bitrix\BIConnector\DataSourceConnector\Connector;
use Bitrix\Main\Context;
use Bitrix\Main\Result;

abstract class Service
{
	protected $manager = null;
	protected ?array $dataSourceConnectors = null;

	/** Per-request memo of getDataSourceConnector() keyed by table name (event-built path). */
	protected array $dataSourceConnectorMemo = [];

	/** @var array<string, true> Datasets already looked for and not found during this request. */
	protected array $missingDataSources = [];
	protected static $serviceId = '';
	public static $dateFormats = [];
	protected $languageMap = null;
	protected $languageId = 'en';

	/**
	 * Creates new service instance.
	 *
	 * @param \Bitrix\BIConnector\Manager $manager Manager instance.
	 */
	public function __construct(\Bitrix\BIConnector\Manager $manager)
	{
		$this->manager = $manager;
		$this->languageId = \Bitrix\Main\Localization\Loc::getCurrentLang() ?? 'en';
	}

	/**
	 * Event OnBIConnectorCreateServiceInstance habler.
	 *
	 * @param \Bitrix\Main\Event $event Event parameters.
	 *
	 * @return \Bitrix\Main\EventResult
	 */
	public static function createServiceInstance(\Bitrix\Main\Event $event)
	{
		$serviceId = $event->getParameters()[0];
		$manager = $event->getParameters()[1];
		$service = null;
		if (static::$serviceId && static::$serviceId === $serviceId)
		{
			$service = new static($manager);
		}
		$result = new \Bitrix\Main\EventResult(\Bitrix\Main\EventResult::SUCCESS, $service);

		return $result;
	}

	/**
	 * Return Service ID for key restrictions
	 *
	 * @return string
	 */
	public static function getServiceId(): string
	{
		return static::$serviceId;
	}

	/**
	 * Changes the language if it is existing and active.
	 * Otherwise, sets the language to the current one.
	 *
	 * @param string $languageId Interface language identifier.
	 *
	 * @return void
	 * @see \Bitrix\Main\Localization\Loc::getCurrentLang
	 */
	public function setLanguage($languageId)
	{
		$this->languageId = \Bitrix\Main\Localization\Loc::getCurrentLang();
		$dbLanguage = \Bitrix\Main\Localization\LanguageTable::getList([
			'select' => ['LID'],
			'filter' => [
				'=LID' => $languageId,
				'=ACTIVE' => 'Y',
			],
			'cache' => ['ttl' => 86400],
		])
			->fetch()
		;

		if ($dbLanguage)
		{
			$this->languageId = $dbLanguage['LID'];
		}

		// Connectors are built with the current language (localized fields/descriptions),
		// so a language switch must invalidate the memoized ones.
		$this->dataSourceConnectorMemo = [];
	}

	/**
	 * Returns current service language.
	 *
	 * @return string
	 */
	public function getLanguage()
	{
		return $this->languageId;
	}

	/**
	 * Returns all available data sources descriptions.
	 *
	 * @param string $languageId Interface language.
	 *
	 * @return array
	 */
	public function getDataSourceConnectors(): array
	{
		if ($this->dataSourceConnectors === null)
		{
			$this->dataSourceConnectors = $this->loadDataSourceConnectors();
		}

		return $this->dataSourceConnectors;
	}

	/**
	 * @param string $name
	 *
	 * @return Connector\Base|null
	 */
	public function getDataSourceConnector(string $name): ?Connector\Base
	{
		// The fully-loaded collection is authoritative: once getDataSourceConnectors() ran, its
		// object wins over the memo so callers never see two connectors for the same table.
		if (!empty($this->dataSourceConnectors[$name]) && $this->dataSourceConnectors[$name] instanceof Connector\Base)
		{
			return $this->dataSourceConnectors[$name];
		}

		if (isset($this->dataSourceConnectorMemo[$name]))
		{
			return $this->dataSourceConnectorMemo[$name];
		}

		// A dataset that was already looked for and not found stays not found for this request: firing the
		// event again would cost another round of description building for the same answer.
		if ($this->isMissingDataSource($name))
		{
			return null;
		}

		$dataSources = [];
		$event = new \Bitrix\Main\Event('biconnector', 'OnBIConnectorDataSources', [
			$this->manager,
			&$dataSources,
			$this->languageId,
			$name,
		]);
		$event->send();

		if (empty($dataSources[$name]))
		{
			$this->rememberMissingDataSource($name);

			return null;
		}

		$source = $dataSources[$name];

		$fields = new FieldCollection();
		foreach ($source['FIELDS'] as $fieldName => $fieldInfo)
		{
			$fields->add($this->prepareFieldDto($fieldName, $fieldInfo));
		}

		return $this->rememberDataSourceConnector(new Connector\Sql($name, $fields, $source));
	}

	/**
	 * Keeps one description snapshot per dataset for the lifetime of the request.
	 *
	 * A dynamic source may answer differently every time it is asked, and one HTTP request touches the
	 * description several times - to validate the table, to build the SQL, to compute the capabilities
	 * fingerprint and to execute. Without a single snapshot the body could be built from one description
	 * while the headers that confirm it came from another, which is exactly what those headers exist to
	 * rule out.
	 */
	protected function rememberDataSourceConnector(Connector\Base $connector): Connector\Base
	{
		$this->dataSourceConnectorMemo[$connector->getName()] = $connector;
		$this->forgetMissingDataSource($connector->getName());

		return $connector;
	}

	protected function isMissingDataSource(string $name): bool
	{
		return isset($this->missingDataSources[$name]);
	}

	protected function rememberMissingDataSource(string $name): void
	{
		$this->missingDataSources[$name] = true;
	}

	protected function forgetMissingDataSource(string $name): void
	{
		unset($this->missingDataSources[$name]);
	}

	/**
	 * Drops everything remembered for the duration of one logical request.
	 *
	 * The snapshot must not outlive the request it was taken for. In a plain HTTP entry point the service
	 * is built anew, but the same object is reused in agents, queues and long running workers, and there
	 * a remembered description, a remembered absence or a prepared statement would answer a later request
	 * with older data - and the growing map of prepared answers would never be released.
	 */
	public function beginRequest(): void
	{
		$remembered = array_merge((array)$this->dataSourceConnectors, $this->dataSourceConnectorMemo);
		foreach ($remembered as $connector)
		{
			if ($connector instanceof Connector\Base)
			{
				$connector->beginRequest();
			}
		}

		$this->dataSourceConnectors = null;
		$this->dataSourceConnectorMemo = [];
		$this->missingDataSources = [];
	}

	/**
	 * Returns all available data sources descriptions.
	 *
	 * @return array
	 */
	protected function loadDataSourceConnectors(): array
	{
		$dataSourceConnectors = [];

		$dataSources = [];
		$event = new \Bitrix\Main\Event('biconnector', 'OnBIConnectorDataSources', [
			$this->manager,
			&$dataSources,
			$this->languageId,
			null,
		]);
		$event->send();

		foreach ($dataSources as $datasourceName => $source)
		{
			// A dataset asked for by name earlier in the request keeps the description it was answered with,
			// so the full collection and the single lookup never hand out two descriptions for one dataset.
			if (isset($this->dataSourceConnectorMemo[$datasourceName]))
			{
				$dataSourceConnectors[$datasourceName] = $this->dataSourceConnectorMemo[$datasourceName];

				continue;
			}

			$fields = new FieldCollection();
			foreach ($source['FIELDS'] as $fieldName => $fieldInfo)
			{
				$fields->add($this->prepareFieldDto($fieldName, $fieldInfo));
			}

			$dataSourceConnectors[$datasourceName] = new Connector\Sql($datasourceName, $fields, $source);
		}

		return $dataSourceConnectors;
	}

	/**
	 * Event OnBIConnectorValidateDashboardUrl handler.
	 *
	 * @param  mixed $event Event parameters.
	 *
	 * @return \Bitrix\Main\EventResult
	 */
	public static function validateDashboardUrl(\Bitrix\Main\Event $event)
	{
		$result = new \Bitrix\Main\EventResult(\Bitrix\Main\EventResult::SUCCESS, 0);

		return $result;
	}

	/**
	 * Retruns array of available data sources.
	 * This will be returned as a json on "show_tables" service command.
	 *
	 * @return array
	 */
	public function getTableList(): array
	{
		$result = [];
		foreach ($this->getDataSourceConnectors() as $connector)
		{
			$result[] = $this->buildTableListRow($connector);
		}

		return $result;
	}

	/**
	 * Builds one row of the table list: the technical name first, the human-readable one next.
	 *
	 * The row shape is part of the contract with every consumer of "show_tables", and the external
	 * services read it by index, so a service that carries more than these two publishes the extra
	 * elements by overriding this method rather than by widening the row for everybody.
	 *
	 * @param Connector\Base $connector
	 *
	 * @return array
	 */
	protected function buildTableListRow(Connector\Base $connector): array
	{
		$verboseName = (string)($connector->rawInfo['VERBOSE_NAME'] ?? '');

		return [
			$connector->getName(),
			$verboseName !== '' ? $verboseName : ($connector->rawInfo['TABLE_DESCRIPTION'] ?? []),
		];
	}

	/**
	 * @deprecated
	 *
	 * Type mapping is realised into Bitrix\BIConnector\DataSourceConnector\FieldDto
	 *
	 * Returns internal type external representation.
	 *
	 * @param string $internalType Internal (CSQLWhere) type.
	 * @param null $fieldName Field name - ID, TITLE, UF_CRM_100 etc.
	 *
	 * @return string
	 * @see \CSQLWhere
	 */
	protected function mapType($internalType, $fieldName = null): string
	{
		switch ($internalType)
		{
			case 'file':
			case 'enum':
			case 'int':
			case 'double':
				return 'NUMBER';
			case 'string':
			case 'callback':
				return 'STRING';
			case 'date':
				return 'YEAR_MONTH_DAY';
			case 'datetime':
				return 'YEAR_MONTH_DAY_SECOND';
			case 'bool':
				return 'BOOLEAN';
		}

		return 'STRING';
	}

	/**
	 * Retruns array of available data sources.
	 * This will be returned as a json on "desc" service command.
	 *
	 * @param string $tableName Data source name.
	 * @return array
	 */
	public function getTableFields(string $tableName): array
	{
		$connector = $this->getDataSourceConnector($tableName);
		if (!$connector)
		{
			return [];
		}

		return $connector
			->getFields()
			->toArray()
		;
	}

	/**
	 * Table description extended with the capabilities block, served by `?desc&caps=1` (design §2.1).
	 *
	 * The `columns` array is element-wise identical to the answer of plain `?desc`, which itself
	 * stays byte-for-byte unchanged, so a plugin that does not ask for capabilities sees no change.
	 *
	 * @param string $tableName Data source name.
	 *
	 * @return array
	 */
	public function describeTable(string $tableName): array
	{
		$connector = $this->getDataSourceConnector($tableName);
		if (!$connector)
		{
			return [];
		}

		return [
			'version' => 1,
			'columns' => array_values($connector->getFields()->toArray()),
			'capabilities' => [
				'aggregation' => $connector->getAggregationCapabilities()->toArray(),
			],
		];
	}

	/**
	 * Current capabilities fingerprint of a table, or null when aggregation is not available.
	 *
	 * It travels with every aggregated answer so that the plugin can detect that the source or the
	 * capabilities changed after it planned the query.
	 */
	public function getAggregationCapsFingerprint(string $tableName): ?string
	{
		$capabilities = $this->getDataSourceConnector($tableName)?->getAggregationCapabilities();

		return $capabilities?->supported ? $capabilities->getFingerprint() : null;
	}

	/**
	 * @param string $fieldName
	 * @param array $fieldInfo
	 *
	 * @return FieldDto
	 */
	protected function prepareFieldDto(string $fieldName, array $fieldInfo): FieldDto
	{
		$type = $fieldInfo['FIELD_TYPE_EX'] ?? $fieldInfo['FIELD_TYPE'];

		//Backwards compatibility
		if (isset($fieldInfo['CONCAT_KEY']))
		{
			$fieldInfo['GROUP_KEY'] = $fieldInfo['CONCAT_KEY'];
		}

		if (isset($fieldInfo['CONCAT_GROUP_BY']))
		{
			$fieldInfo['GROUP_CONCAT'] = $fieldInfo['CONCAT_GROUP_BY'];
		}


		if (isset($fieldInfo['IS_SYSTEM']))
		{
			$isSystem = $fieldInfo['IS_SYSTEM'] === 'Y';
		}
		else
		{
			$isSystem = !str_starts_with($fieldName, 'UF_');
		}

		return new FieldDto(
			$fieldName,
			$fieldInfo['FIELD_DESCRIPTION'] ?? $fieldName,
			$fieldInfo['FIELD_DESCRIPTION_FULL'] ?? '',
			$type ?? 'string',
			($fieldInfo['IS_METRIC'] ?? 'N') === 'Y',
			($fieldInfo['IS_PRIMARY'] ?? 'N') === 'Y',
			$isSystem,
			$fieldInfo['AGGREGATION_TYPE'] ?? null,
			$fieldInfo['GROUP_KEY'] ?? null,
			$fieldInfo['GROUP_CONCAT'] ?? null,
			$fieldInfo['GROUP_COUNT'] ?? null,
			$fieldInfo['IS_VALUE_SPLITABLE'] ?? null,
		);
	}

	/** @deprecated */
	protected function prepareTableFields(string $fieldName, array $fieldInfo): array
	{
		$type = $fieldInfo['FIELD_TYPE_EX'] ?? $fieldInfo['FIELD_TYPE'];

		//Backwards compatibility
		if (isset($fieldInfo['CONCAT_KEY']))
		{
			$fieldInfo['GROUP_KEY'] = $fieldInfo['CONCAT_KEY'];
		}

		if (isset($fieldInfo['CONCAT_GROUP_BY']))
		{
			$fieldInfo['GROUP_CONCAT'] = $fieldInfo['CONCAT_GROUP_BY'];
		}

		return [
			'CONCEPT_TYPE' => ($fieldInfo['IS_METRIC'] ?? 'N') === 'Y'
					? 'METRIC'
					: 'DIMENSION'
			,
			'ID' => $fieldName,
			'NAME' => $fieldInfo['FIELD_DESCRIPTION'],
			'DESCRIPTION' => $fieldInfo['FIELD_DESCRIPTION_FULL'] ?? '',
			'TYPE' => $this->mapType($type),
			'AGGREGATION_TYPE' => $fieldInfo['AGGREGATION_TYPE'] ?? null,
			'IS_PRIMARY' => $fieldInfo['IS_PRIMARY'] ?? null,
			'GROUP_KEY' => $fieldInfo['GROUP_KEY'] ?? null,
			'GROUP_CONCAT' => $fieldInfo['GROUP_CONCAT'] ?? null,
			'GROUP_COUNT' => $fieldInfo['GROUP_COUNT'] ?? null,
		];
	}

	/**
	 * @deprecated
	 *
	 * applyDateFilter
	 *
	 * @param array &$sqlWhere Modified where.
	 * @param array $tableFields Table metadata.
	 * @param array $dateRange Filters from input.
	 * @param string $timeFilterColumn Column from input.
	 *
	 * @return void
	 */
	protected function applyDateFilter(&$sqlWhere, $tableFields, $dateRange, $timeFilterColumn = '')
	{
		$startDate = array_key_exists('startDate', $dateRange) ? MakeTimeStamp($dateRange['startDate'], 'YYYY-MM-DD') : false;

		$filterColumnName = false;
		if (
			$timeFilterColumn
			&& array_key_exists($timeFilterColumn, $tableFields)
			&& $tableFields[$timeFilterColumn]['FIELD_TYPE'] === 'datetime'
		)
		{
			$filterColumnName = $timeFilterColumn;
		}
		else
		{
			foreach ($tableFields as $fieldName => $fieldInfo)
			{
				if ($fieldInfo['FIELD_TYPE'] === 'datetime')
				{
					$filterColumnName = $fieldName;

					break;
				}
			}
		}

		if ($filterColumnName)
		{
			if ($startDate)
			{
				$sqlWhere['>=' . $filterColumnName] = ConvertTimeStamp($startDate, 'SHORT');
			}

			$endDate = array_key_exists('endDate', $dateRange) ? MakeTimeStamp($dateRange['endDate'], 'YYYY-MM-DD') : false;
			if ($endDate)
			{
				$endDate += 23 * 3600 + 59 * 60 + 59;
				$sqlWhere['<=' . $filterColumnName] = ConvertTimeStamp($endDate, 'FULL');
			}
		}
	}

	/**
	 * @deprecated
	 *
	 * applyDimensionsFilters
	 *
	 * @param array &$sqlWhere Modified where.
	 * @param bool &$canBeFiltered Return flag.
	 * @param array $tableFields Table metadata.
	 * @param array $dimensionsFilters Filters from input.
	 *
	 * @return void
	 */
	protected function applyDimensionsFilters(&$sqlWhere, &$canBeFiltered, $tableFields, $dimensionsFilters)
	{
		foreach ($dimensionsFilters as $topFilter)
		{
			$andFilter = [
				'LOGIC' => 'OR',
			];
			foreach ($topFilter as $subFilter)
			{
				if ($subFilter['fieldName'] && isset($tableFields[$subFilter['fieldName']]))
				{
					if ($tableFields[$subFilter['fieldName']]['FIELD_TYPE'] === 'datetime')
					{
						if (is_array($subFilter['values']))
						{
							foreach ($subFilter['values'] as $i => $value)
							{
								$subFilter['values'][$i] = ConvertTimeStamp(strtotime($value), 'FULL');
							}
						}
						else
						{
							$subFilter['values'] = ConvertTimeStamp(strtotime($subFilter['values']), 'FULL');
						}
					}
					elseif ($tableFields[$subFilter['fieldName']]['FIELD_TYPE'] === 'date')
					{
						if (is_array($subFilter['values']))
						{
							foreach ($subFilter['values'] as $i => $value)
							{
								$subFilter['values'][$i] = ConvertTimeStamp(strtotime($value), 'SHORT');
							}
						}
						else
						{
							$subFilter['values'] = ConvertTimeStamp(strtotime($subFilter['values']), 'SHORT');
						}
					}

					$negate = $subFilter['type'] === 'EXCLUDE' ? '!' : '';
					switch ($subFilter['operator'])
					{
						case 'EQUALS':
						case 'IN_LIST':
							$andFilter[] = [$negate . '=' . $subFilter['fieldName'] => $subFilter['values']];

							break;
						case 'CONTAINS':
							$andFilter[] = [$negate . '%' . $subFilter['fieldName'] => $subFilter['values']];

							break;
						case 'REGEXP_PARTIAL_MATCH':
						case 'REGEXP_EXACT_MATCH':
							$canBeFiltered = false;

							break;
						case 'IS_NULL':
							$andFilter[] = [$negate . '=' . $subFilter['fieldName'] => false];

							break;
						case 'BETWEEN':
							$andFilter[] = [$negate . '><' . $subFilter['fieldName'] => $subFilter['values']];

							break;
						case 'NUMERIC_GREATER_THAN':
							$andFilter[] = [$negate . '>' . $subFilter['fieldName'] => $subFilter['values']];

							break;
						case 'NUMERIC_GREATER_THAN_OR_EQUAL':
							$andFilter[] = [$negate . '>=' . $subFilter['fieldName'] => $subFilter['values']];

							break;
						case 'NUMERIC_LESS_THAN':
							$andFilter[] = [$negate . '<' . $subFilter['fieldName'] => $subFilter['values']];

							break;
						case 'NUMERIC_LESS_THAN_OR_EQUAL':
							$andFilter[] = [$negate . '<=' . $subFilter['fieldName'] => $subFilter['values']];

							break;
						default:
							$canBeFiltered = false;
					}
				}
			}
			if (count($andFilter) > 1)
			{
				$sqlWhere[] = $andFilter;
			}
		}
	}

	protected function markErrorStreamed(Result $result): void
	{
		$data = $result->getData();
		$data['errorStreamed'] = true;
		$result->setData($data);
	}

	protected function formatLimitValue(int $value): string
	{
		$culture = Context::getCurrent()?->getCulture();
		if (isset($culture) && $value >= 10000)
		{
			return number_format(
				(float)$value,
				0,
				$culture->getNumberDecimalSeparator(),
				$culture->getNumberThousandsSeparator(),
			);
		}

		return (string)$value;
	}

	/**
	 * Retruns array of data source description including metadata and sql.
	 * This will be used to process "data" and "explain" service commands.
	 *
	 * @param string $tableName Data source name.
	 * @param array $parameters Input parameters.
	 *
	 * @return array
	 */
	public function getData(string $tableName, array $parameters): array
	{
		$connector = $this->getDataSourceConnector($tableName);
		if (!$connector)
		{
			return [];
		}

		return $connector->getFormattedData($parameters, static::$dateFormats);
	}

	/**
	 * @param string $tableName
	 * @param array $parameters
	 * @param string $requestMethod
	 * @param string $requestUri
	 * @param int $limit
	 * @param LimitManager $limitManager
	 * @param array|null $queryMetadata Query metadata from Superset/Trino (bx_query_metadata).
	 *
	 * @return Result
	 */
	public function printQuery(
		string $tableName,
		array $parameters,
		string $requestMethod,
		string $requestUri,
		int $limit,
		LimitManager $limitManager,
		?array $queryMetadata = null,
	): Result
	{
		return new Result();
	}
}
