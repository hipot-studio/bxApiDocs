<?php

namespace Bitrix\Superset\Internal\Services;

use Bitrix\Main;
use Bitrix\Superset\Internal\Api;
use Bitrix\Superset\Internal\HttpStatus;
use Bitrix\Superset\Internal\Support\AbstractSupersetContext;

final class TableService extends AbstractSupersetContext
{
	private const SCHEMA = 'bitrix24';

	public function list(?string $schema = null): Main\Result
	{
		$databaseResult = $this->getDatabaseService()->getTrinoDatabaseId();
		if (!$databaseResult->isSuccess())
		{
			return $databaseResult;
		}

		$databaseId = (int)$databaseResult->getData()['id'];
		$requestResult = $this->getDatabaseApi()->getTables($databaseId, $schema ?? self::SCHEMA);
		if ($requestResult->getHttpStatus() !== HttpStatus::OK)
		{
			return $this->createRequestErrorResult($requestResult, 'Getting table list');
		}

		$decoded = $this->decode($requestResult->getAnswer());
		if (!is_array($decoded))
		{
			return $this->createErrorResult('Invalid table list response');
		}

		$tables = [];
		foreach (($decoded['result'] ?? []) as $item)
		{
			if (!is_array($item))
			{
				continue;
			}

			$tables[] = [
				'name' => (string)($item['value'] ?? $item['name'] ?? ''),
				'type' => (string)($item['type'] ?? 'table'),
				'schema' => (string)($item['schema'] ?? $schema ?? self::SCHEMA),
				'extra' => $item['extra'] ?? null,
			];
		}

		$result = new Main\Result();
		$result->setData([
			'tables' => $tables,
			'databaseId' => $databaseId,
		]);

		return $result;
	}

	public function getMetadata(string $tableName, ?string $schema = null): Main\Result
	{
		if ($tableName === '')
		{
			return $this->createErrorResult('Empty table name', null, HttpStatus::UNPROCESSABLE_ENTITY);
		}

		$databaseResult = $this->getDatabaseService()->getTrinoDatabaseId();
		if (!$databaseResult->isSuccess())
		{
			return $databaseResult;
		}

		$databaseId = (int)$databaseResult->getData()['id'];
		$requestResult = $this->getDatabaseApi()->getTableMetadata(
			$databaseId,
			$tableName,
			$schema ?? self::SCHEMA,
		);
		if ($requestResult->getHttpStatus() !== HttpStatus::OK)
		{
			return $this->createRequestErrorResult($requestResult, 'Getting table metadata');
		}

		$decoded = $this->decode($requestResult->getAnswer());
		if (!is_array($decoded))
		{
			return $this->createErrorResult('Invalid table metadata response');
		}

		$columns = [];
		foreach (($decoded['columns'] ?? []) as $column)
		{
			if (!is_array($column))
			{
				continue;
			}

			$columns[] = [
				'name' => (string)($column['name'] ?? ''),
				'type' => (string)($column['type'] ?? ''),
				'nullable' => (bool)($column['nullable'] ?? true),
				'default' => $column['default'] ?? null,
				'comment' => isset($column['comment']) ? (string)$column['comment'] : null,
				'keys' => $column['keys'] ?? [],
			];
		}

		$result = new Main\Result();
		$result->setData([
			'name' => (string)($decoded['name'] ?? $tableName),
			'schema' => $schema ?? self::SCHEMA,
			'columns' => $columns,
			'comment' => isset($decoded['comment']) ? (string)$decoded['comment'] : null,
			'databaseId' => $databaseId,
		]);

		return $result;
	}

	private function getDatabaseApi(): Api\Database
	{
		return new Api\Database($this->connector);
	}

	private function getDatabaseService(): DatabaseService
	{
		return new DatabaseService($this->server, $this->connector);
	}
}
