<?php

namespace Bitrix\BIConnector\ExternalSource\Dataset\Connector;

use Bitrix\BIConnector\Configuration\DataTimezone;
use Bitrix\BIConnector\Configuration\Feature;
use Bitrix\BIConnector\DataSourceConnector\Aggregation;
use Bitrix\BIConnector\DataSourceConnector\FieldCollection;
use Bitrix\BIConnector\ExternalSource\Dataset\Capabilities\RestCapabilitiesProvider;
use Bitrix\BIConnector\ExternalSource\DatasetManager;
use Bitrix\BIConnector\ExternalSource\Internal\ExternalDataset;
use Bitrix\BIConnector\ExternalSource\Internal\ExternalDatasetTable;
use Bitrix\BIConnector\ExternalSource\Internal\ExternalSourceRestConnector;
use Bitrix\BIConnector\ExternalSource\Internal\ExternalSourceRestTable;
use Bitrix\BIConnector\DataSourceConnector\Connector\Base;
use Bitrix\BIConnector\ExternalSource\Source;
use Bitrix\BIConnector\ExternalSource;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

final class Rest extends Base
{
	protected const ANALYTIC_TAG_DATASET = 'REST';

	/**
	 * The dataset, the connector and the field map are looked up once and kept: the capabilities are
	 * asked for both when the description is published and on every data response.
	 */
	private bool $datasetLoaded = false;
	private bool $connectorLoaded = false;
	private ?ExternalDataset $dataset = null;
	private ?ExternalSourceRestConnector $connector = null;
	private ?int $sourceId = null;

	/** @var array<string, string>|null EXTERNAL_CODE => NAME of the same table. */
	private ?array $mapFields = null;

	/**
	 * Memoized capabilities answer: the delegation predicate and the common part of the aggregated
	 * branch both ask for it while one answer is being prepared, and every ask resolves the connection
	 * row anew. The block is immutable, so handing out the first one is the whole fix.
	 */
	private ?Aggregation\Capabilities $aggregationCapabilities = null;

	/**
	 * @param RestCapabilitiesProvider|null $capabilitiesProvider Source of the capabilities declaration.
	 *     Resolved from the connector row when not supplied.
	 * @param Source\Rest|null $transport Transport of the channel. Resolved from the connection behind
	 *     the dataset when not supplied.
	 */
	public function __construct(
		string $name,
		FieldCollection $fields,
		array $rawInfo,
		private readonly ?RestCapabilitiesProvider $capabilitiesProvider = null,
		private readonly ?Source\Rest $transport = null,
	)
	{
		parent::__construct($name, $fields, $rawInfo);
	}

	/**
	 * @param array $parameters
	 * @param int $limit
	 * @param array $dateFormats
	 * @return \Generator
	 */
	public function query(
		array $parameters,
		int $limit,
		array $dateFormats = []
	): \Generator
	{
		$result = new Result();

		// The prepared answer and not getData(): it is memoized by the very arguments this method
		// receives and is already computed by the time the body is asked for, so the body is bound to
		// the description the confirming headers were issued from instead of to a second snapshot of it.
		$data = $this->getFormattedData($parameters, $dateFormats);
		if (isset($data['error']))
		{
			$result->addError(new Error((string)$data['error']));

			return $result;
		}

		$this->loadDataset();
		$tableCode = (string)($this->dataset?->getExternalCode() ?? '');

		$schema = $data['schema'] ?? [];
		$filter = $data['where'] ?? [];
		$isAggregated = $data['aggregationApplied'] ?? false;

		// The aggregated request sends no select: the schema of that answer holds protocol aliases and no
		// field names. The filter is the very one of the row path - an aggregate computed over other rows
		// than the row path would have returned is a wrong number, and the confirming header would not
		// notice it. Neither `limit` nor `timezone` travels: groups are truncated by
		// `aggregation.groupLimit` alone, and dates are truncated over the values as they are.
		//
		// `mapFields` is the exception: the transport puts it in the envelope of both paths, and this
		// instance resolves it once, so the map is handed over instead of being asked of the catalog of
		// fields a second time.
		$queryFields = $isAggregated
			? [
				'filter' => $filter,
				'aggregation' => $parameters['aggregation'] ?? [],
				'mapFields' => $this->getMapFields($tableCode),
			]
			: [
				'select' => array_column($schema, 'NAME'),
				'filter' => $filter,
				'limit' => $limit,
				'timezone' => DataTimezone::getTimezone(),
				'mapFields' => $this->getMapFields($tableCode),
			]
		;

		try
		{
			/* @var ExternalSource\Source\Rest $source */
			$source = $this->transport
				?? Source\Factory::getSource(ExternalSource\Type::Rest, $this->sourceId ?? 0)
			;
			if (!$isAggregated)
			{
				yield $source->getData($tableCode, $queryFields);

				return $result;
			}

			$body = $source->getAggregatedData($tableCode, $queryFields);

			// Nothing is emitted until the gate has accepted the answer whole (ALG-02). The response
			// columns are the aliases of the block, and the prepared answer already holds them in wire
			// order - AVG expanded into its pair included.
			$gate = new Aggregation\ResponseHeaderGate(array_column($schema, 'ID'));
			$rows = $gate->verify($body);

			$result->setData([
				// The body is printed as it came, so the print path counts no rows and the count of the
				// gate is the only one there is.
				'count' => $rows,
				'aggregationApplied' => true,
			]);

			yield $body;
		}
		catch (\Throwable $e)
		{
			// \Throwable and not \Exception: an \Error raised while the body is being obtained would
			// otherwise leave the generator with neither a body nor a reported failure.
			$result->addError(new Error($e->getMessage()));
		}

		return $result;
	}

	/**
	 * Aggregation capabilities of a dataset served by an external REST application.
	 *
	 * Delegation is conditional: both options are on, the connector publishes both new addresses, and
	 * the application declares capabilities the portal is able to check itself (ALG-01). Any unmet
	 * condition answers the unsupported block, which leaves the row path exactly as it was.
	 */
	public function getAggregationCapabilities(): Aggregation\Capabilities
	{
		if ($this->aggregationCapabilities !== null)
		{
			return $this->aggregationCapabilities;
		}

		if (!Feature::isRestAggregationPushdownEnabled())
		{
			return $this->aggregationCapabilities = Aggregation\Capabilities::unsupported();
		}

		try
		{
			return $this->aggregationCapabilities = $this->buildDeclaredCapabilities();
		}
		catch (\Throwable)
		{
			// The block is an extra on the path of every data response, so nothing here - a catalog that
			// does not answer included - has the right to break the row path.
			return $this->aggregationCapabilities = Aggregation\Capabilities::unsupported();
		}
	}

	/**
	 * External datasets are identified by their source, so the same table name backed by a different
	 * connection yields a different scope and, through it, a different capabilities fingerprint.
	 */
	protected function getAggregationScopeId(): string
	{
		$this->loadDataset();

		return $this->sourceId === null
			? ''
			: Aggregation\ScopeId::forExternalSource($this->sourceId)
		;
	}

	/**
	 * Delegation is decided by the capabilities answer alone (TPL-01).
	 *
	 * That answer already folds every condition of the channel - both options, both published addresses,
	 * a declaration the portal is able to check - and reports the unsupported block on any failure of its
	 * own, so a second list of conditions here could only drift away from it.
	 */
	protected function canDelegateAggregation(): bool
	{
		return $this->getAggregationCapabilities()->supported;
	}

	/**
	 * The record of the query on this channel is the canonical form of the block itself (TPL-01).
	 *
	 * The application is the executor here, so there is nothing to compile: the pre-aggregation predicate
	 * reaches it the way the row path sends it - as a filter and not as compiled SQL - and this transport
	 * has no joins at all. Both compiled arguments are therefore unused.
	 *
	 * On the wire the block travels as an object inside the envelope of API-02, so this string stays a
	 * record of the request and never becomes the transport unit.
	 */
	protected function buildAggregatedQuery(
		Aggregation\Request $request,
		Aggregation\DatasetSchema $schema,
		string $whereClause,
		string $joins,
	): string
	{
		return Aggregation\CanonicalJson::encode($request->getRawBlock());
	}

	/**
	 * @return bool
	 */
	public function isFulfilledOutput(): bool
	{
		return true;
	}

	/**
	 * @return bool
	 */
	protected function isNeedApplyTimezoneOffset(): bool
	{
		return false;
	}

	private function buildDeclaredCapabilities(): Aggregation\Capabilities
	{
		$this->loadDataset();

		$connector = $this->getConnector();
		$urlCapabilities = (string)($connector?->getUrlCapabilities() ?? '');
		$urlAggregatedData = (string)($connector?->getUrlAggregatedData() ?? '');
		if ($urlCapabilities === '' || $urlAggregatedData === '')
		{
			// An address left empty means the same as an address never published: an application that
			// cannot serve both endpoints of the channel keeps the row path.
			return Aggregation\Capabilities::unsupported();
		}

		$tableCode = (string)($this->dataset?->getExternalCode() ?? '');
		if ($tableCode === '')
		{
			return Aggregation\Capabilities::unsupported();
		}

		$provider = $this->capabilitiesProvider
			?? RestCapabilitiesProvider::createForConnection($this->sourceId ?? 0, $urlCapabilities)
		;

		return Aggregation\Capabilities::fromDeclaration(
			$provider->getDeclaration($tableCode),
			$this->getDatasetSchema(),
			$this->getMapFields($tableCode),
			$this->getAggregationScopeId(),
		);
	}

	private function loadDataset(): void
	{
		if ($this->datasetLoaded)
		{
			return;
		}

		$this->datasetLoaded = true;

		$dataset = ExternalDatasetTable::getList([
			'filter' => [
				'=NAME' => $this->getName(),
				'=TYPE' => ExternalSource\Type::Rest->value,
			],
			'limit' => 1,
		])
			->fetchObject()
		;
		if (!$dataset)
		{
			return;
		}

		$this->dataset = $dataset;
		$this->sourceId = $dataset->getSourceId();
	}

	/**
	 * The connector is read apart from the dataset: the row path needs the dataset alone, while the
	 * addresses of the channel are asked for only where delegation is decided.
	 */
	private function getConnector(): ?ExternalSourceRestConnector
	{
		if ($this->connectorLoaded)
		{
			return $this->connector;
		}

		$this->connectorLoaded = true;
		$this->loadDataset();
		if ($this->sourceId === null)
		{
			return null;
		}

		$binding = ExternalSourceRestTable::getList([
			'select' => ['CONNECTOR.URL_CAPABILITIES', 'CONNECTOR.URL_AGGREGATED_DATA'],
			'filter' => ['=SOURCE_ID' => $this->sourceId],
			'limit' => 1,
		])
			->fetchObject()
		;

		return $this->connector = $binding?->getConnector();
	}

	/**
	 * @param string $tableCode External table code, the same one the data address receives.
	 *
	 * @return array<string, string> EXTERNAL_CODE => NAME.
	 */
	private function getMapFields(string $tableCode): array
	{
		$this->mapFields ??= DatasetManager::getMapFieldsByTableName($tableCode);

		return $this->mapFields;
	}
}
