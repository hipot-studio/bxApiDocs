<?php

namespace Bitrix\BIConnector\DataSourceConnector\Connector;

use Bitrix\BIConnector\DataSourceConnector\Aggregation;
use Bitrix\BIConnector\DataSourceConnector\ConnectorDataResult;
use Bitrix\BIConnector\DataSourceConnector\ConnectorDto;
use Bitrix\BIConnector\DataSourceConnector\FieldCollection;
use Bitrix\BIConnector\DataSourceConnector\SqlTimeLimit;
use Bitrix\BIConnector\DB\BiAggregationDialectInterface;
use Bitrix\BIConnector\Manager;
use Bitrix\Main\Analytics\AnalyticsEvent;
use Bitrix\Main\Config\ConfigurationException;
use Bitrix\Main\Error;
use Bitrix\Main\DB;

abstract class Base
{
	protected const ANALYTIC_TAG_DATASET = 'other';

	/**
	 * Field types the date channel can filter on.
	 *
	 * Public so that a test can enumerate them from here rather than repeat the list: adding a type has to
	 * force new coverage instead of quietly inheriting the behaviour of the existing ones.
	 */
	public const DATE_FILTER_FIELD_TYPES = ['date', 'datetime'];

	/** Open-ended upper bound of the date channel; the day after it is not representable. */
	private const MAX_END_DATE = '9999-12-31';

	private const SECONDS_IN_DAY = 86400;

	protected ?int $sqlTimeLimitSeconds = null;

	protected ?DB\Connection $activeQueryConnection = null;

	/** @var callable|null */
	protected $abortCheck = null;

	/**
	 * Rules that made it into the filter, collected while it is assembled and confirmed only once the
	 * SQL has compiled.
	 */
	private ?Aggregation\AppliedWhere $appliedWhere = null;

	/** @var array<string, array> One prepared answer per argument set, see getFormattedData(). */
	private array $formattedData = [];

	/**
	 * Date formats of the answer being prepared.
	 *
	 * They decide whether the select list converts a field into the data timezone, so the filter needs the
	 * very same values to invert the very same decision.
	 */
	private array $dateFormats = [];

	public function __construct(
		protected string $name,
		protected FieldCollection $fields,
		public readonly array $rawInfo,
	)
	{
	}

	/**
	 * @return DB\Connection|null
	 * @throws ConfigurationException
	 */
	protected function getConnection(): ?DB\Connection
	{
		$manager = Manager::getInstance();

		return $manager->getDatabaseConnection();
	}

	public function setSqlTimeLimitSeconds(?int $seconds): void
	{
		$this->sqlTimeLimitSeconds = ($seconds !== null && $seconds > 0) ? $seconds : null;
	}

	public function setAbortCheck(?callable $abortCheck): void
	{
		$this->abortCheck = $abortCheck;
	}

	protected function shouldAbort(): bool
	{
		return $this->abortCheck !== null && ($this->abortCheck)();
	}

	public function abortActiveQuery(): void
	{
		if (!$this->activeQueryConnection)
		{
			return;
		}

		$connection = $this->activeQueryConnection;
		$this->activeQueryConnection = null;

		try
		{
			$connection->disconnect();
		}
		catch (\Throwable $e)
		{
		}

		if (!$connection->isPersistent())
		{
			SqlTimeLimit::forgetRestore($connection);
		}
	}

	protected function requiresPinnedExecutorConnection(): bool
	{
		return $this->sqlTimeLimitSeconds !== null || $this->abortCheck !== null;
	}

	protected function applySqlTimeLimit(DB\Connection $connection): bool
	{
		if ($this->sqlTimeLimitSeconds === null)
		{
			return true;
		}

		return SqlTimeLimit::apply($connection, $this->sqlTimeLimitSeconds);
	}

	protected function restoreSqlTimeLimit(): void
	{
		if ($this->activeQueryConnection)
		{
			SqlTimeLimit::restore($this->activeQueryConnection);
		}
	}

	/**
	 * @return string
	 */
	public function getName(): string
	{
		return $this->name;
	}

	/**
	 * @return FieldCollection
	 */
	public function getFields(): FieldCollection
	{
		return $this->fields;
	}

	/**
	 * @param array $parameters
	 * @param array $dateFormats
	 *
	 * @return array|string[]
	 */
	public function getFormattedData(array $parameters, array $dateFormats = []): array
	{
		// One request prepares the answer twice: once to publish the SQL and the confirming fingerprints,
		// once to execute it. Recomputing would produce a second description snapshot, and the headers
		// would then confirm something other than the body that was sent - the very failure those headers
		// exist to rule out. The result is immutable, so returning the first one is the whole fix.
		$cacheKey = md5(serialize([$parameters, $dateFormats]));
		if (isset($this->formattedData[$cacheKey]))
		{
			return $this->formattedData[$cacheKey];
		}

		$connectorDataResult = $this->getData($parameters, $dateFormats);
		if (!$connectorDataResult->isSuccess())
		{
			return $this->formattedData[$cacheKey] = [
				'error' => $connectorDataResult->getErrorMessages()[0],
			];
		}

		$connectorData = $connectorDataResult->getConnectorData();

		return $this->formattedData[$cacheKey] = [
			'schema' => $connectorData->schema,
			'sql' => $connectorData->query,
			'onAfterFetch' => $connectorData->queryRowCallbacks,
			'filtersApplied' => $connectorData->filtersApplied,
			'where' => $connectorData->filter,
			'shadowFields' => $connectorData->shadowFields,
			'aggregationApplied' => $connectorData->aggregationApplied,
			'aggregationFingerprint' => $connectorData->aggregationFingerprint,
			'whereFingerprint' => $connectorData->whereFingerprint,
			'usageStatFields' => $connectorData->usageStatFields,
		];
	}

	/**
	 * @param array $parameters
	 * @param array $dateFormats
	 *
	 * @return ConnectorDataResult
	 */
	public function getData(array $parameters, array $dateFormats = []): ConnectorDataResult
	{
		$result = new ConnectorDataResult();

		$tableInfo = $this->rawInfo;
		$tableFields = $tableInfo['FIELDS'] ?? [];
		$this->dateFormats = $dateFormats;

		$canBeFiltered = true;
		$sqlWhere = $tableInfo['FILTER'] ?? [];
		$this->appliedWhere = Aggregation\AppliedWhere::createEmpty();

		if (array_key_exists('dateRange', $parameters) && !is_array($parameters['dateRange']))
		{
			// The range was asked for in a shape that carries no bounds, so nothing is applied and the
			// answer must not be reported as filtered.
			$canBeFiltered = false;
		}

		if (isset($parameters['dateRange']) && is_array($parameters['dateRange']))
		{
			if (isset($parameters['configParams']['timeFilterColumn']))
			{
				$timeFilterColumn = $parameters['configParams']['timeFilterColumn'];
			}
			elseif (isset($parameters['configParams']['timefiltercolumn']))
			{
				$timeFilterColumn = $parameters['configParams']['timefiltercolumn'];
			}
			else
			{
				$timeFilterColumn = '';
			}

			$sqlWhere = $this->applyDateFilter(
				$sqlWhere,
				$parameters['dateRange'],
				$timeFilterColumn,
				$canBeFiltered,
			);
		}

		// https://developers.google.com/datastudio/connector/filters?hl=ru
		if (isset($parameters['dimensionsFilters']) && is_array($parameters['dimensionsFilters']))
		{
			$sqlWhere = $this->applyDimensionsFilters($sqlWhere, $canBeFiltered, $parameters['dimensionsFilters']);
		}

		$queryWhere = new \CBIConnectorSqlBuilder();
		$connection = $this->getConnection();
		if ($connection)
		{
			$queryWhere->setConnection($connection);
		}

		$queryWhere->SetFields($tableFields);
		if (isset($tableInfo['FILTER_FIELDS']))
		{
			$queryWhere->AddFields($tableInfo['FILTER_FIELDS']);
		}

		$strQueryWhere = '';
		if ($canBeFiltered && $sqlWhere)
		{
			try
			{
				$strQueryWhere = $queryWhere->GetQuery($sqlWhere);
			}
			catch (\Throwable)
			{
				$strQueryWhere = '';
			}
		}

		// The confirmation is issued strictly after compilation. An empty statement means the filter was
		// not printed - at `$canBeFiltered = false`, on an empty compilation or on an exception - and in
		// that case the header carries the fingerprint of the empty WHERE, never of the collected rules.
		if ($strQueryWhere === '')
		{
			$this->appliedWhere = Aggregation\AppliedWhere::createEmpty();
		}

		// array_key_exists and not isset: a present `aggregation: null` is a value the schema forbids,
		// so it must produce a protocol error instead of quietly falling through to the raw path.
		if (array_key_exists('aggregation', $parameters))
		{
			return $this->getAggregatedData(
				$parameters,
				$queryWhere,
				$strQueryWhere,
				$sqlWhere,
				$canBeFiltered,
			);
		}

		$selectedFields = [];
		$groupFields = [];
		if (isset($parameters['fields']) && is_array($parameters['fields']))
		{
			foreach ($parameters['fields'] as $field)
			{
				if (!is_array($field))
				{
					$result->addError(new Error('INVALID_SELECT_FIELDS_FORMAT'));

					return $result;
				}

				$fieldName = trim($field['name'] ?? '', " \t\n\r");
				if ($fieldName && isset($tableFields[$fieldName]))
				{
					$tableField = $tableFields[$fieldName];
					if (
						(!isset($field['forFilterOnly']) || !$field['forFilterOnly'])
						|| !($strQueryWhere && $canBeFiltered)
					)
					{
						if (isset($tableField['GROUP_KEY']))
						{
							$groupKey = $tableField['GROUP_KEY'];
							$groupFields[$groupKey] = $tableFields[$groupKey];
							$groupFields[$groupKey]['ID'] = $groupKey;
						}
						$selectedFields[$fieldName] = $tableField;
					}
				}
			}
			if (!$selectedFields)
			{
				$result->addError(new Error('EMPTY_SELECT_FIELDS_LIST'));

				return $result;
			}
		}
		else
		{
			$selectedFields = $tableFields;
		}

		$primaryFields = [];
		if ($groupFields)
		{
			foreach ($tableFields as $fieldName => $tableField)
			{
				if (isset($tableField['IS_PRIMARY']) && $tableField['IS_PRIMARY'] == 'Y')
				{
					$tableField['ID'] = $fieldName;
					$primaryFields[$fieldName] = $tableField;
				}
			}
		}

		foreach ($groupFields as $groupKey => $_)
		{
			if (isset($selectedFields[$groupKey]))
			{
				unset($groupFields[$groupKey]);
			}
		}

		foreach ($primaryFields as $primaryKey => $_)
		{
			if (isset($selectedFields[$primaryKey]))
			{
				unset($primaryFields[$primaryKey]);
			}
		}

		$shadowFields = array_merge($primaryFields, $groupFields);

		$queryWhere->SetSelect(array_merge($selectedFields, $shadowFields), $dateFormats);

		$i = 0;
		$fetchCallbacks = [];
		foreach (array_merge($selectedFields, $shadowFields) as $fieldInfo)
		{
			if (isset($fieldInfo['CALLBACK']))
			{
				$fetchCallbacks[$i] = $fieldInfo['CALLBACK'];
			}
			elseif ($fieldInfo['FIELD_TYPE'] === 'int')
			{
				$fetchCallbacks[$i] = static fn ($value) => $value === null ? null : (int)$value;
			}

			$i++;
		}

		$schemaFields = $this->getFields()->toArray();

		$schema = [];
		foreach (array_keys($selectedFields) as $fieldName)
		{
			foreach ($schemaFields as $fieldInfo)
			{
				if ($fieldName === $fieldInfo['ID'])
				{
					$schema[] = $fieldInfo;
				}
			}
		}

		if (isset($tableInfo['DICTIONARY']))
		{
			foreach ($tableInfo['DICTIONARY'] as $dictionaryId)
			{
				if (!\Bitrix\BIConnector\DictionaryManager::validateCache($dictionaryId))
				{
					$result->addError(new Error("DICTIONARY_{$dictionaryId}_UPDATE"));

					return $result;
				}
			}
		}

		$query = $this->getPrintedQuery(
			$queryWhere,
			$tableInfo,
			($canBeFiltered && $sqlWhere) ? $sqlWhere : null,
			$parameters,
		);

		// A request that carries no filter has nothing left to apply, so it is confirmed rather than
		// refused: the empty condition is applied exactly. What must not be confirmed is a filter that
		// was asked for and did not reach the statement - refused while it was assembled, or lost when
		// the statement failed to compile.
		$filtersApplied = $canBeFiltered && ($strQueryWhere !== '' || !$sqlWhere);

		$dto = new ConnectorDto(
			$schema,
			$query,
			$fetchCallbacks,
			$sqlWhere,
			$filtersApplied,
			$shadowFields,
			whereFingerprint: $this->getAppliedWhereFingerprint(),
		);

		$result->setConnectorData($dto);

		return $result;
	}

	/**
	 * Drops what was prepared for one logical request.
	 *
	 * The prepared answer is reused so that the headers describe the body they were computed for; that
	 * reuse must not reach the next request, and the map of prepared answers must not grow without bound
	 * in a long running process.
	 */
	public function beginRequest(): void
	{
		$this->formattedData = [];
		$this->appliedWhere = null;
	}

	/**
	 * Aggregation capabilities of the dataset for `?desc&caps=1` (design §2.1).
	 *
	 * A connector that cannot delegate aggregation returns the unsupported block; the default is to
	 * not support it, so a new connector type never advertises aggregation by accident.
	 */
	public function getAggregationCapabilities(): Aggregation\Capabilities
	{
		return Aggregation\Capabilities::unsupported();
	}

	/**
	 * Whether this connector has an executor for a delegated aggregation (design §6.2).
	 *
	 * Asked exactly where the acceptance of the block used to ask for the aggregation dialect, which is
	 * before the block is parsed: on a dataset without an executor a syntactically broken block has to
	 * keep answering AGGREGATION_UNSUPPORTED rather than AGGREGATION_BAD_REQUEST.
	 *
	 * The default is no delegation, so a new connector type never starts delegating by accident.
	 */
	protected function canDelegateAggregation(): bool
	{
		return false;
	}

	/**
	 * Turns an accepted block into the executable form of this connector (design §6.2).
	 *
	 * The pre-aggregation WHERE and the joins are the ones of the raw path, which is what makes the
	 * aggregate equal to the one computed over the raw rows. The response schema, the fingerprints and
	 * the list of touched fields are formed by the caller, not here.
	 *
	 * @param Aggregation\Request $request Block already parsed and validated against the capabilities.
	 * @param Aggregation\DatasetSchema $schema Schema of the dataset the block is addressed to.
	 * @param string $whereClause Compiled pre-aggregation predicate, empty when there is no filter.
	 * @param string $joins Compiled join list of the raw path, empty when there are none.
	 *
	 * @return string Record of the query: the statement on the SQL path, the canonical block on a
	 *     channel that sends the block itself.
	 *
	 * @throws Aggregation\AggregationException
	 */
	protected function buildAggregatedQuery(
		Aggregation\Request $request,
		Aggregation\DatasetSchema $schema,
		string $whereClause,
		string $joins,
	): string
	{
		// The condition and the builder are one pair: a connector that answers the condition and builds
		// nothing must not delegate, even when only one half of the pair was implemented.
		throw Aggregation\AggregationException::unsupported('the connector builds no aggregated query');
	}

	/**
	 * Date formats of the answer being prepared, for the implementations of the two members above.
	 */
	protected function getDateFormats(): array
	{
		return $this->dateFormats;
	}

	/**
	 * The dialect of the underlying connection, or null when it cannot generate aggregated SQL.
	 */
	protected function getAggregationDialect(): ?BiAggregationDialectInterface
	{
		return null;
	}

	/**
	 * Opaque identity of the physical source behind this dataset (design §2.1).
	 */
	protected function getAggregationScopeId(): string
	{
		return Aggregation\ScopeId::forSystemDataset();
	}

	public function getDatasetSchema(): Aggregation\DatasetSchema
	{
		return new Aggregation\DatasetSchema($this->rawInfo, $this->getFields()->toArray());
	}

	/**
	 * Rows and bytes the source scanned for the last statement, when it reports them.
	 *
	 * Kept apart from the returned row count: with a delegated aggregation the client receives groups
	 * while the source may have scanned millions of rows, and only the latter explains the load.
	 *
	 * @return array{readRows: int, readBytes: int}|null
	 */
	public function getLastScanStatistics(): ?array
	{
		return null;
	}

	/**
	 * Accepts the block and prepares the aggregated answer instead of the row one (design §6.2).
	 *
	 * Everything that does not depend on the connector type lives here: the order of the checks, the
	 * structural parse, the validation against the capabilities, the response schema and the
	 * fingerprints. Only the executable form of the block is built by the connector itself, through
	 * buildAggregatedQuery().
	 */
	private function getAggregatedData(
		array $parameters,
		\CBIConnectorSqlBuilder $queryWhere,
		string $strQueryWhere,
		array $sqlWhere,
		bool $canBeFiltered = true,
	): ConnectorDataResult
	{
		$result = new ConnectorDataResult();

		try
		{
			if (!\Bitrix\BIConnector\Configuration\Feature::isAggregationPushdownEnabled())
			{
				throw Aggregation\AggregationException::unsupported('feature is disabled');
			}

			// A filter that was asked for and did not reach the statement cannot be made up for above a
			// group: the rows are already rolled up, and nothing on the way back can re-apply it. The
			// aggregation is therefore not delegated at all, and the consumer computes it over the rows
			// it filters itself - slower, and right.
			//
			// Both readings of "not applied" are refused: a rule the filter refused while it was being
			// assembled, and a filter that was assembled and did not compile.
			if (!$canBeFiltered || ($sqlWhere && $strQueryWhere === ''))
			{
				throw Aggregation\AggregationException::unsupported(
					'the pre-aggregation filter was not applied'
				);
			}

			// Asked here and not below the parse: the refusal of a connector that cannot delegate has to
			// reach the client before the block is read, so the code of a broken block stays the one of
			// the missing executor.
			if (!$this->canDelegateAggregation())
			{
				throw Aggregation\AggregationException::unsupported('the connector does not delegate aggregation');
			}

			$schema = $this->getDatasetSchema();
			$capabilities = $this->getAggregationCapabilities();

			$request = Aggregation\Request::fromArray($parameters['aggregation']);
			(new Aggregation\Validator($schema, $capabilities))->validate($request, $this->getName());

			$touchedFields = $this->collectTouchedFields($request);
			foreach ($touchedFields as $fieldName)
			{
				$queryWhere->RegisterSelectedField($fieldName, $schema->getFieldInfo($fieldName));
			}

			$query = $this->buildAggregatedQuery($request, $schema, $strQueryWhere, $queryWhere->GetJoins());

			$responseSchema = [];
			foreach ($request->getResponseAliases() as $alias)
			{
				$responseSchema[] = ['ID' => $alias];
			}

			$result->setConnectorData(new ConnectorDto(
				$responseSchema,
				$query,
				Aggregation\ValueSerializer::buildCallbacks($request),
				$sqlWhere,
				// Same reading as on the raw path: an absent filter is applied exactly, a filter that was
				// asked for and did not reach the statement is not.
				$strQueryWhere !== '' || !$sqlWhere,
				[],
				true,
				$request->getFingerprint(),
				$this->getAppliedWhereFingerprint(),
				$touchedFields,
			));
		}
		catch (Aggregation\AggregationException $exception)
		{
			$result->addError(new Error($exception->getErrorCode()));
		}

		return $result;
	}

	/**
	 * Values of a rule as the request carried them.
	 *
	 * An operator that takes no argument, such as IS_NULL, must yield an empty list rather than a list
	 * holding one empty string: the difference is part of the fingerprint.
	 *
	 * @return string[]
	 */
	private static function normalizeRuleValues(mixed $values): array
	{
		if ($values === null || $values === '')
		{
			return [];
		}

		return array_map('strval', is_array($values) ? array_values($values) : [$values]);
	}

	/**
	 * Fingerprint of the WHERE that actually compiled; the empty WHERE when nothing was printed.
	 */
	private function getAppliedWhereFingerprint(): string
	{
		return ($this->appliedWhere ?? Aggregation\AppliedWhere::createEmpty())->getFingerprint();
	}

	/**
	 * @return string[]
	 */
	private function collectTouchedFields(Aggregation\Request $request): array
	{
		$fields = [];
		foreach ($request->groupBy as $item)
		{
			$fields[$item->field] = true;
		}
		foreach ($request->aggregates as $item)
		{
			if ($item->field !== null)
			{
				$fields[$item->field] = true;
			}
		}

		return array_keys($fields);
	}

	/**
	 * @param \CBIConnectorSqlBuilder $builder
	 * @param array $tableInfo
	 * @param array|null $where
	 *
	 * @return string
	 */
	protected function getPrintedQuery(
		\CBIConnectorSqlBuilder $builder,
		array $tableInfo,
		?array $where = null,
		?array $queryParameters = null,
	): string
	{
		return '';
	}

	/**
	 * applyDateFilter
	 *
	 * @param array $sqlWhere Modified where.
	 * @param array $dateRange Filters from input.
	 * @param string $timeFilterColumn Column from input.
	 * @param bool &$canBeFiltered Cleared when the requested range is not applied as asked.
	 *
	 * @return array
	 */
	protected function applyDateFilter(
		array $sqlWhere,
		array $dateRange,
		string $timeFilterColumn = '',
		bool &$canBeFiltered = true,
	): array
	{
		$tableFields = $this->rawInfo['FIELDS'] ?? [];

		$startDate =
			array_key_exists('startDate', $dateRange)
				? MakeTimeStamp($dateRange['startDate'], 'YYYY-MM-DD')
				: false
		;

		// A range that carries no bound at all applies nothing, so the answer is not reported as
		// filtered. A single unreadable bound is left to the other one, which still narrows the answer.
		if (!array_key_exists('startDate', $dateRange) && !array_key_exists('endDate', $dateRange))
		{
			$canBeFiltered = false;
		}

		$filterColumnName = false;
		if (
			$timeFilterColumn
			&& array_key_exists($timeFilterColumn, $tableFields)
			&& in_array($tableFields[$timeFilterColumn]['FIELD_TYPE'], self::DATE_FILTER_FIELD_TYPES, true)
		)
		{
			$filterColumnName = $timeFilterColumn;
		}
		else
		{
			foreach ($tableFields as $fieldName => $fieldInfo)
			{
				if (in_array($fieldInfo['FIELD_TYPE'], self::DATE_FILTER_FIELD_TYPES, true))
				{
					$filterColumnName = $fieldName;

					break;
				}
			}
		}

		if (!$filterColumnName)
		{
			// A range was asked for on a dataset that has no date column at all.
			$canBeFiltered = false;

			return $sqlWhere;
		}

		$dataTimezone = \Bitrix\BIConnector\Configuration\DataTimezone::getTimezone();

		if ($startDate)
		{
			$startDate -= $this->getDateRangeShift($dataTimezone, $tableFields[$filterColumnName] ?? []);
			$sqlWhere['>=' . $filterColumnName] = ConvertTimeStamp($startDate, 'FULL');
		}

		$endDate =
			array_key_exists('endDate', $dateRange)
				? MakeTimeStamp($dateRange['endDate'], 'YYYY-MM-DD')
				: false
		;
		if ($endDate)
		{
			if (($dateRange['endDate'] ?? null) === self::MAX_END_DATE)
			{
				// The day after the sentinel is not representable, so the closed form is kept here. The
				// capability declares this date inexact, so no aggregation is delegated behind it.
				$endDate = MakeTimeStamp(self::MAX_END_DATE . ' 23:59:59', 'YYYY-MM-DD HH:MI:SS');
				$sqlWhere['<=' . $filterColumnName] = ConvertTimeStamp($endDate, 'FULL');
			}
			else
			{
				// Half-open upper bound, the single normative form: the previous `<= D 23:59:59` dropped
				// a row at `D 23:59:59.500` when the physical column carries fractional seconds, while
				// Trino keeps it because the wire value it sees is truncated to the second.
				$endDate += self::SECONDS_IN_DAY;
				$endDate -= $this->getDateRangeShift($dataTimezone, $tableFields[$filterColumnName] ?? []);
				$sqlWhere['<' . $filterColumnName] = ConvertTimeStamp($endDate, 'FULL');
			}
		}

		// Recorded with the column actually chosen: when the requested one is missing or not date-like,
		// the portal silently falls back to the first date column, and the plugin has to see that.
		$this->appliedWhere?->setDateRange(
			(string)($dateRange['startDate'] ?? ''),
			(string)($dateRange['endDate'] ?? ''),
			(string)$filterColumnName,
		);

		return $sqlWhere;
	}

	/**
	 * The shift the boundaries of `dateRange` are inverted by.
	 *
	 * It must be the very shift the select list applies, because the client re-checks the predicate
	 * against the value it received, not against the absolute instant. The select expression converts
	 * every row by one fixed offset - `DataTimezone::getTimezoneOffset()` is evaluated once, at the
	 * moment of the request - so the filter has to invert that same fixed offset.
	 *
	 * Using the historical offset of each boundary instead makes the filter right in isolation and wrong
	 * against the projection: in a zone with daylight saving, a January row converted with the summer
	 * offset lands inside the requested day for the client while the boundary inverted with the winter
	 * offset excludes it, and the row disappears before aggregation. The two sides have to agree; being
	 * jointly offset out of season is visible only in the displayed time, and correcting that means
	 * converting the projection by the named zone per row - a change to the shipped raw path, not to
	 * this filter.
	 */
	private function getDateRangeShift(?string $timezone, array $fieldInfo): int
	{
		if (!$timezone || !$this->isNeedApplyTimezoneOffset())
		{
			return 0;
		}

		// The one decision, taken where the projection takes it. A `date` field is projected without any
		// conversion, so subtracting a shift from its boundaries would filter on values the consumer never
		// sees - and the rows it drops are invisible in the answer.
		if (!\CBIConnectorSqlBuilder::AppliesTimezoneConversion($fieldInfo, $this->dateFormats))
		{
			return 0;
		}

		return \CTimeZone::getTimezoneOffset($timezone);
	}

	/** Field types whose values have to be numbers before they reach the statement. */
	private const NUMERIC_FIELD_TYPES = ['int', 'double'];

	/** Operators that carry no value of their own. */
	private const VALUELESS_OPERATORS = ['IS_NULL'];

	/**
	 * Decides whether a rule can be turned into a condition at all.
	 *
	 * A rule that cannot is dropped and the whole filter is reported as not applied, so the consumer
	 * keeps filtering the rows itself. What is checked here is only what the portal would otherwise
	 * turn into a condition nobody asked for: an unknown negation reads as its opposite, a missing or
	 * nested value collapses into the literal it is cast to, and a value that is not a number reaches
	 * a numeric column as one.
	 *
	 * A rule with no type, or with a null one, keeps the historical reading, INCLUDE. A rule naming a
	 * field the dataset does not describe is not judged here: it is skipped where the field is looked
	 * up, which is the reading the golden and the legacy regression sets pin down.
	 *
	 * @param array $rule Rule as received.
	 * @param array $fieldInfo Description of the field the rule names.
	 *
	 * @return bool
	 */
	private static function isRuleAcceptable(array $rule, array $fieldInfo): bool
	{
		$fieldType = (string)($fieldInfo['FIELD_TYPE'] ?? '');

		$type = $rule['type'] ?? null;
		if ($type !== null && $type !== 'INCLUDE' && $type !== 'EXCLUDE')
		{
			return false;
		}

		$operator = $rule['operator'] ?? null;
		if (!is_string($operator))
		{
			return false;
		}

		if (in_array($operator, self::VALUELESS_OPERATORS, true))
		{
			return true;
		}

		$values = $rule['values'] ?? null;
		$values = is_array($values) ? $values : [$values];
		if (!$values)
		{
			return false;
		}

		$numeric = in_array($fieldType, self::NUMERIC_FIELD_TYPES, true);
		foreach ($values as $value)
		{
			// Booleans and nulls are not values of this protocol: both are cast to a string the
			// consumer never sent, and an array is cast to the literal 'Array'.
			if (!is_string($value) && !is_int($value) && !is_float($value))
			{
				return false;
			}

			if ($numeric && !is_numeric($value))
			{
				return false;
			}
		}

		return true;
	}

	/**
	 * applyDimensionsFilters
	 *
	 * @param array $sqlWhere Modified where.
	 * @param bool &$canBeFiltered Return flag.
	 * @param array $dimensionsFilters Filters from input.
	 *
	 * @return array
	 */
	/**
	 * Brings the values of a date or datetime rule to the form the portal compares in.
	 *
	 * @param mixed $values Value or list of values as received.
	 * @param string $format ConvertTimeStamp format: FULL for datetime, SHORT for date.
	 *
	 * @return array|string|null Converted values, or null when any of them names no moment.
	 */
	private static function convertFilterDateValues(mixed $values, string $format): array|string|null
	{
		if (is_array($values))
		{
			$converted = [];
			foreach ($values as $i => $value)
			{
				if (!is_string($value) && !is_int($value))
				{
					return null;
				}

				$timestamp = strtotime((string)$value);
				if ($timestamp === false)
				{
					return null;
				}

				$converted[$i] = ConvertTimeStamp($timestamp, $format);
			}

			return $converted;
		}

		if (!is_string($values) && !is_int($values))
		{
			return null;
		}

		$timestamp = strtotime((string)$values);

		return $timestamp === false ? null : ConvertTimeStamp($timestamp, $format);
	}

	protected function applyDimensionsFilters($sqlWhere, &$canBeFiltered, $dimensionsFilters): array
	{
		$tableFields = $this->rawInfo['FIELDS'] ?? [];

		foreach ($dimensionsFilters as $topFilter)
		{
			// The filter arrives from the request, so its shape is not given: a group that is not a list
			// of rules cannot be read at all. Reading it as one used to raise a TypeError and answer the
			// request with a server error, while the condition itself is simply unproven - the filter is
			// dropped and the consumer is told it was not applied.
			if (!is_array($topFilter))
			{
				$canBeFiltered = false;

				continue;
			}

			$andFilter = [
				'LOGIC' => 'OR',
			];
			$appliedRules = [];

			foreach ($topFilter as $subFilter)
			{
				if (!is_array($subFilter) || !is_string($subFilter['fieldName'] ?? null))
				{
					$canBeFiltered = false;

					continue;
				}

				// A rule naming a field the dataset does not describe is skipped while the rest of the filter
				// still applies: that reading is pinned down by the golden and the legacy regression sets.
				if (!isset($tableFields[$subFilter['fieldName']]))
				{

					continue;
				}

				if (!self::isRuleAcceptable($subFilter, $tableFields[$subFilter['fieldName']]))
				{
					$canBeFiltered = false;

					continue;
				}

				// Kept before the portal rewrites date values: the plugin reproduces the form it sent,
				// and that is what the two fingerprints compare.
				$receivedValues = $subFilter['values'] ?? [];
				$ruleCountBefore = count($andFilter);

				if ($tableFields[$subFilter['fieldName']]['FIELD_TYPE'] === 'datetime')
				{
					$converted = self::convertFilterDateValues($subFilter['values'] ?? null, 'FULL');
					if ($converted === null)
					{
						// A value that names no moment cannot be sent: strtotime() answers false and
						// ConvertTimeStamp(false) prints the current time, so the condition would be
						// applied against a value nobody asked for while the answer is reported as
						// filtered. The rule is dropped and the filter is reported as not applied.
						$canBeFiltered = false;

						continue;
					}

					$subFilter['values'] = $converted;
				}
				elseif ($tableFields[$subFilter['fieldName']]['FIELD_TYPE'] === 'date')
				{
					$converted = self::convertFilterDateValues($subFilter['values'] ?? null, 'SHORT');
					if ($converted === null)
					{
						$canBeFiltered = false;

						continue;
					}

					$subFilter['values'] = $converted;
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

				// Collected only when the rule actually produced a filter entry. A rule skipped for an
				// unknown field or an unknown operator must not appear in the confirmation.
				if (count($andFilter) > $ruleCountBefore)
				{
					$appliedRules[] = Aggregation\AppliedWhere::rule(
						(string)$subFilter['fieldName'],
						(string)$subFilter['operator'],
						(string)($subFilter['type'] ?? 'INCLUDE'),
						self::normalizeRuleValues($receivedValues),
					);
				}
			}
			if (count($andFilter) > 1)
			{
				$sqlWhere[] = $andFilter;
				// The nesting is preserved: rules of a group are combined with OR and the groups with AND,
				// so a flat list would let two different predicates share one fingerprint.
				$this->appliedWhere?->addGroup($appliedRules);
			}
		}

		return $sqlWhere;
	}

	/**
	 * Extra query parameters used when building the preview (e.g. for the dataset import slider).
	 *
	 * By default returns no extra parameters, so the preview shows the first N rows.
	 * Connectors that fetch data from an external API (e.g. expenses) override this
	 * to load a recent time window instead.
	 *
	 * @return array
	 */
	public function getPreviewParameters(): array
	{
		return [];
	}

	/**
	 * @param array $parameters
	 * @param int $limit
	 * @param array $dateFormats
	 *
	 * @return \Generator
	 */
	abstract public function query(
		array $parameters,
		int $limit,
		array $dateFormats = [],
	): \Generator;

	/**
	 * Is source response prepared for trino and does it not require decoding and other preparations.
	 *
	 * @return bool
	 */
	public function isFulfilledOutput(): bool
	{
		return false;
	}

	/**
	 * @return bool
	 */
	protected function isNeedApplyTimezoneOffset(): bool
	{
		return true;
	}

	/**
	 * @return void
	 */
	public function sendAnalytic(): void
	{
		$event = new AnalyticsEvent('data_request', 'BI_Builder', 'Connector');
		$datasetName = $this->reformatSnakeToCamelCase($this->name);
		$event
			->setSection('BI_Connector')
			->setP1($datasetName)
			->setP2(static::ANALYTIC_TAG_DATASET)
			->send()
		;

	}

	private function reformatSnakeToCamelCase(string $text): string
	{
		$parts = explode('_', $text);

		$camelCase = array_shift($parts);
		$camelCase .= implode('', array_map('ucfirst', $parts));

		return $camelCase;
	}
}
