<?php

namespace Bitrix\Superset\Internal\Services;

use Bitrix\Main;
use Bitrix\Superset\Internal\Api;
use Bitrix\Superset\Internal\HttpStatus;
use Bitrix\Superset\Internal\Support\AbstractSupersetContext;
use Bitrix\Superset\Internal\Support\SqlReadOnlyClassifier;

final class VirtualDatasetService extends AbstractSupersetContext
{
	private const SCHEMA = 'bitrix24';
	private const MAX_NAME_LENGTH = 250;

	/**
	 * Creates a virtual dataset (SQL-backed view) in Superset.
	 *
	 * @param string $datasetName Display name of the dataset (1..250).
	 * @param string $sourceTable Source physical table name in Trino.
	 * @param string[] $columns Columns to project from the source table.
	 * @param int $ownerId Superset owner id.
	 * @param string|null $sourceSchema Schema of the source table (defaults to bitrix24).
	 * @return Main\Result
	 */
	public function create(
		string $datasetName,
		string $sourceTable,
		array $columns,
		int $ownerId,
		?string $sourceSchema = null,
	): Main\Result
	{
		$datasetName = trim($datasetName);
		$sourceTable = trim($sourceTable);
		$sourceSchema = trim($sourceSchema ?? self::SCHEMA);

		if ($datasetName === '' || mb_strlen($datasetName) > self::MAX_NAME_LENGTH)
		{
			return $this->createErrorResult(
				'Dataset name must be 1..' . self::MAX_NAME_LENGTH . ' chars',
				null,
				HttpStatus::UNPROCESSABLE_ENTITY,
			);
		}
		if ($sourceTable === '')
		{
			return $this->createErrorResult('Source table name is required', null, HttpStatus::UNPROCESSABLE_ENTITY);
		}
		if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $sourceTable))
		{
			return $this->createErrorResult('Source table name is invalid', null, HttpStatus::UNPROCESSABLE_ENTITY);
		}
		if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $sourceSchema))
		{
			return $this->createErrorResult('Source schema name is invalid', null, HttpStatus::UNPROCESSABLE_ENTITY);
		}
		$rejectedColumns = [];
		$columnNames = $this->normalizeColumns($columns, $rejectedColumns);
		if (!empty($rejectedColumns))
		{
			return $this->createErrorResult(
				'Invalid column names: ' . implode(', ', $rejectedColumns),
				null,
				HttpStatus::UNPROCESSABLE_ENTITY,
			);
		}
		if (empty($columnNames))
		{
			return $this->createErrorResult('At least one column is required', null, HttpStatus::UNPROCESSABLE_ENTITY);
		}

		$databaseResult = $this->getDatabaseService()->getTrinoDatabaseId();
		if (!$databaseResult->isSuccess())
		{
			return $databaseResult;
		}
		$databaseId = (int)$databaseResult->getData()['id'];

		$sql = $this->buildSelectSql($sourceTable, $sourceSchema, $columnNames);

		$payload = [
			'database' => $databaseId,
			'owners' => [$ownerId],
			'schema' => self::SCHEMA,
			'table_name' => $datasetName,
			'sql' => $sql,
			'is_managed_externally' => false,
		];

		$requestResult = $this->getDatasetApi()->createDataset($payload);
		if (
			!$requestResult->isSuccess()
			|| $requestResult->getHttpStatus() !== HttpStatus::CREATED
		)
		{
			return $this->createRequestErrorResult($requestResult, 'Adding virtual dataset');
		}

		$decoded = $this->decode($requestResult->getAnswer());
		$result = new Main\Result();
		$result->setData([
			'id' => (int)($decoded['id'] ?? 0),
			'name' => $datasetName,
			'sql' => $sql,
			'sourceTable' => $sourceTable,
			'sourceSchema' => $sourceSchema,
			'columns' => $columnNames,
			'body' => $requestResult->getAnswer(),
		]);

		return $result;
	}

	/**
	 * Creates a virtual dataset (SQL-backed view) in Superset from a raw SQL query.
	 *
	 * @param string $datasetName Display name of the dataset (1..250).
	 * @param string $sql Raw SQL SELECT used as the dataset source.
	 * @param int $ownerId Superset owner id.
	 * @return Main\Result
	 */
	public function createFromSql(string $datasetName, string $sql, int $ownerId): Main\Result
	{
		$datasetName = trim($datasetName);
		$sql = trim($sql);

		if ($datasetName === '' || mb_strlen($datasetName) > self::MAX_NAME_LENGTH)
		{
			return $this->createErrorResult(
				'Dataset name must be 1..' . self::MAX_NAME_LENGTH . ' chars',
				null,
				HttpStatus::UNPROCESSABLE_ENTITY,
			);
		}
		if ($sql === '')
		{
			return $this->createErrorResult('SQL is required', null, HttpStatus::UNPROCESSABLE_ENTITY);
		}

		// Defense-in-depth: a virtual dataset stores its SQL as a source view, so the
		// same read-only guarantee applied to SQL Lab (execute) must hold here too -
		// only a single read-only SELECT / WITH ... SELECT may be persisted.
		$classification = (new SqlReadOnlyClassifier())->classify($sql);
		if (!$classification->isReadOnly)
		{
			return $this->createErrorResult(
				(string)$classification->reason,
				null,
				HttpStatus::UNPROCESSABLE_ENTITY,
			);
		}

		$databaseResult = $this->getDatabaseService()->getTrinoDatabaseId();
		if (!$databaseResult->isSuccess())
		{
			return $databaseResult;
		}
		$databaseId = (int)$databaseResult->getData()['id'];

		$payload = [
			'database' => $databaseId,
			'owners' => [$ownerId],
			'schema' => self::SCHEMA,
			'table_name' => $datasetName,
			'sql' => $sql,
			'is_managed_externally' => false,
		];

		$requestResult = $this->getDatasetApi()->createDataset($payload);
		if (
			!$requestResult->isSuccess()
			|| $requestResult->getHttpStatus() !== HttpStatus::CREATED
		)
		{
			return $this->createRequestErrorResult($requestResult, 'Adding virtual dataset from SQL');
		}

		$decoded = $this->decode($requestResult->getAnswer());
		$result = new Main\Result();
		$result->setData([
			'id' => (int)($decoded['id'] ?? 0),
			'name' => $datasetName,
			'sql' => $sql,
			'body' => $requestResult->getAnswer(),
		]);

		return $result;
	}

	/**
	 * @param string[]|array<int, array{name: string}> $columns
	 * @param string[] $rejected Collects column names that failed validation.
	 * @return string[]
	 */
	private function normalizeColumns(array $columns, array &$rejected = []): array
	{
		$normalized = [];
		foreach ($columns as $column)
		{
			if (is_array($column))
			{
				$column = (string)($column['name'] ?? '');
			}
			$column = trim((string)$column);
			if ($column === '' || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $column))
			{
				$rejected[] = $column;
				continue;
			}
			$normalized[] = $column;
		}

		return array_values(array_unique($normalized));
	}

	private function buildSelectSql(string $table, string $schema, array $columns): string
	{
		$quote = static fn(string $identifier): string => '"' . str_replace('"', '""', $identifier) . '"';

		$quotedColumns = array_map($quote, $columns);

		$tableRef = $quote($schema) . '.' . $quote($table);

		return 'SELECT ' . implode(', ', $quotedColumns) . ' FROM ' . $tableRef;
	}

	private function getDatabaseService(): DatabaseService
	{
		return new DatabaseService($this->server, $this->connector);
	}

	private function getDatasetApi(): Api\Dataset
	{
		return new Api\Dataset($this->connector);
	}
}
