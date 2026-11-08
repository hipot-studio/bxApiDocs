<?php

namespace Bitrix\BIConnector\DB;

use Bitrix\Main;
use Bitrix\Main\Diag;
use Bitrix\Main\DB\ConnectionException;
use Bitrix\Main\DB\SqlQueryException;
use Bitrix\Main\NotSupportedException;
use Bitrix\Main\Web\HttpClient;

/**
 * Read-only connection to ClickHouse over its HTTP interface.
 *
 * ClickHouse has no Bitrix driver, and the BI read path needs only a narrow slice of the connection
 * behaviour: run a query, iterate rows, describe a table, list tables, report a version. Everything
 * that writes data or changes the schema throws, so the class cannot be used outside of that slice
 * by accident.
 *
 * The answer is requested in JSONCompactEachRowWithNamesAndTypes: the first line carries column
 * names, the second one carries types, and every following line is a row. That makes the response
 * readable line by line, so a wide selection never has to be held in memory as a whole.
 */
class ClickhouseConnection extends Main\DB\Connection implements BiTimeLimitInterface
{
	public const DEFAULT_PORT = 8123;
	public const DEFAULT_SECURE_PORT = 8443;

	protected const RESPONSE_FORMAT = 'JSONCompactEachRowWithNamesAndTypes';
	protected const CONNECT_TIMEOUT_SECONDS = 10;
	protected const STREAM_TIMEOUT_SECONDS = 300;
	protected const STREAM_TIMEOUT_SLACK_SECONDS = 30;
	protected const PING_QUERY = 'SELECT 1';

	/**
	 * ClickHouse readonly level sent with every request. Level 2 rejects writes and schema changes but
	 * still allows settings to be passed along with the query, which level 1 forbids.
	 */
	protected const READONLY_LEVEL = 2;

	private const READ_ONLY_MESSAGE = 'Clickhouse BI connection is read-only.';
	private const ERROR_MESSAGE_MAX_LENGTH = 4096;

	private ?string $lastError = null;
	private ?string $lastBiError = null;
	private bool $biMode = false;
	private ?string $endpointUrl = null;
	private int $biTimeLimitSeconds = 0;

	/** @var array{readRows: int, readBytes: int}|null */
	private ?array $lastQueryStatistics = null;

	protected function createSqlHelper()
	{
		return new ClickhouseSqlHelper($this);
	}

	public function getType()
	{
		return 'clickhouse';
	}

	public function setBiTimeLimitSeconds(int $seconds): void
	{
		$this->biTimeLimitSeconds = max(0, $seconds);
		$this->endpointUrl = null;
	}

	/**
	 * Checks that the endpoint answers and the credentials are accepted.
	 *
	 * HTTP is stateless, so there is nothing to keep open. A ping is still made once, because the
	 * calling code treats a failed connect as "the source is unavailable" and expects an exception.
	 *
	 * @return void
	 * @throws ConnectionException
	 */
	protected function connectInternal()
	{
		if ($this->isConnected)
		{
			return;
		}

		$result = $this->executeHttpQuery(self::PING_QUERY);
		if ($result === false)
		{
			throw new ConnectionException('Clickhouse connection error', (string)$this->lastError);
		}

		$result->close();

		$this->isConnected = true;
	}

	protected function disconnectInternal()
	{
		$this->isConnected = false;
	}

	/**
	 * Executes a query on this connection.
	 *
	 * The base implementation may hand the statement over to a slave connection of the portal pool.
	 * That must not happen here: this object points at an external ClickHouse server, and a slave of
	 * the portal is a different database with different data. The statement always stays here.
	 *
	 * @param string $sql
	 *
	 * @return Main\DB\Result
	 * @throws SqlQueryException
	 */
	public function query($sql)
	{
		[$sql, $binds, $offset, $limit] = self::parseQueryFunctionArgs(func_get_args());

		if ($limit > 0)
		{
			$sql = $this->getSqlHelper()->getTopSql($sql, $limit, $offset);
		}

		$trackerQuery = null;
		if ($this->trackSql)
		{
			$trackerQuery = $this->sqlTracker->getNewTrackerQuery();
			$trackerQuery->setNode($this->getNodeId());
		}

		$result = $this->queryInternal($sql, $binds, $trackerQuery);

		return $this->createResult($result, $trackerQuery);
	}

	/**
	 * Runs a query in the BI read mode.
	 *
	 * Mirrors the contract of the MySQL and PostgreSQL BI connections: false on failure, with the
	 * message available through getLastBiError(), and rows returned as lists of values rather than
	 * name to value maps, because the BI read path addresses them by position.
	 *
	 * No separate memory saving mode is needed on top of that: the HTTP response is already read as a
	 * stream.
	 *
	 * @param string $sql
	 *
	 * @return Main\DB\Result|false
	 */
	public function biQuery($sql)
	{
		$this->lastBiError = null;
		$this->biMode = true;

		try
		{
			return $this->query($sql);
		}
		catch (ConnectionException | SqlQueryException $e)
		{
			$this->lastBiError = $e->getDatabaseMessage() ?: $e->getMessage();

			return false;
		}
		finally
		{
			$this->biMode = false;
		}
	}

	public function getLastBiError(): ?string
	{
		return $this->lastBiError;
	}

	public function getErrorMessage()
	{
		return (string)($this->lastBiError ?: $this->lastError);
	}

	/**
	 * @param string $sql
	 * @param array|null $binds
	 * @param Diag\SqlTrackerQuery|null $trackerQuery
	 *
	 * @return ClickhouseQueryResult
	 * @throws ConnectionException
	 * @throws SqlQueryException
	 */
	protected function queryInternal($sql, ?array $binds = null, ?Diag\SqlTrackerQuery $trackerQuery = null)
	{
		$this->connectInternal();

		$trackerQuery?->startQuery($sql, $binds);

		$result = $this->executeHttpQuery($sql);

		$trackerQuery?->finishQuery();

		// The outcome is deliberately not kept in $lastQueryResult: it owns the response stream, and
		// holding a second reference here would postpone the release of the stream of a result the
		// caller has already dropped. Nothing in this connection reads $lastQueryResult.

		if ($result === false)
		{
			throw new SqlQueryException('Clickhouse query error', (string)$this->lastError, $sql);
		}

		return $result;
	}

	/**
	 * @param ClickhouseQueryResult $result
	 * @param Diag\SqlTrackerQuery|null $trackerQuery
	 *
	 * @return ClickhouseResult
	 */
	protected function createResult($result, ?Diag\SqlTrackerQuery $trackerQuery = null)
	{
		return new ClickhouseResult($result, $this, $trackerQuery, $this->biMode);
	}

	public function getVersion()
	{
		if ($this->version === null)
		{
			$version = (string)$this->queryScalar('SELECT version()');

			if (preg_match('#^(\d+\.\d+)#', $version, $matches))
			{
				$this->version = $matches[1];
			}
			else
			{
				$this->version = $version;
			}
		}

		return [$this->version, null];
	}

	/**
	 * @param string $tableName
	 *
	 * @return bool
	 */
	public function isTableExists($tableName)
	{
		$sqlHelper = $this->getSqlHelper();

		$count = $this->queryScalar("
			SELECT count()
			FROM system.tables
			WHERE database = currentDatabase()
				AND name = '" . $sqlHelper->forSql($tableName) . "'
		");

		return (int)$count > 0;
	}

	/**
	 * Returns the columns of a table as ORM fields.
	 *
	 * Column names are returned exactly as the server reports them, without changing the case: they
	 * are used both to build the SQL and to address the values of a fetched row, so the two have to
	 * agree. ClickHouse tables conventionally use lower case names.
	 *
	 * @param string $tableName
	 *
	 * @return Main\ORM\Fields\ScalarField[]
	 */
	public function getTableFields($tableName)
	{
		if (empty($this->tableColumnsCache[$tableName]))
		{
			$sqlHelper = $this->getSqlHelper();

			$query = $this->query("
				SELECT name, type
				FROM system.columns
				WHERE database = currentDatabase()
					AND table = '" . $sqlHelper->forSql($tableName) . "'
				ORDER BY position
			");

			$fields = [];
			while ($columnInfo = $query->fetch())
			{
				$columnName = (string)($columnInfo['name'] ?? '');
				if ($columnName === '')
				{
					continue;
				}

				$fields[$columnName] = $sqlHelper->getFieldByColumnType(
					$columnName,
					(string)($columnInfo['type'] ?? 'String')
				);
			}

			$this->tableColumnsCache[$tableName] = $fields;
		}

		return $this->tableColumnsCache[$tableName];
	}

	/**
	 * @param string $tableName
	 * @param array $columns
	 *
	 * @return bool Always false: ClickHouse has no indexes comparable to the ones this API describes.
	 */
	public function isIndexExists($tableName, array $columns)
	{
		return false;
	}

	/**
	 * @param string $tableName
	 * @param array $columns
	 * @param bool $strict
	 *
	 * @return string|null Always null, see isIndexExists().
	 */
	public function getIndexName($tableName, array $columns, $strict = false)
	{
		return null;
	}

	public function getAffectedRowsCount()
	{
		return 0;
	}

	/**
	 * @return int
	 * @throws NotSupportedException Always: the connection is read-only.
	 */
	public function getInsertedId()
	{
		throw new NotSupportedException(self::READ_ONLY_MESSAGE);
	}

	/**
	 * @param string $tableName
	 * @param array $fields
	 * @param array $primary
	 * @param array $autoincrement
	 *
	 * @return void
	 * @throws NotSupportedException Always: the connection is read-only.
	 */
	public function createTable($tableName, $fields, $primary = [], $autoincrement = [])
	{
		throw new NotSupportedException(self::READ_ONLY_MESSAGE);
	}

	/**
	 * @param string $currentName
	 * @param string $newName
	 *
	 * @return void
	 * @throws NotSupportedException Always: the connection is read-only.
	 */
	public function renameTable($currentName, $newName)
	{
		throw new NotSupportedException(self::READ_ONLY_MESSAGE);
	}

	/**
	 * @param string $tableName
	 *
	 * @return void
	 * @throws NotSupportedException Always: the connection is read-only.
	 */
	public function dropTable($tableName)
	{
		throw new NotSupportedException(self::READ_ONLY_MESSAGE);
	}

	/**
	 * @param string $tableName
	 *
	 * @return void
	 * @throws NotSupportedException Always: the connection is read-only.
	 */
	public function truncateTable($tableName)
	{
		throw new NotSupportedException(self::READ_ONLY_MESSAGE);
	}

	/**
	 * ClickHouse has no transactions in the sense this API assumes, and the connection never writes.
	 *
	 * @return void
	 */
	public function startTransaction()
	{
	}

	/**
	 * @return void
	 */
	public function commitTransaction()
	{
	}

	/**
	 * @return void
	 */
	public function rollbackTransaction()
	{
	}

	/**
	 * Sends a statement and prepares a reader over the answer.
	 *
	 * @param string $sql
	 *
	 * @return ClickhouseQueryResult|false
	 */
	protected function executeHttpQuery(string $sql)
	{
		$this->lastError = null;
		$this->lastQueryStatistics = null;

		$httpClient = $this->createHttpClient();

		$queried = $httpClient->query(HttpClient::HTTP_POST, $this->getEndpointUrl(), $sql);
		$status = $httpClient->getStatus();

		// The client has already spooled the decoded body into its own php://temp stream; that stream
		// is taken over and read in place. Copying it into a second buffer through setOutputStream()
		// and getResult() would write and read the whole response twice.
		$body = $httpClient->getResponse()?->getBody();
		$stream = $body?->detach();

		if (!is_resource($stream))
		{
			$errors = $httpClient->getError();
			$this->lastError = !empty($errors)
				? implode('; ', $errors)
				: 'Clickhouse request failed with HTTP status ' . (int)$status . '.'
			;

			return false;
		}

		if (!$queried || $status !== 200)
		{
			$this->lastError = $this->extractErrorMessage($stream, $httpClient, $status);
			fclose($stream);

			return false;
		}

		$this->lastQueryStatistics = self::parseSummaryHeader((string)$httpClient->getHeaders()->get('X-ClickHouse-Summary'));

		rewind($stream);

		$names = $this->readHeaderLine($stream);
		$types = $this->readHeaderLine($stream);

		if ($names === null || $types === null)
		{
			// A failure detected before the first row was flushed answers with a non-200 status and is
			// handled above. Getting here means the body is neither an error nor the expected format,
			// so the body itself is the most informative message available.
			rewind($stream);
			$body = trim((string)stream_get_contents($stream, self::ERROR_MESSAGE_MAX_LENGTH));
			$this->lastError = $body !== '' ? $body : 'Unexpected Clickhouse response format.';
			fclose($stream);

			return false;
		}

		return new ClickhouseQueryResult($names, $types, $stream);
	}

	/**
	 * Rows and bytes the server reported for the last successful statement.
	 *
	 * With a delegated aggregation the number of returned rows says nothing about the work the source
	 * did, so the scanned volume is recorded separately for observability.
	 *
	 * @return array{readRows: int, readBytes: int}|null
	 */
	public function getLastQueryStatistics(): ?array
	{
		return $this->lastQueryStatistics;
	}

	/**
	 * X-ClickHouse-Summary carries a JSON object whose counters are decimal strings.
	 *
	 * @return array{readRows: int, readBytes: int}|null
	 */
	private static function parseSummaryHeader(string $header): ?array
	{
		if (trim($header) === '')
		{
			return null;
		}

		$summary = json_decode($header, true);
		if (!is_array($summary) || !isset($summary['read_rows']))
		{
			return null;
		}

		return [
			'readRows' => (int)$summary['read_rows'],
			'readBytes' => (int)($summary['read_bytes'] ?? 0),
		];
	}

	/**
	 * @return HttpClient
	 */
	protected function createHttpClient(): HttpClient
	{
		$httpClient = new HttpClient([
			'socketTimeout' => self::CONNECT_TIMEOUT_SECONDS,
			'streamTimeout' => $this->getStreamTimeoutSeconds(),
			'redirect' => false,
		]);

		$login = (string)$this->getLogin();
		if ($login !== '')
		{
			// Credentials go into headers and not into the query string, so that they do not end up
			// in the server access log.
			$httpClient->setHeader('X-ClickHouse-User', $login);
			$httpClient->setHeader('X-ClickHouse-Key', (string)$this->getPassword());
		}

		$httpClient->setHeader('Content-Type', 'text/plain; charset=utf-8');

		return $httpClient;
	}

	private function getStreamTimeoutSeconds(): int
	{
		return $this->biTimeLimitSeconds > 0
			? $this->biTimeLimitSeconds + self::STREAM_TIMEOUT_SLACK_SECONDS
			: self::STREAM_TIMEOUT_SECONDS
		;
	}

	/**
	 * Builds the endpoint URL out of the connection settings.
	 *
	 * The host setting may carry a scheme and a port, because the connection form asks for a single
	 * address string.
	 *
	 * @return string
	 */
	protected function getEndpointUrl(): string
	{
		if ($this->endpointUrl !== null)
		{
			return $this->endpointUrl;
		}

		$host = trim((string)$this->getHost());

		if (!preg_match('#^https?://#i', $host))
		{
			$host = 'http://' . $host;
		}

		$parts = parse_url($host);
		$scheme = $parts['scheme'] ?? 'http';
		$hostName = $parts['host'] ?? '';
		$port = (int)($parts['port'] ?? 0);

		if ($port <= 0)
		{
			$port = $scheme === 'https' ? self::DEFAULT_SECURE_PORT : self::DEFAULT_PORT;
		}

		$parameters = [
			'default_format' => self::RESPONSE_FORMAT,
			// The PHP side rejects writes and schema changes, but query() forwards arbitrary SQL, so
			// the guarantee has to be enforced by the server as well. Level 2 forbids writes and DDL
			// while still allowing settings to be passed, which level 1 does not.
			'readonly' => self::READONLY_LEVEL,
			// A JSON number is a double once PHP has parsed it, and a double holds 15 significant
			// digits. Unquoted, an Int64 sum past 2^53, a Decimal(38,4) and a UInt64 all arrive
			// already rounded, and there is no way to recover the value afterwards. Quoted, they
			// arrive as the exact decimal text the value has.
			'output_format_json_quote_64bit_integers' => 1,
			'output_format_json_quote_decimals' => 1,
			// Without this the server prints NaN and Infinity as `null`, which is a different answer:
			// `null` means the group was empty, NaN means it was not. The quoted tokens are what the
			// wire contract carries for a non-finite double.
			'output_format_json_quote_denormals' => 1,
		];

		if ($this->biTimeLimitSeconds > 0)
		{
			$parameters['max_execution_time'] = $this->biTimeLimitSeconds;
			$parameters['timeout_overflow_mode'] = 'throw';
		}

		$database = trim((string)$this->getDatabase());
		if ($database !== '')
		{
			$parameters['database'] = $database;
		}

		$this->endpointUrl = $scheme . '://' . $hostName . ':' . $port . '/?' . http_build_query($parameters);

		return $this->endpointUrl;
	}

	/**
	 * @param resource $stream
	 *
	 * @return array|null Decoded header line, or null when the line is missing or malformed.
	 */
	private function readHeaderLine($stream): ?array
	{
		$line = fgets($stream);
		if ($line === false)
		{
			return null;
		}

		$decoded = json_decode(trim($line), true);

		return is_array($decoded) ? $decoded : null;
	}

	/**
	 * @param resource $stream
	 * @param HttpClient $httpClient
	 * @param int|null $status
	 *
	 * @return string
	 */
	private function extractErrorMessage($stream, HttpClient $httpClient, $status): string
	{
		rewind($stream);
		$body = trim((string)stream_get_contents($stream, self::ERROR_MESSAGE_MAX_LENGTH));

		if ($body !== '')
		{
			return $body;
		}

		$errors = $httpClient->getError();
		if (!empty($errors))
		{
			return implode('; ', $errors);
		}

		return 'Clickhouse request failed with HTTP status ' . (int)$status . '.';
	}

}
