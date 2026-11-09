<?php

namespace Bitrix\Superset\Internal\Services;

use Bitrix\Main;
use Bitrix\Superset\Internal\Api;
use Bitrix\Superset\Internal\HttpStatus;
use Bitrix\Superset\Internal\RequestResult;
use Bitrix\Superset\Internal\SqlLab\SqlLabErrorCode;
use Bitrix\Superset\Internal\Support\AbstractSupersetContext;
use Bitrix\Superset\Internal\Support\SqlReadOnlyClassifier;

final class SqlLabService extends AbstractSupersetContext
{
	private const SCHEMA = 'bitrix24';
	private const DEFAULT_ROW_LIMIT = 1000;
	private const MAX_ROW_LIMIT = 10000;
	private const LIMITED_FACTORS = [
		'QUERY',
		'DROPDOWN',
		'QUERY_AND_DROPDOWN',
	];
	private const ERROR_TYPE_TO_CODE = [
		'SYNTAX_ERROR' => SqlLabErrorCode::INVALID_QUERY,
		'QUERY_SECURITY_ACCESS_ERROR' => SqlLabErrorCode::ACCESS_DENIED,
		'TABLE_SECURITY_ACCESS_ERROR' => SqlLabErrorCode::ACCESS_DENIED,
		'QUERY_TIMEOUT_ERROR' => SqlLabErrorCode::TIMEOUT,
	];
	// Superset types every Trino failure as GENERIC_DB_ENGINE_ERROR; only the repr of the Trino client exception
	// at the start of the message tells a fault of the query (USER_ERROR) from a failure of Trino itself.
	private const TRINO_USER_ERROR_PATTERN = '/^TrinoUserError\(type=USER_ERROR, name=([A-Z_]+),/';
	private const TRINO_ACCESS_DENIED_NAMES = [
		'PERMISSION_DENIED',
		'BX_TABLE_ACCESS_DENIED',
	];
	private const ERROR_MESSAGES = [
		SqlLabErrorCode::INVALID_QUERY => 'SQL query is invalid',
		SqlLabErrorCode::ACCESS_DENIED => 'Access to SQL query data is denied',
		SqlLabErrorCode::TIMEOUT => 'SQL query timed out',
		SqlLabErrorCode::UNAVAILABLE => 'SQL Lab is unavailable',
	];

	/**
	 * Executes a read-only SQL query through Superset SQL Lab (synchronous mode)
	 * and returns the result rows and column metadata.
	 *
	 * Before the query leaves this module it is validated by
	 * {@see SqlReadOnlyClassifier}: only a single read-only SELECT (or
	 * WITH ... SELECT) is allowed, everything else (DML/DDL, multiple statements,
	 * SELECT ... INTO, obfuscation via comments or string literals) is rejected
	 * regardless of the caller or of Superset's own `allow_dml` flag. This makes
	 * the read-only guarantee independent of transport (box vs cloud) and of the
	 * BI database configuration.
	 *
	 * @param string $sql Raw SQL SELECT to execute against the BI (Trino) database.
	 * @param int|null $limit Row cap applied by Superset (queryLimit). Clamped to 1..MAX_ROW_LIMIT.
	 * @return Main\Result
	 */
	public function execute(string $sql, ?int $limit = null, ?string $actAsLogin = null): Main\Result
	{
		if ($actAsLogin === '')
		{
			return $this->createInvalidActAsLoginResult();
		}

		$sql = trim($sql);
		if ($sql === '')
		{
			return $this->createSqlLabErrorResult(
				SqlLabErrorCode::INVALID_QUERY,
				HttpStatus::UNPROCESSABLE_ENTITY,
				detail: 'SQL is required',
			);
		}

		$classification = (new SqlReadOnlyClassifier())->classify($sql);
		if (!$classification->isReadOnly)
		{
			return $this->createSqlLabErrorResult(
				SqlLabErrorCode::INVALID_QUERY,
				HttpStatus::UNPROCESSABLE_ENTITY,
				detail: (string)$classification->reason,
			);
		}

		$queryLimit = max(1, min(self::MAX_ROW_LIMIT, $limit ?? self::DEFAULT_ROW_LIMIT));

		$databaseResult = $this->getDatabaseService()->getTrinoDatabaseId();
		if (!$databaseResult->isSuccess())
		{
			// $databaseResult carries infrastructure diagnostics (connection addresses, API
			// status codes), not something about the user's query: keep it out of detail, which
			// the AI channel unwraps verbatim (see BiToolBehaviour::describeSqlLabError).
			return $this->createSqlLabErrorResult(
				SqlLabErrorCode::UNAVAILABLE,
				HttpStatus::SERVICE_UNAVAILABLE,
			);
		}
		$databaseId = (int)$databaseResult->getData()['id'];

		$payload = [
			'database_id' => $databaseId,
			'sql' => $sql,
			'schema' => self::SCHEMA,
			'runAsync' => false,
			'select_as_cta' => false,
			'queryLimit' => $queryLimit,
		];

		$requestResult = $this->getSqlLabApi($actAsLogin)->execute($payload);
		$decoded = $this->decode($requestResult->getAnswer());
		$status = is_array($decoded) ? (string)($decoded['status'] ?? '') : '';

		if ($requestResult->getHttpStatus() === HttpStatus::OK)
		{
			$notApplied = $this->checkActAsApplied($requestResult, $actAsLogin);
			if ($notApplied !== null)
			{
				return $notApplied;
			}
		}

		// Superset reports a query-level failure as HTTP 200 with {"status":"failed"}: the transport
		// is healthy, so the answer stays 4xx. The stable code carries the classification, while a 5xx
		// would move the whole portal to SUPERSET_STATUS_ERROR.
		$refusal = $requestResult->getHttpStatus() >= HttpStatus::BAD_REQUEST
			? $this->createActAsRefusalResult($requestResult)
			: null;
		if ($refusal !== null)
		{
			return $refusal;
		}

		if ($status === 'failed')
		{
			return $this->createSqlLabErrorResult(
				$this->resolveErrorCode($decoded),
				HttpStatus::UNPROCESSABLE_ENTITY,
				$requestResult,
				$this->extractSqlLabError($decoded),
			);
		}

		if (!$requestResult->isSuccess() || $requestResult->getHttpStatus() !== HttpStatus::OK)
		{
			$httpStatus = $requestResult->getHttpStatus();
			$code = $this->resolveErrorCode($decoded);
			if ($httpStatus >= HttpStatus::INTERNAL_SERVER_ERROR && $code !== SqlLabErrorCode::UNAVAILABLE)
			{
				// Superset raises a failed synchronous query as 500; a fault of the query itself is not
				// a failure of the service and must not move the portal to SUPERSET_STATUS_ERROR.
				$httpStatus = HttpStatus::UNPROCESSABLE_ENTITY;
			}

			return $this->createSqlLabErrorResult(
				$code,
				$httpStatus >= HttpStatus::BAD_REQUEST ? $httpStatus : HttpStatus::BAD_GATEWAY,
				$requestResult,
				$this->extractSqlLabError($decoded),
			);
		}

		$columns = [];
		foreach ((is_array($decoded['columns'] ?? null) ? $decoded['columns'] : []) as $column)
		{
			if (!is_array($column))
			{
				continue;
			}
			$columns[] = [
				'name' => (string)($column['name'] ?? $column['column_name'] ?? ''),
				'type' => (string)($column['type'] ?? ''),
				'isDttm' => (bool)($column['is_dttm'] ?? false),
			];
		}

		$rows = is_array($decoded['data'] ?? null) ? $decoded['data'] : [];
		$query = is_array($decoded['query'] ?? null) ? $decoded['query'] : [];

		$result = new Main\Result();
		$result->setData([
			'columns' => $columns,
			'rows' => $rows,
			'rowCount' => count($rows),
			'limit' => $queryLimit,
			'truncated' => $this->resolveTruncated($query),
		]);

		return $result;
	}

	/**
	 * Maps an allow-listed SQL Lab error type to a stable public code.
	 */
	private function resolveErrorCode(?array $decoded): string
	{
		if (!is_array($decoded))
		{
			return SqlLabErrorCode::UNAVAILABLE;
		}

		$errors = $decoded['errors'] ?? null;
		if (!is_array($errors))
		{
			return SqlLabErrorCode::UNAVAILABLE;
		}

		foreach ($errors as $error)
		{
			if (!is_array($error))
			{
				continue;
			}

			$errorType = $error['error_type'] ?? null;
			if (is_string($errorType) && isset(self::ERROR_TYPE_TO_CODE[$errorType]))
			{
				return self::ERROR_TYPE_TO_CODE[$errorType];
			}

			$trinoUserError = $this->resolveTrinoUserError($error['message'] ?? null);
			if ($trinoUserError !== null)
			{
				return $trinoUserError;
			}
		}

		return SqlLabErrorCode::UNAVAILABLE;
	}

	/**
	 * A query Trino refused as a fault of its own (a missing table or column, a type mismatch, a table closed by
	 * the table permissions) is a fault of the query; a table closed by the permissions is answered as a missing
	 * one by design.
	 */
	private function resolveTrinoUserError(mixed $message): ?string
	{
		if (!is_string($message) || preg_match(self::TRINO_USER_ERROR_PATTERN, $message, $matches) !== 1)
		{
			return null;
		}

		return in_array($matches[1], self::TRINO_ACCESS_DENIED_NAMES, true)
			? SqlLabErrorCode::ACCESS_DENIED
			: SqlLabErrorCode::INVALID_QUERY
		;
	}

	private function resolveTruncated(array $query): ?bool
	{
		$limitingFactor = $query['limitingFactor'] ?? null;
		if (!is_string($limitingFactor))
		{
			return null;
		}

		if (in_array($limitingFactor, self::LIMITED_FACTORS, true))
		{
			return true;
		}

		return $limitingFactor === 'NOT_LIMITED' ? false : null;
	}

	/**
	 * Pulls a human-readable error message out of a SQL Lab error payload.
	 */
	private function extractSqlLabError(?array $decoded): ?string
	{
		if (!is_array($decoded))
		{
			return null;
		}

		$errors = $decoded['errors'] ?? null;
		if (is_array($errors) && isset($errors[0]['message']) && $errors[0]['message'] !== '')
		{
			return (string)$errors[0]['message'];
		}

		foreach (['error', 'message'] as $key)
		{
			$message = $decoded[$key] ?? null;
			if (is_string($message) && $message !== '')
			{
				return $message;
			}
		}

		return null;
	}

	/**
	 * $httpStatus describes the transport and is read by the proxy controller; $detail keeps the
	 * verbatim Superset text for the AI channel, while the REST boundary answers with $code alone.
	 */
	private function createSqlLabErrorResult(
		string $code,
		int $httpStatus,
		?RequestResult $requestResult = null,
		?string $detail = null,
	): Main\Result
	{
		$result = new Main\Result();
		$result->addError(new Main\Error(
			self::ERROR_MESSAGES[$code] ?? self::ERROR_MESSAGES[SqlLabErrorCode::UNAVAILABLE],
			$code,
			$detail === null || $detail === '' ? null : ['detail' => $detail],
		));

		$data = ['httpStatus' => $httpStatus];
		if ($requestResult !== null)
		{
			$data['requestResult'] = $requestResult;
		}
		$result->setData($data);

		return $result;
	}

	private function getDatabaseService(): DatabaseService
	{
		return new DatabaseService($this->server, $this->connector);
	}

	private function getSqlLabApi(?string $actAsLogin = null): Api\SqlLab
	{
		return new Api\SqlLab($this->getConnectorFor($actAsLogin));
	}
}
