<?php

namespace Bitrix\Superset\Internal\Services;

use Bitrix\Main;
use Bitrix\Superset\Internal\Api;
use Bitrix\Superset\Internal\HttpStatus;
use Bitrix\Superset\Internal\Support\AbstractSupersetContext;
use Bitrix\Superset\Internal\Support\SqlReadOnlyClassifier;

final class SqlLabService extends AbstractSupersetContext
{
	private const SCHEMA = 'bitrix24';
	private const DEFAULT_ROW_LIMIT = 1000;
	private const MAX_ROW_LIMIT = 10000;

	/**
	 * Executes a read-only SQL query through Superset SQL Lab (synchronous mode)
	 * and returns the result rows and column metadata.
	 *
	 * Before the query leaves this module it is validated by
	 * {@see SqlReadOnlyClassifier}: only a single read-only SELECT (or
	 * WITH ... SELECT) is allowed, everything else (DML/DDL, multiple statements,
	 * SELECT ... INTO, obfuscation via comments or string literals) is rejected
	 * with HTTP 422 regardless of the caller or of Superset's own `allow_dml`
	 * flag. This makes the read-only guarantee independent of transport (box vs
	 * cloud) and of the BI database configuration.
	 *
	 * @param string $sql Raw SQL SELECT to execute against the BI (Trino) database.
	 * @param int|null $limit Row cap applied by Superset (queryLimit). Clamped to 1..MAX_ROW_LIMIT.
	 * @return Main\Result
	 */
	public function execute(string $sql, ?int $limit = null): Main\Result
	{
		$sql = trim($sql);
		if ($sql === '')
		{
			return $this->createErrorResult('SQL is required', null, HttpStatus::UNPROCESSABLE_ENTITY);
		}

		$classification = (new SqlReadOnlyClassifier())->classify($sql);
		if (!$classification->isReadOnly)
		{
			return $this->createErrorResult(
				(string)$classification->reason,
				null,
				HttpStatus::UNPROCESSABLE_ENTITY,
			);
		}

		$queryLimit = max(1, min(self::MAX_ROW_LIMIT, $limit ?? self::DEFAULT_ROW_LIMIT));

		$databaseResult = $this->getDatabaseService()->getTrinoDatabaseId();
		if (!$databaseResult->isSuccess())
		{
			return $databaseResult;
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

		$requestResult = $this->getSqlLabApi()->execute($payload);
		$decoded = $this->decode($requestResult->getAnswer());
		$status = is_array($decoded) ? (string)($decoded['status'] ?? '') : '';

		// Superset returns HTTP 200 with {"status":"failed","errors":[...]} for SQL
		// errors (syntax, timeout, table access). Surface that message verbatim so
		// the agent can fix and retry its query (PRD AC-008 / AC-031).
		if ($status === 'failed')
		{
			$message = $this->extractSqlLabError($decoded) ?? 'SQL execution failed';

			return $this->createErrorResult($message, $requestResult, HttpStatus::UNPROCESSABLE_ENTITY);
		}

		if (!$requestResult->isSuccess() || $requestResult->getHttpStatus() !== HttpStatus::OK)
		{
			$message = $this->extractSqlLabError($decoded);
			if ($message !== null)
			{
				return $this->createErrorResult($message, $requestResult, $requestResult->getHttpStatus());
			}

			return $this->createRequestErrorResult($requestResult, 'Executing SQL query');
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
			'limitingFactor' => (string)($query['limitingFactor'] ?? ''),
		]);

		return $result;
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

		if (isset($decoded['error']) && is_string($decoded['error']) && $decoded['error'] !== '')
		{
			return $decoded['error'];
		}

		if (isset($decoded['message']) && is_string($decoded['message']) && $decoded['message'] !== '')
		{
			return $decoded['message'];
		}

		return null;
	}

	private function getDatabaseService(): DatabaseService
	{
		return new DatabaseService($this->server, $this->connector);
	}

	private function getSqlLabApi(): Api\SqlLab
	{
		return new Api\SqlLab($this->connector);
	}
}
