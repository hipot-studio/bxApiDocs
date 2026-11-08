<?php

namespace Bitrix\BIConnector\ExternalSource\Source;

use Bitrix\BIConnector\ExternalSource\DatasetManager;
use Bitrix\BIConnector\ExternalSource\FieldType;
use Bitrix\BIConnector\ExternalSource\Internal\ExternalSourceRest;
use Bitrix\BIConnector\ExternalSource\Internal\ExternalSourceRestConnector;
use Bitrix\BIConnector\ExternalSource\Internal\ExternalSourceRestTable;
use Bitrix\BIConnector\ExternalSource\Internal\ExternalSourceSettingsCollection;
use Bitrix\BIConnector\Services;
use Bitrix\BIConnector\Superset\Error\UserFacingError;
use Bitrix\BIConnector\Superset\Logger\Logger;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use Bitrix\Main\SystemException;
use Bitrix\Main\Web\HttpClient;
use Bitrix\Main\Web\IpAddress;
use Bitrix\Main\Web\Json;
use Bitrix\Main\Web\Uri;

class Rest extends Base
{
	public const ADDRESS_CHECK = 'urlCheck';
	public const ADDRESS_TABLE_LIST = 'urlTableList';
	public const ADDRESS_TABLE_DESCRIPTION = 'urlTableDescription';
	public const ADDRESS_DATA = 'urlData';
	public const ADDRESS_CAPABILITIES = 'urlCapabilities';
	public const ADDRESS_AGGREGATED_DATA = 'urlAggregatedData';

	/**
	 * The capabilities address lies on the path of every data response, so it gets a profile of its own:
	 * a slow but alive application must not stretch a data response by the data timeout, and a
	 * declaration of arbitrary size must not be parsed as JSON in memory.
	 */
	private const CAPABILITIES_REQUEST_TIMEOUT = 5;
	private const CAPABILITIES_BODY_LENGTH_MAX = 1048576;

	/**
	 * An optional address only has to be reachable and cannot block the connection, so its probe must not
	 * hold the answer of the check for long nor read a body of arbitrary size.
	 */
	private const OPTIONAL_PROBE_TIMEOUT = 5;
	private const OPTIONAL_PROBE_BODY_LENGTH_MAX = 1048576;

	/**
	 * Without a table the description and the data addresses owe only an HTTP answer, so their probe waits
	 * for neither the data timeout nor the body, which is not read at all. The body is skipped rather than
	 * capped: an aborted transfer would turn an alive address into an unreachable one.
	 */
	private const REACHABILITY_PROBE_TIMEOUT = 10;
	private const REACHABILITY_PROBE_STREAM_TIMEOUT = 30;

	private const PREVIEW_CACHE_TTL = 3600;
	/**
	 * The diagnostics show the current state of the application: rows cached for long would hide a failure that
	 * came after them and the rows fixed in the application. The lifetime still spares the application
	 * the requests of a reopened screen.
	 */
	private const PREVIEW_DIAGNOSTICS_CACHE_TTL = 120;
	private const PREVIEW_DIAGNOSTICS_CACHE_SUFFIX = '_diagnostics';
	private const PREVIEW_FAILURE_CACHE_SUFFIX = '_failure';
	/**
	 * A failure of the preview is cached shortly: long enough to spare a failing application the requests
	 * of a reopened screen, short enough to show the rows soon after the application is back.
	 */
	private const PREVIEW_FAILURE_CACHE_TTL = 60;

	private ExternalSourceRest | null $source;
	private int $requestTimeout = 250;
	private bool $diagnosticsMode = false;

	private ?ExternalSourceRestConnector $connector = null;

	public function __construct(int $sourceId)
	{
		parent::__construct($sourceId);

		$source = ExternalSourceRestTable::getList([
				'filter' => ['=SOURCE_ID' => $sourceId],
			])
			->fetchObject()
		;

		$source?->fillSource();
		$source?->fillConnector();

		if ($source)
		{
			$this->connector = $source->getConnector();
		}

		$this->source = $source;
	}

	private static function checkTableList(array $tableList): bool
	{
		foreach ($tableList as $table)
		{
			if (!is_array($table))
			{
				return false;
			}

			if (empty($table['code']) || empty($table['title']))
			{
				return false;
			}
		}

		return true;
	}

	private static function checkTableDescription(array $columns): bool
	{
		$requiredColumnFields = ['code', 'name', 'type'];

		if (empty($columns))
		{
			return false;
		}

		foreach ($columns as $column)
		{
			if (!is_array($column))
			{
				return false;
			}

			foreach ($requiredColumnFields as $field)
			{
				if (!array_key_exists($field, $column) || empty($column[$field]))
				{
					return false;
				}
			}
		}

		return true;
	}

	/**
	 * In the diagnostics mode a failed request names its reason, the address and the HTTP status
	 * instead of one generic error, and a private address is refused before the request is sent.
	 */
	public function setDiagnosticsMode(bool $isEnabled): self
	{
		$this->diagnosticsMode = $isEnabled;

		return $this;
	}

	public function connect(?ExternalSourceSettingsCollection $settings = null): Result
	{
		if ($this->diagnosticsMode)
		{
			return $this->checkAnswerOf(self::ADDRESS_CHECK, $this->collectConnection($settings));
		}

		$result = new Result();

		if (!$this->connector?->getUrlCheck())
		{
			$errorMessage = Loc::getMessage('BICONNECTOR_REST_CONNECTION_ERROR_EMPTY_CHECK_URL');
			$result->addError(new UserFacingError($errorMessage));

			return $result;
		}

		$queryParams = [];
		if ($settings)
		{
			foreach ($settings as $setting)
			{
				$queryParams[$setting->getCode()] = $setting->getValue();
			}
		}

		try {
			$this->query(
				$this->connector?->getUrlCheck() ?? '',
				[
					'connection' => $queryParams,
				],
				self::ADDRESS_CHECK,
			);
		}
		catch (SystemException $e)
		{
			$result->addError(new UserFacingError($e->getMessage()));
		}

		return $result;
	}

	/**
	 * Checks every address a connection relies on, with the errors of the diagnostics mode.
	 *
	 * No table is chosen yet, so the check and the table list addresses must answer meaningfully, while
	 * the table description and the data addresses only have to be reachable. The capabilities and the
	 * aggregated data addresses are checked only when declared, and their failures do not block the
	 * connection: they come back as `optionalFailures` in the data of the result.
	 *
	 * @param ExternalSourceSettingsCollection|null $settings Settings being checked; the stored ones
	 *     of the connection when null.
	 * @param bool $stopAtFirstFailure Whether the check ends on the first failed required address, leaving
	 *     the rest unasked, for a caller that reports one reason only; otherwise every address is asked.
	 */
	public function probe(
		?ExternalSourceSettingsCollection $settings = null,
		bool $stopAtFirstFailure = false,
	): Result
	{
		$connection = $this->collectConnection($settings);
		$result = new Result();

		$requiredProbes = [
			fn(): Result => $this->checkAnswerOf(self::ADDRESS_CHECK, $connection),
			fn(): Result => $this->probeTableList($connection),
			fn(): Result => $this->probeReachability(self::ADDRESS_TABLE_DESCRIPTION, $connection, ['table' => '']),
			fn(): Result => $this->probeReachability(self::ADDRESS_DATA, $connection, ['table' => '', 'limit' => 1]),
		];
		foreach ($requiredProbes as $requiredProbe)
		{
			$result->addErrors($requiredProbe()->getErrors());
			if ($stopAtFirstFailure && !$result->isSuccess())
			{
				return $result->setData(['optionalFailures' => []]);
			}
		}

		$optionalFailures = [];
		foreach ($this->probeOptionalAddresses($connection) as $optionalResult)
		{
			array_push($optionalFailures, ...$optionalResult->getErrors());
		}
		$result->setData(['optionalFailures' => $optionalFailures]);

		return $result;
	}

	private function probeTableList(array $connection): Result
	{
		$listResult = $this->checkAnswerOf(self::ADDRESS_TABLE_LIST, $connection, ['searchString' => '']);
		if (!$listResult->isSuccess())
		{
			return $listResult;
		}

		$tableList = $this->decode($listResult->getData()['answer'] ?? '');
		if ($tableList === null || !self::checkTableList($tableList))
		{
			return (new Result())->addError(
				$this->createFailureError(ConnectionFailure::ProtocolError, self::ADDRESS_TABLE_LIST, 200)
			);
		}

		return $listResult;
	}

	/**
	 * Any HTTP answer proves the address alive: without a table the application owes it nothing more.
	 */
	private function probeReachability(string $address, array $connection, array $query): Result
	{
		$client = $this->createHttpClient(self::REACHABILITY_PROBE_TIMEOUT);
		$client->setStreamTimeout(self::REACHABILITY_PROBE_STREAM_TIMEOUT);
		$client->shouldFetchBody(static fn(): bool => false);

		// Without its body a healthy answer reads as a protocol error, so only a real failure is logged.
		$reachResult = $this->checkAnswerOf($address, $connection, $query, $client, false);
		if (self::isAnsweredByApplication($reachResult))
		{
			return new Result();
		}

		if (isset($reachResult->getData()['requestedUrl']))
		{
			$this->processResponseErrors($reachResult);
		}

		return $reachResult;
	}

	/**
	 * @return Result[]
	 */
	private function probeOptionalAddresses(array $connection): array
	{
		$results = [];

		$capabilitiesUrl = $this->getAddressUrl(self::ADDRESS_CAPABILITIES);
		if ($capabilitiesUrl !== '')
		{
			$results[] = $this->exchange(
				$this->createOptionalProbeClient(),
				$capabilitiesUrl,
				Json::encode(['connection' => $connection, 'table' => '']),
				self::ADDRESS_CAPABILITIES,
				true,
			);
		}

		$aggregatedUrl = $this->getAddressUrl(self::ADDRESS_AGGREGATED_DATA);
		if ($aggregatedUrl !== '')
		{
			$results[] = $this->exchange(
				$this->createOptionalProbeClient(),
				$aggregatedUrl,
				Json::encode([
					'connection' => $connection,
					'table' => '',
					'filter' => new \stdClass(),
					'aggregation' => new \stdClass(),
					'mapFields' => new \stdClass(),
				]),
				self::ADDRESS_AGGREGATED_DATA,
				true,
			);
		}

		return array_map(
			static fn(Result $optionalResult): Result =>
				self::isAnsweredByApplication($optionalResult) ? new Result() : $optionalResult,
			$results,
		);
	}

	private function createOptionalProbeClient(): HttpClient
	{
		$client = $this->createHttpClient(self::OPTIONAL_PROBE_TIMEOUT);
		$client->setStreamTimeout(self::OPTIONAL_PROBE_TIMEOUT);
		$client->setBodyLengthMax(self::OPTIONAL_PROBE_BODY_LENGTH_MAX);
		$client->setHeader('Content-Type', 'application/json');

		return $client;
	}

	private static function isAnsweredByApplication(Result $result): bool
	{
		foreach ($result->getErrors() as $error)
		{
			$failure = ConnectionFailure::tryFrom((string)$error->getCode());
			if ($failure !== ConnectionFailure::HttpError && $failure !== ConnectionFailure::ProtocolError)
			{
				return false;
			}
		}

		return true;
	}

	/**
	 * @param string|null $searchString Search query.
	 *
	 * @return Result Result of loading list/ Data is array with tables.
	 *
	 * ID - table code like "bank_accounts" <br>
	 * DATASET_NAME - same table code <br>
	 * TITLE - readable name of table like "(Dictionary) Bank Accounts" <br>
	 * DESCRIPTION - table name "bank_accounts" <br>
	 */
	public function getEntityList(?string $searchString = null): Result
	{
		$result = new Result();
		if (!$this->source?->getConnector()?->getUrlTableList())
		{
			$errorMessage = Loc::getMessage('BICONNECTOR_REST_CONNECTION_ERROR_EMPTY_DATA_URL');
			$result->addError(new UserFacingError($errorMessage));

			return $result;
		}

		$query = $this->fillConnectionSettings([
			'searchString' => $searchString,
		]);

		try {
			$resultQuery = $this->query(
				$this->source?->getConnector()?->getUrlTableList() ?? '',
				$query,
				self::ADDRESS_TABLE_LIST,
			);

			$tableList = $this->decode($resultQuery);
			if ($tableList === null || !self::checkTableList($tableList))
			{
				$errorMessage = Loc::getMessage('BICONNECTOR_REST_EMPTY_TABLE_LIST_ERROR');
				$result->addError(new UserFacingError($errorMessage));

				return $result;
			}
		}
		catch (SystemException)
		{
			$errorMessage = Loc::getMessage('BICONNECTOR_REST_CONNECTION_ERROR_404_ERROR');
			$result->addError(new UserFacingError($errorMessage));

			return $result;
		}

		$resultData = [];
		if (!empty($tableList))
		{
			foreach ($tableList as $table)
			{
				$resultData[] = [
					'ID' => $table['id'] ?? $table['code'],
					'TITLE' => $table['title'],
					'DESCRIPTION' => $table['code'],
					'DATASET_NAME' => $table['code'],
				];
			}
		}

		$result->setData($resultData);

		return $result;
	}

	/**
	 * @param string $entityName Table name with _ - like Catalog_BankAccounts.
	 *
	 * @return array
	 *
	 * @throws ConnectionFailureException In the diagnostics mode, when the address fails for a known reason.
	 */
	public function getDescription(string $entityName): array
	{
		if (!$this->source?->getConnector()?->getUrlTableDescription())
		{
			if ($this->diagnosticsMode)
			{
				throw new ConnectionFailureException(ConnectionFailure::UrlMissing, self::ADDRESS_TABLE_DESCRIPTION);
			}

			return [];
		}

		$query = $this->fillConnectionSettings([
			'table' => $entityName,
		]);

		try {
			$result = $this->query(
				$this->source?->getConnector()?->getUrlTableDescription() ?? '',
				$query,
				self::ADDRESS_TABLE_DESCRIPTION,
			);

			$columns = $this->decode($result);
		}
		catch (ConnectionFailureException $e)
		{
			throw $e;
		}
		catch (SystemException)
		{
			throw new SystemException(Loc::getMessage('BICONNECTOR_REST_CONNECTION_TABLE_DESCRIPTION_404_ERROR') ?? '');
		}

		if (!$columns || !is_array($columns) || !self::checkTableDescription($columns))
		{
			if ($this->diagnosticsMode)
			{
				throw new ConnectionFailureException(
					ConnectionFailure::ProtocolError,
					self::ADDRESS_TABLE_DESCRIPTION,
					200,
				);
			}

			throw new SystemException(Loc::getMessage('BICONNECTOR_REST_EMPTY_TABLE_DESCRIPTION_ERROR') ?? '');
		}

		$result = [];
		foreach ($columns as $column)
		{
			$result[] = [
				'CODE' => $column['code'],
				'NAME' => $column['name'] ?? $column['code'],
				'TYPE' => $this->mapType($column['type'] ?? Services\ApacheSuperset::TYPE_STRING),
			];
		}

		return $result;
	}

	/**
	 * @param string $tableName Table name with _ - like Catalog_BankAccounts.
	 * @param array $query Array of query params - select, filter, limit and, optionally, the already
	 *     resolved `mapFields`.
	 *
	 * @return string.
	 *
	 * @throws ConnectionFailureException In the diagnostics mode, when the address fails for a known reason.
	 */
	public function getData(string $tableName, array $query): string
	{
		if (!$this->source?->getSource()?->getActive())
		{
			throw new SystemException(Loc::getMessage('BICONNECTOR_REST_CONNECTION_NOT_ACTIVE') ?? '');
		}

		if (!$this->source?->getConnector()?->getUrlData())
		{
			if ($this->diagnosticsMode)
			{
				throw new ConnectionFailureException(ConnectionFailure::UrlMissing, self::ADDRESS_DATA);
			}

			throw new SystemException(Loc::getMessage('BICONNECTOR_REST_CONNECTION_ERROR_EMPTY_DATA_URL') ?? '');
		}

		// The caller may hand over the map it has already resolved, the same way the aggregated path
		// takes it: it is the same catalog answer, and asking for it again would cost a query on the
		// path of every data response.
		$resolvedMapFields = $query['mapFields'] ?? [];

		$query = array_intersect_key($query, array_flip(['limit', 'filter', 'select']));
		$query = $this->fillConnectionSettings($query);
		$query['table'] = $tableName;
		$query['mapFields'] = $this->getMapFields($tableName, $query['select'] ?? [], $resolvedMapFields);

		return $this->query($this->source?->getConnector()?->getUrlData() ?? '', $query, self::ADDRESS_DATA);
	}

	/**
	 * Sends an accepted aggregation block to the aggregated data address (API-02).
	 *
	 * The envelope carries two namespaces: `table` is the external code, the same one getData() sends,
	 * while the columns of the block name the fields of the dataset. `mapFields` bridges them, so it
	 * travels whole - this path has no select list to intersect it with. Neither `limit` nor `timezone`
	 * is sent: groups are truncated by `aggregation.groupLimit` alone, and dates are truncated without a
	 * timezone conversion.
	 *
	 * The body is JSON and not a form: the block has to reach the application with `null` in `having`,
	 * `orderBy` and `groupLimit` and with an integer `version`, and form encoding turns the first into an
	 * empty string and the second into a string. The data address keeps its form encoding untouched.
	 *
	 * @param string $tableName External table code, like Catalog_BankAccounts.
	 * @param array $query Query params - filter, aggregation and, optionally, the already resolved
	 *     `mapFields`; keys outside the white list are dropped.
	 *
	 * @return string Body of the answer as the application framed it.
	 *
	 * @throws SystemException When the connection is inactive, the address is empty or the request failed.
	 */
	public function getAggregatedData(string $tableName, array $query): string
	{
		if (!$this->source?->getSource()?->getActive())
		{
			throw new SystemException(Loc::getMessage('BICONNECTOR_REST_CONNECTION_NOT_ACTIVE') ?? '');
		}

		$requestedUrl = (string)($this->source?->getConnector()?->getUrlAggregatedData() ?? '');
		if ($requestedUrl === '')
		{
			// The address is checked again here and not only where delegation is decided: it may be
			// cleared in between, and an empty url must refuse instead of being posted to.
			throw new SystemException(Loc::getMessage('BICONNECTOR_REST_CONNECTION_ERROR_EMPTY_DATA_URL') ?? '');
		}

		$query = array_intersect_key($query, array_flip(['filter', 'aggregation', 'mapFields']));
		$query = $this->fillConnectionSettings($query);
		$query['table'] = $tableName;

		$query['mapFields'] = $this->getMapFields($tableName, [], $query['mapFields'] ?? []);

		// No body length limit here, for parity with the row path, which does not cap the answer either.
		return $this->queryJson(
			$requestedUrl,
			$query,
			$this->requestTimeout,
			address: self::ADDRESS_AGGREGATED_DATA,
		);
	}

	/**
	 * Asks the application for its aggregation capabilities declaration for one table.
	 *
	 * The envelope carries the external table code, the same one getData() sends, and the connection
	 * settings: field and table names of the dataset are unknown to the application.
	 *
	 * The body is JSON and not a form: the declaration answer is JSON as well, and the data address
	 * keeps its form encoding untouched.
	 *
	 * @param string $tableName External table code, like Catalog_BankAccounts.
	 *
	 * @return array|null Raw declaration, or null when it cannot be obtained at all.
	 *
	 * @throws SystemException When the connection is inactive or the request itself failed.
	 */
	public function getCapabilitiesDeclaration(string $tableName): ?array
	{
		if (!$this->source?->getSource()?->getActive())
		{
			throw new SystemException(Loc::getMessage('BICONNECTOR_REST_CONNECTION_NOT_ACTIVE') ?? '');
		}

		$requestedUrl = (string)($this->source?->getConnector()?->getUrlCapabilities() ?? '');
		if ($requestedUrl === '')
		{
			return null;
		}

		$query = $this->fillConnectionSettings([]);
		$query['table'] = $tableName;

		// The failure of this address is written to the event log by the caller of this method, which
		// knows the table the declaration was asked about; writing it here as well would give two
		// records per failure, and the failure repeats for as long as the address stays down.
		$answer = $this->queryJson(
			$requestedUrl,
			$query,
			self::CAPABILITIES_REQUEST_TIMEOUT,
			self::CAPABILITIES_BODY_LENGTH_MAX,
			logFailure: false,
			address: self::ADDRESS_CAPABILITIES,
		);

		return $this->decode($answer);
	}

	/**
	 * The diagnostics mode keeps a cache of its own, so an empty preview cached without the diagnostics, which
	 * may hide a failure, is never served there. A failure of a known reason is cached there briefly and
	 * thrown again from the cache, so reopening the screen does not wait for a failing application each time.
	 *
	 * @param string $entityName Table name with _ - like Catalog_BankAccounts.
	 * @param int $n Amount of rows.
	 *
	 * @return array
	 *
	 * @throws SystemException In the diagnostics mode, when the rows cannot be obtained.
	 */
	public function getFirstNData(string $entityName, int $n, array $fields = []): array
	{
		$fieldsForCacheKey =
			implode(',', array_keys($fields))
			. implode(',', array_values($fields))
		;
		$cacheKey = "biconnector_rest_preview_data_{$entityName}_{$n}_{$this->source?->getSourceId()}_{$fieldsForCacheKey}";

		if ($this->diagnosticsMode)
		{
			return $this->getDiagnosedFirstNData($entityName, $n, $cacheKey . self::PREVIEW_DIAGNOSTICS_CACHE_SUFFIX);
		}

		$cacheManager = \Bitrix\Main\Application::getInstance()->getManagedCache();

		if ($cacheManager->read(self::PREVIEW_CACHE_TTL, $cacheKey))
		{
			return $cacheManager->get($cacheKey);
		}

		try
		{
			$data = $this->readFirstNRows($entityName, $n);
		}
		catch (SystemException)
		{
			$data = [];
		}

		$cacheManager->set($cacheKey, $data);

		return $data;
	}

	/**
	 * @throws SystemException When the rows cannot be obtained.
	 */
	private function getDiagnosedFirstNData(string $entityName, int $n, string $cacheKey): array
	{
		$cacheManager = \Bitrix\Main\Application::getInstance()->getManagedCache();

		if ($cacheManager->read(self::PREVIEW_DIAGNOSTICS_CACHE_TTL, $cacheKey))
		{
			return $cacheManager->get($cacheKey);
		}

		// The failure has a shorter lifetime than the rows, and the managed cache keeps one lifetime per key.
		$failureCacheKey = $cacheKey . self::PREVIEW_FAILURE_CACHE_SUFFIX;
		if ($cacheManager->read(self::PREVIEW_FAILURE_CACHE_TTL, $failureCacheKey))
		{
			$cachedFailure = self::restoreConnectionFailure((array)$cacheManager->get($failureCacheKey));
			if ($cachedFailure !== null)
			{
				throw $cachedFailure;
			}
		}

		try
		{
			$data = $this->readFirstNRows($entityName, $n);
		}
		catch (ConnectionFailureException $e)
		{
			$cacheManager->set($failureCacheKey, [
				'failure' => $e->getFailure()->value,
				'address' => $e->getAddress(),
				'status' => $e->getStatus(),
			]);

			throw $e;
		}

		$cacheManager->set($cacheKey, $data);

		return $data;
	}

	private static function restoreConnectionFailure(array $cachedFailure): ?ConnectionFailureException
	{
		$failure = ConnectionFailure::tryFrom((string)($cachedFailure['failure'] ?? ''));
		if ($failure === null)
		{
			return null;
		}

		$status = $cachedFailure['status'] ?? null;

		return new ConnectionFailureException(
			$failure,
			(string)($cachedFailure['address'] ?? ''),
			$status === null ? null : (int)$status,
		);
	}

	private function readFirstNRows(string $entityName, int $n): array
	{
		$data = [];
		$result = $this->getData($entityName, ['limit' => $n]);
		$rows = $this->decode($result);
		if ($rows === null && $this->diagnosticsMode)
		{
			throw new ConnectionFailureException(ConnectionFailure::ProtocolError, self::ADDRESS_DATA, 200);
		}

		if ($rows !== null)
		{
			$columns = array_shift($rows);

			$rows = array_slice($rows, 0, $n);
			foreach ($rows as $row)
			{
				$data[] = array_combine($columns, $row);
			}
		}

		return $data;
	}

	/**
	 * @param ExternalSourceRestConnector $connector
	 *
	 * @return $this
	 */
	public function setConnector(ExternalSourceRestConnector $connector): self
	{
		$this->connector = $connector;

		return $this;
	}

	/**
	 * @param string $requestedUrl
	 * @param array $queryParams
	 *
	 * @return string
	 *
	 * @throws SystemException
	 */
	private function query(string $requestedUrl, array $queryParams = [], string $address = ''): string
	{
		$client = $this->createHttpClient($this->requestTimeout);

		$responseResult = $this->exchange($client, $requestedUrl, $queryParams, $address);
		$answer = $responseResult->getData()['answer'];

		if (!$responseResult->isSuccess())
		{
			$failure = $this->diagnosticsMode
				? ConnectionFailureException::fromError($responseResult->getError())
				: null
			;
			if ($failure !== null)
			{
				throw $failure;
			}

			throw new SystemException(
				$responseResult->getError()?->getMessage()
				?? Loc::getMessage('BICONNECTOR_REST_CONNECTION_ERROR_CANT_CONNECT')
			);
		}

		return $answer;
	}

	/**
	 * POSTs a JSON body to one of the addresses of this transport and returns the raw answer.
	 *
	 * Encoding apart, the constraints are the ones of the form addresses: POST, the same client, private
	 * addresses forbidden unless the portal allows local connections.
	 *
	 * @param int|null $bodyLengthMax Transfer limit, or null to leave the answer size uncapped.
	 * @param bool $logFailure Whether a failure is written to the event log from here. An address whose
	 *     caller writes the failure down itself passes false, so one failure gives one record.
	 *
	 * @return string
	 *
	 * @throws SystemException
	 */
	private function queryJson(
		string $requestedUrl,
		array $queryParams,
		int $timeout,
		?int $bodyLengthMax = null,
		bool $logFailure = true,
		string $address = '',
	): string
	{
		$client = $this->createHttpClient($timeout);
		if ($bodyLengthMax !== null)
		{
			// Exceeding the limit aborts the transfer, so an oversized body arrives as a transport
			// failure and never as a truncated answer.
			$client->setBodyLengthMax($bodyLengthMax);
		}
		$client->setHeader('Content-Type', 'application/json');

		$responseResult = $this->exchange(
			$client,
			$requestedUrl,
			Json::encode($queryParams),
			$address,
			logFailure: $logFailure,
		);

		if (!$responseResult->isSuccess())
		{
			throw new SystemException($responseResult->getError()?->getMessage() ?? 'Unknown error');
		}

		return $responseResult->getData()['answer'];
	}

	/**
	 * Sends one request on a configured client and tells whether it succeeded.
	 *
	 * @param string $address One of the ADDRESS_* constants; named by the errors of the diagnostics mode.
	 * @param bool|null $diagnostics Overrides the mode of the transport, null keeps it.
	 */
	private function exchange(
		HttpClient $client,
		string $requestedUrl,
		array|string $body,
		string $address = '',
		?bool $diagnostics = null,
		bool $logFailure = true,
	): Result
	{
		$diagnostics ??= $this->diagnosticsMode;

		$responseResult = $diagnostics ? $this->refusePrivateAddress($requestedUrl, $address) : null;
		if ($responseResult === null)
		{
			$answer = $client->post(new Uri($requestedUrl), $body);
			$responseResult = $diagnostics
				? $this->processDiagnosedResponse($answer, $client, $address)
				: $this->processResponse($answer, $client)
			;
		}

		if ($logFailure)
		{
			$this->processResponseErrors($responseResult);
		}

		return $responseResult;
	}

	/**
	 * While private addresses are forbidden, the client resolves the host itself and reports a host that
	 * does not resolve as a private address, so both are told apart here, before anything is sent.
	 */
	private function refusePrivateAddress(string $requestedUrl, string $address): ?Result
	{
		if ($this->isLocalConnectionAllowed())
		{
			return null;
		}

		$ip = $this->resolveHostOf($requestedUrl);
		if ($ip === null)
		{
			return null;
		}

		if (filter_var($ip->get(), FILTER_VALIDATE_IP) === false)
		{
			$failure = ConnectionFailure::Unreachable;
		}
		elseif ($ip->isPrivate())
		{
			$failure = ConnectionFailure::PrivateAddress;
		}
		else
		{
			return null;
		}

		$result = new Result();
		$result->setData([
			'answer' => false,
			'requestedUrl' => $requestedUrl,
		]);
		$result->addError($this->createFailureError($failure, $address));

		return $result;
	}

	/**
	 * Resolves the host the way the client does, punycode included; null when the url gives no host
	 * to resolve, which the client then refuses by itself.
	 */
	private function resolveHostOf(string $requestedUrl): ?IpAddress
	{
		$uri = new Uri($requestedUrl);
		$host = $uri->getHost();
		if ($host === '')
		{
			return null;
		}

		$literal = trim($host, '[]');
		if (filter_var($literal, FILTER_VALIDATE_IP) !== false)
		{
			return new IpAddress($literal);
		}

		if ($uri->convertToPunycode() instanceof Error)
		{
			return null;
		}

		return IpAddress::createByUri($uri);
	}

	private function processDiagnosedResponse($answer, HttpClient $client, string $address): Result
	{
		$result = new Result();
		$effectiveUrl = $client->getEffectiveUrl();
		$result->setData([
			'answer' => $answer,
			'requestedUrl' => is_string($effectiveUrl) ? $effectiveUrl : $effectiveUrl->getUri(),
		]);

		$status = (int)$client->getStatus();
		if ($answer === false || $status === 0)
		{
			$failure = ConnectionFailure::fromClientErrors((array)$client->getError());
			$result->addError($this->createFailureError($failure, $address));

			return $result;
		}

		if ($status !== 200)
		{
			$result->addError($this->createFailureError(ConnectionFailure::HttpError, $address, $status));

			return $result;
		}

		if ($answer === '')
		{
			$result->addError($this->createFailureError(ConnectionFailure::ProtocolError, $address, $status));
		}

		return $result;
	}

	private function createFailureError(ConnectionFailure $failure, string $address, ?int $status = null): Error
	{
		return new UserFacingError(
			$failure->describe($address, $status),
			$failure->value,
			[
				'address' => $address,
				'status' => $status,
			],
		);
	}

	private function isLocalConnectionAllowed(): bool
	{
		return \Bitrix\Main\Config\Option::get('biconnector', 'allow_local_connections', 'N') === 'Y';
	}

	/**
	 * The url of every address of this transport comes from connector data, so private addresses stay
	 * forbidden unless the portal explicitly allows local connections.
	 */
	private function createHttpClient(int $timeout): HttpClient
	{
		$client = $this->createHttpClientInstance();
		$client->setTimeout($timeout);
		if (!$this->isLocalConnectionAllowed())
		{
			$client->setPrivateIp(false);
		}

		return $client;
	}

	/**
	 * The bare client every request of this transport is configured on.
	 *
	 * Separate from the configuration above and protected for one reason: the timeout and the transfer
	 * limit an address is given differ from address to address, and this is where a test can substitute
	 * a client that reads back what it was given.
	 */
	protected function createHttpClientInstance(): HttpClient
	{
		return new HttpClient();
	}

	/**
	 * @param $answer
	 *
	 * @param HttpClient $client
	 * @return Result
	 */
	private function processResponse($answer, HttpClient $client): Result
	{
		$result = new Result();
		$effectiveUrl = $client->getEffectiveUrl();
		$result->setData([
			'answer' => $answer,
			'requestedUrl' => is_string($effectiveUrl) ? $effectiveUrl : $effectiveUrl->getUri(),
		]);

		if (!$answer)
		{
			$errorMessage = Loc::getMessage('BICONNECTOR_REST_CONNECTION_ERROR_CANT_CONNECT');
			$result->addError(new UserFacingError($errorMessage));

			return $result;
		}

		if ($client->getStatus() !== 200)
		{
			$errorMessage = Loc::getMessage('BICONNECTOR_REST_CONNECTION_ERROR_404_ERROR');
			$result->addError(new UserFacingError($errorMessage));

			return $result;
		}

		return $result;
	}

	/**
	 * @param Result $queryResult
	 *
	 * @return void
	 */
	private function processResponseErrors(Result $queryResult): void
	{
		if (!$queryResult->isSuccess())
		{
			Logger::logErrors($queryResult->getErrors(), [
				'connectionType' => 'rest',
				'connectionId' => $this->source?->getSource()?->getId() ?? 0,
				'requestedUrl' => $queryResult->getData()['requestedUrl'],
				'answer' => $queryResult->getData()['answer'] ?? 'No answer',
			]);
		}
	}

	/**
	 * @param string $type.
	 *
	 * @return string.
	 * @see \Bitrix\BIConnector\DataSourceConnector\ApacheSupersetFieldDto::mapType
	 */
	private function mapType(string $type): string
	{
		$mapType = FieldType::tryFrom(mb_strtolower($type));

		if ($mapType)
		{
			return $mapType->value;
		}

		return FieldType::String->value;
	}

	private function decode($data): ?array
	{
		try
		{
			$decoded = Json::decode($data);
			if (is_array($decoded))
			{
				return $decoded;
			}
		}
		catch (ArgumentException $e)
		{
		}

		return null;
	}

	private function fillConnectionSettings(array $queryFields): array
	{
		$settingFields = [];
		$source = $this->source?->getSource();
		if (!$source)
		{
			return $queryFields;
		}

		$settings = \Bitrix\BIConnector\ExternalSource\SourceManager::getSourceSettings($source);
		foreach ($settings as $setting)
		{
			$settingFields[$setting->getCode()] = $setting->getValue();
		}

		$queryFields['connection'] = $settingFields;

		return $queryFields;
	}

	/**
	 * Settings a request of the connection check carries: the ones being checked, or the stored ones
	 * of the connection when none are given.
	 */
	private function collectConnection(?ExternalSourceSettingsCollection $settings): array
	{
		if ($settings === null)
		{
			return $this->fillConnectionSettings([])['connection'] ?? [];
		}

		$connection = [];
		foreach ($settings as $setting)
		{
			$connection[$setting->getCode()] = $setting->getValue();
		}

		return $connection;
	}

	private function getAddressUrl(string $address): string
	{
		return (string)match ($address)
		{
			self::ADDRESS_CHECK => $this->connector?->getUrlCheck(),
			self::ADDRESS_TABLE_LIST => $this->connector?->getUrlTableList(),
			self::ADDRESS_TABLE_DESCRIPTION => $this->connector?->getUrlTableDescription(),
			self::ADDRESS_DATA => $this->connector?->getUrlData(),
			self::ADDRESS_CAPABILITIES => $this->connector?->getUrlCapabilities(),
			self::ADDRESS_AGGREGATED_DATA => $this->connector?->getUrlAggregatedData(),
			default => '',
		};
	}

	/**
	 * Posts the connection to an address and requires a meaningful answer: HTTP 200 and a non-empty body.
	 */
	private function checkAnswerOf(
		string $address,
		array $connection,
		array $query = [],
		?HttpClient $client = null,
		bool $logFailure = true,
	): Result
	{
		$url = $this->getAddressUrl($address);
		if ($url === '')
		{
			return (new Result())->addError($this->createFailureError(ConnectionFailure::UrlMissing, $address));
		}

		return $this->exchange(
			$client ?? $this->createHttpClient($this->requestTimeout),
			$url,
			['connection' => $connection] + $query,
			$address,
			true,
			$logFailure,
		);
	}

	/**
	 * @param array $resolved The map as the caller has already resolved it, when it has. A caller that
	 *     hands over nothing is served from the catalog exactly as before.
	 */
	private function getMapFields(string $tableName, array $select, array $resolved = []): array
	{
		$mapFields = $resolved ?: DatasetManager::getMapFieldsByTableName($tableName);

		return $select ? array_intersect($mapFields, $select) : $mapFields;
	}
}
