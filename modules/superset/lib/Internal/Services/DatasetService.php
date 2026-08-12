<?php

namespace Bitrix\Superset\Internal\Services;

use Bitrix\Main;
use Bitrix\Main\Web\Uri;
use Bitrix\Superset\Internal\Api;
use Bitrix\Superset\Internal\HttpStatus;
use Bitrix\Superset\Internal\Dto;
use Bitrix\Superset\Internal\Support\AbstractSupersetContext;
use Bitrix\Superset\Internal\Services\ImportExport\ArchiveRepacker;
use Bitrix\Superset\Internal\Services\ImportExport\EntitiesImporter;

final class DatasetService extends AbstractSupersetContext
{
	private const SUPERSET_USER_ID = 1;
	private const SCHEMA = 'bitrix24';

	private const TYPE_MAP = [
		'INT' => 'BIGINT',
		'STRING' => 'VARCHAR',
		'DOUBLE' => 'DOUBLE',
		'DATE' => 'DATE',
		'DATETIME' => 'DATETIME',
	];

	public function list(array $ids = [], array $neqIds = []): Main\Result
	{
		$filter = [];
		if (!empty($ids))
		{
			$filter[] = [
				'col' => 'id',
				'opr' => 'in',
				'value' => [$ids],
			];
		}
		elseif (!empty($neqIds))
		{
			foreach ($neqIds as $neqId)
			{
				$filter[] = [
					'col' => 'id',
					'opr' => 'neq',
					'value' => (int)$neqId,
				];
			}
		}

		$preparedDataset = [];
		$page = 0;
		$datasetApi = $this->getDatasetApi();

		do
		{
			$requestResult = $datasetApi->getDatasetsList($filter, $page, 100);
			if ($requestResult->getHttpStatus() !== HttpStatus::OK)
			{
				return $this->createRequestErrorResult($requestResult, 'Getting dataset list');
			}

			$datasets = $this->decode($requestResult->getAnswer());
			if (!is_array($datasets))
			{
				return $this->createErrorResult('Invalid dataset list response');
			}

			foreach (($datasets['result'] ?? []) as $dataset)
			{
				if (is_array($dataset))
				{
					$preparedDataset[] = $this->prepareResultDataset($dataset);
				}
			}

			$isRepeatRequest = count($datasets['ids'] ?? []) > 0;
			$page++;
		}
		while ($isRepeatRequest);

		$result = new Main\Result();
		$result->setData([
			'datasets' => $this->mapUsersToClientIds($preparedDataset),
		]);

		return $result;
	}

	public function listByTableName(string $tableName): Main\Result
	{
		$databaseResult = $this->getDatabaseService()->getTrinoDatabaseId();
		if (!$databaseResult->isSuccess())
		{
			return $databaseResult;
		}

		$databaseId = (int)$databaseResult->getData()['id'];
		$requestResult = $this->getDatasetApi()->getRelatedDatasetsFromTable($databaseId, $tableName);
		if ($requestResult->getHttpStatus() !== HttpStatus::OK)
		{
			return $this->createRequestErrorResult($requestResult, 'Getting dataset list by table names');
		}

		$decoded = $this->decode($requestResult->getAnswer());
		if (!is_array($decoded))
		{
			return $this->createErrorResult('Invalid dataset list by table response');
		}

		$items = is_array($decoded['result'] ?? null) ? $decoded['result'] : [];
		foreach ($items as &$item)
		{
			if (isset($item['id']))
			{
				$url = new Uri($this->server->getHost() . '/explore/');
				$url->addParams([
					'datasource_type' => 'table',
					'datasource_id' => $item['id'],
				]);
				$item['url'] = $url->getLocator();
			}
		}
		unset($item);

		$result = new Main\Result();
		$result->setData([
			'datasets' => $items,
		]);

		return $result;
	}

	public function get(int $id): Main\Result
	{
		$datasetResult = $this->getDatasetByIdInternal($id);
		if (!$datasetResult->isSuccess())
		{
			return $datasetResult;
		}

		$dataset = $this->prepareResultDataset($datasetResult->getData()['dataset']);

		$result = new Main\Result();
		$result->setData([
			'dataset' => $dataset,
		]);

		return $result;
	}

	public function getByName(string $name): Main\Result
	{
		$datasetResult = $this->getDatasetByNameInternal($name);
		if (!$datasetResult->isSuccess())
		{
			return $datasetResult;
		}

		$dataset = $this->prepareResultDataset($datasetResult->getData()['dataset']);

		$result = new Main\Result();
		$result->setData([
			'dataset' => $dataset,
		]);

		return $result;
	}

	public function create(array $fields, int $ownerId): Main\Result
	{
		$databaseResult = $this->getDatabaseService()->getTrinoDatabaseId();
		if (!$databaseResult->isSuccess())
		{
			return $databaseResult;
		}

		$payload = [
			'database' => $databaseResult->getData()['id'],
			'owners' => [$ownerId],
			'schema' => self::SCHEMA,
			'table_name' => (string)($fields['name'] ?? ''),
		];

		$requestResult = $this->getDatasetApi()->createDataset($payload);
		if (
			!$requestResult->isSuccess()
			|| $requestResult->getHttpStatus() !== HttpStatus::CREATED
		)
		{
			return $this->createRequestErrorResult($requestResult, 'Adding dataset');
		}

		$decoded = $this->decode($requestResult->getAnswer());
		$result = new Main\Result();
		$result->setData([
			'id' => (int)($decoded['id'] ?? 0),
			'body' => $requestResult->getAnswer(),
		]);

		return $result;
	}

	public function update(int $id, array $fields): Main\Result
	{
		if ($id > 0)
		{
			$datasetResult = $this->getDatasetByIdInternal($id);
		}
		elseif (isset($fields['table_name']))
		{
			$datasetResult = $this->getDatasetByNameInternal((string)$fields['table_name']);
		}
		else
		{
			return $this->createErrorResult(
				'Dataset id or table_name not found',
				null,
				HttpStatus::UNPROCESSABLE_ENTITY
			);
		}

		if (!$datasetResult->isSuccess())
		{
			return $datasetResult;
		}

		$dataset = $datasetResult->getData()['dataset'];
		$payload = [];

		if (!empty($fields['columns']))
		{
			$columnsResult = $this->prepareColumns($dataset['columns'] ?? [], $fields['columns']);
			if (!$columnsResult->isSuccess())
			{
				return $columnsResult;
			}

			$payload['columns'] = $columnsResult->getData()['columns'];
		}

		if (!empty($fields['metrics']))
		{
			$metricsResult = $this->prepareMetrics($fields['metrics']);
			if (!$metricsResult->isSuccess())
			{
				return $metricsResult;
			}

			$payload['metrics'] = $metricsResult->getData()['metrics'];
		}

		$requestResult = $this->getDatasetApi()->updateDataset((int)$dataset['id'], $payload);
		if ($requestResult->getHttpStatus() !== HttpStatus::OK)
		{
			return $this->createRequestErrorResult($requestResult, 'Updating dataset');
		}

		$result = new Main\Result();
		$result->setData([
			'id' => (int)$dataset['id'],
			'body' => $requestResult->getAnswer(),
		]);

		return $result;
	}

	public function replaceOwner(int $fromOwnerId, array $replacementOwnerIds, int $maxExecutionTime = 0): Main\Result
	{
		$requestResult = $this->getDatasetApi()->getDatasetsByOwnerId($fromOwnerId);
		if ($requestResult->getHttpStatus() !== HttpStatus::OK)
		{
			return $this->createRequestErrorResult($requestResult, 'Getting datasets by owner');
		}

		$decoded = $this->decode($requestResult->getAnswer());
		if (!is_array($decoded))
		{
			return $this->createErrorResult('Invalid dataset owner replacement response');
		}

		$isUpdated = false;
		$timeStart = Main\Diag\Helper::getCurrentMicrotime();

		foreach (($decoded['result'] ?? []) as $dataset)
		{
			if (!is_array($dataset))
			{
				continue;
			}

			$ownerIds = array_map('intval', array_column($dataset['owners'] ?? [], 'id'));
			if (!in_array($fromOwnerId, $ownerIds, true))
			{
				continue;
			}

			$isUpdated = true;
			if (count($ownerIds) > 1)
			{
				$key = array_search($fromOwnerId, $ownerIds, true);
				if ($key !== false)
				{
					unset($ownerIds[$key]);
				}
			}
			else
			{
				$ownerIds = $replacementOwnerIds;
			}

			$ownerIds = array_values(array_unique(array_map('intval', $ownerIds)));
			sort($ownerIds);

			$updateResult = $this->getDatasetApi()->updateDataset((int)($dataset['id'] ?? 0), ['owners' => $ownerIds]);
			if ($updateResult->getHttpStatus() !== HttpStatus::OK)
			{
				return $this->createRequestErrorResult($updateResult, 'Replacing dataset owner');
			}

			if (
				$maxExecutionTime > 0
				&& (Main\Diag\Helper::getCurrentMicrotime() - $timeStart) > $maxExecutionTime
			)
			{
				break;
			}
		}

		$result = new Main\Result();
		$result->setData([
			'updated' => $isUpdated,
			'is_running' => $isUpdated,
		]);

		return $result;
	}

	public function delete(int $id): Main\Result
	{
		$datasetResult = $this->getDatasetByIdInternal($id);
		if (!$datasetResult->isSuccess())
		{
			return $datasetResult;
		}

		$dataset = $datasetResult->getData()['dataset'];
		$ownerIdList = [];
		foreach (($dataset['owners'] ?? []) as $owner)
		{
			if (is_array($owner) && isset($owner['id']))
			{
				$ownerIdList[] = (int)$owner['id'];
			}
		}

		if (count($ownerIdList) === 1 && current($ownerIdList) === self::SUPERSET_USER_ID)
		{
			return $this->createErrorResult(
				'Permission denied',
				null,
				HttpStatus::FORBIDDEN
			);
		}

		$requestResult = $this->getDatasetApi()->deleteDataset($id);
		if ($requestResult->getHttpStatus() !== HttpStatus::OK)
		{
			return $this->createRequestErrorResult($requestResult, 'Deleting dataset');
		}

		$result = new Main\Result();
		$result->setData([
			'body' => 'OK',
			'id' => $id,
		]);

		return $result;
	}

	public function import(array $uploadedFile, string $currency): Main\Result
	{
		if (\CFile::CheckFile($uploadedFile, strExt: 'zip') !== '')
		{
			return $this->createErrorResult('DatasetImport. File for import not found');
		}

		$connectionResult = $this->getDatabaseService()->getTrinoConnection();
		if (!$connectionResult->isSuccess())
		{
			return $connectionResult;
		}

		$connection = $connectionResult->getData()['connection'];
		$databaseDto = Dto\Convertor\Database\ArrayToDatabase::convert($connection);
		$databaseYaml = Dto\Convertor\Database\DatabaseToYaml::convert($databaseDto);

		$archiveRepacker = new ArchiveRepacker();
		$saveResult = $archiveRepacker->saveUploadedFile($uploadedFile);
		if (!$saveResult->isSuccess())
		{
			return $this->createErrorResult(
				'DatasetImport. SaveAndGetFileFromPost: ' . implode("\n", $saveResult->getErrorMessages())
			);
		}

		$datasetFileId = $saveResult->getData()['id'];
		$datasetFile = $saveResult->getData()['file'];

		try
		{
			$importResult = (new EntitiesImporter($this->server, $this->connector, $archiveRepacker))->importDataset(
				$datasetFile,
				$databaseDto->databaseName,
				$databaseYaml,
				$databaseDto->uuid,
				$currency,
			);
		}
		finally
		{
			\CFile::Delete($datasetFileId);
		}

		if (!$importResult->isSuccess())
		{
			return $this->createErrorResult(
				'DatasetImport. Import dataset errors: ' . implode("\n", $importResult->getErrorMessages())
			);
		}

		$result = new Main\Result();
		$result->setData([
			'body' => $importResult->getData()['importAnswer'] ?? '',
			'usedLangId' => $importResult->getData()['usedLangId'] ?? '',
		]);

		return $result;
	}

	public function getCreateUrl(string $datasetName, bool $isVirtual = false): Main\Result
	{
		$databaseResult = $this->getDatabaseService()->getTrinoDatabaseId();
		if (!$databaseResult->isSuccess())
		{
			return $databaseResult;
		}

		$databaseId = (int)$databaseResult->getData()['id'];
		if ($isVirtual)
		{
			$url = new Uri($this->server->getHost() . '/sqllab/');
			$url->addParams([
				'dbname' => Api\Database::TRINO_DATABASE_NAME,
				'schema' => self::SCHEMA,
				'table' => $datasetName,
				'name' => $datasetName,
				'sql' => "SELECT * FROM {$datasetName} LIMIT 100;",
				'autorun' => 'true',
			]);
		}
		else
		{
			$url = new Uri($this->server->getHost() . '/dataset/add/');
			$url->addParams([
				'db' => $databaseId,
				'schema' => self::SCHEMA,
				'table' => $datasetName,
			]);
		}

		$result = new Main\Result();
		$result->setData([
			'url' => $url->getLocator(),
		]);

		return $result;
	}

	public function getUrlById(int $id): Main\Result
	{
		$url = new Uri($this->server->getHost() . '/explore/');
		$url->addParams([
			'datasource_type' => 'table',
			'datasource_id' => $id,
		]);
		$result = new Main\Result();
		$result->setData([
			'url' => $url->getLocator(),
		]);

		return $result;
	}

	public function initRequiredDataset(array $tables = []): Main\Result
	{
		$buildResult = (new CreateFilterDataset($this->server, $this->connector))->run($tables);
		if (!$buildResult->isSuccess())
		{
			return $buildResult;
		}

		$result = new Main\Result();
		$result->setData([
			'status' => 'ok',
			'datasets' => $buildResult->getData(),
		]);

		return $result;
	}

	private function getDatasetByIdInternal(int $id): Main\Result
	{
		$requestResult = $this->getDatasetApi()->getDatasetById($id);
		if ($requestResult->getHttpStatus() !== HttpStatus::OK)
		{
			return $this->createRequestErrorResult($requestResult, 'Dataset get');
		}

		$decoded = $this->decode($requestResult->getAnswer());
		if (!is_array($decoded) || !is_array($decoded['result'] ?? null))
		{
			return $this->createErrorResult('Invalid dataset response');
		}

		$result = new Main\Result();
		$result->setData([
			'dataset' => $decoded['result'],
		]);

		return $result;
	}

	private function getDatasetByNameInternal(string $name): Main\Result
	{
		$requestResult = $this->getDatasetApi()->getDatasetByName($name);
		if ($requestResult->getHttpStatus() !== HttpStatus::OK)
		{
			return $this->createRequestErrorResult($requestResult, 'Dataset get by name');
		}

		$decoded = $this->decode($requestResult->getAnswer());
		$dataset = is_array($decoded) ? current($decoded['result'] ?? []) : null;
		if (!is_array($dataset))
		{
			return $this->createErrorResult(
				"Dataset with name '{$name}' not found",
				$requestResult,
				HttpStatus::NOT_FOUND
			);
		}

		return $this->getDatasetByIdInternal((int)$dataset['id']);
	}

	private function prepareColumns(array $existingColumns, array $newColumns): Main\Result
	{
		$result = new Main\Result();
		$columns = [];

		foreach ($newColumns as $newColumn)
		{
			if (!is_array($newColumn))
			{
				continue;
			}

			$existingColumn = array_filter($existingColumns, static function ($item) use ($newColumn) {
				return is_array($item) && ($item['column_name'] ?? null) === strtolower((string)($newColumn['name'] ?? ''));
			});

			if (!empty($existingColumn))
			{
				$existingColumn = current($existingColumn);
				$columns[] = [
					'advanced_data_type' => $existingColumn['advanced_data_type'],
					'column_name' => $existingColumn['column_name'],
					'description' => $existingColumn['description'],
					'expression' => $existingColumn['expression'],
					'extra' => $existingColumn['extra'],
					'filterable' => $existingColumn['filterable'],
					'groupby' => $existingColumn['groupby'],
					'id' => $existingColumn['id'],
					'is_active' => $existingColumn['is_active'],
					'is_dttm' => $existingColumn['is_dttm'],
					'python_date_format' => $existingColumn['python_date_format'],
					'type' => $existingColumn['type'],
					'uuid' => $existingColumn['uuid'],
					'verbose_name' => $existingColumn['verbose_name'],
				];

				continue;
			}

			$columnType = self::TYPE_MAP[$newColumn['type'] ?? ''] ?? null;
			if ($columnType === null)
			{
				return $this->createErrorResult(
					'Column type not found or EXTERNAL_ID null',
					null,
					HttpStatus::OK
				);
			}

			$columns[] = [
				'column_name' => strtolower((string)$newColumn['name']),
				'extra' => '{}',
				'filterable' => true,
				'groupby' => true,
				'is_dttm' => false,
				'python_date_format' => null,
				'type' => $columnType,
			];
		}

		$result->setData([
			'columns' => $columns,
		]);

		return $result;
	}

	private function prepareMetrics(array $newMetrics): Main\Result
	{
		$result = new Main\Result();
		$metrics = [];

		foreach ($newMetrics as $newMetric)
		{
			if (!is_array($newMetric))
			{
				continue;
			}

			$metrics[] = $newMetric;
		}

		$result->setData([
			'metrics' => $metrics,
		]);

		return $result;
	}

	private function prepareResultDataset(array $supersetDataset): array
	{
		$tableName = (string)($supersetDataset['table_name'] ?? '');
		$editUrl = new Uri($this->connector->buildRequestUrl('/tablemodelview/list/'));
		$editUrl->addParams([
			'filters' => "(table_name:'{$tableName}')",
		]);

		return [
			'id' => (int)($supersetDataset['id'] ?? 0),
			'table_name' => $tableName,
			'description' => $supersetDataset['description'] ?? '',
			'owners' => $supersetDataset['owners'] ?? [],
			'columns' => is_array($supersetDataset['columns'] ?? null) ? $supersetDataset['columns'] : [],
			'metrics' => is_array($supersetDataset['metrics'] ?? null) ? $supersetDataset['metrics'] : [],
			'edit_url' => $editUrl->getLocator(),
		];
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
