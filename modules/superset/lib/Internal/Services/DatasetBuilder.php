<?php

namespace Bitrix\Superset\Internal\Services;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\Web\Json;
use Bitrix\Superset\Internal\Api\Dataset;
use Bitrix\Superset\Internal\Connector\SupersetInstance;
use Bitrix\Superset\Internal\HttpStatus;

class DatasetBuilder
{
	private Dataset $datasetApi;
	private ?int $databaseId = null;
	private ?string $schema = null;
	private ?string $tableName = null;
	private ?string $sql = null;
	private array $owners = [];
	private bool $isManagedExternally = true;

	public function __construct(SupersetInstance $connector)
	{
		$this->datasetApi = new Dataset($connector);
	}

	public function setDatabaseId(int $id): self
	{
		$this->databaseId = $id;

		return $this;
	}

	public function setTableName(string $name): self
	{
		$this->tableName = $name;

		return $this;
	}

	public function setSql(string $sql): self
	{
		$this->sql = $sql;

		return $this;
	}

	public function setOwners(array $ownerIds): self
	{
		$this->owners = $ownerIds;

		return $this;
	}

	public function setSchema(string $schema): self
	{
		$this->schema = $schema;

		return $this;
	}

	public function build(): Result
	{
		$this->checkParameters();

		$result = new Result();

		$existingDataset = $this->getExistingDataset((string)$this->tableName);
		if (!$existingDataset->isSuccess())
		{
			$result->addErrors($existingDataset->getErrors());

			return $result;
		}

		$existingDatasetId = $existingDataset->getData()['id'] ?? null;
		$datasetResult = $existingDatasetId
			? $this->updateDataset((int)$existingDatasetId)
			: $this->createDataset()
		;

		if (
			$datasetResult->isSuccess()
			&& in_array($datasetResult->getHttpStatus(), [HttpStatus::CREATED, HttpStatus::OK], true)
		)
		{
			try
			{
				$answer = Json::decode($datasetResult->getAnswer());
				$result->setData(['id' => (int)$answer['id']]);
			}
			catch (ArgumentException)
			{
				$result->addError(
					new Error("Could not decode create/update dataset response for '{$this->tableName}'")
				);

				return $result;
			}
		}
		else
		{
			$errorMessage = "Failed to create/update dataset '{$this->tableName}'";
			$errorMessage .= "; Answer: {$datasetResult->getAnswer()}";
			$errorMessage .= "; Http status: {$datasetResult->getHttpStatus()}";
			$errorMessage .= "; Errors: " . implode(', ', $datasetResult->getErrorMessages());

			$result->addError(new Error($errorMessage));

			return $result;
		}

		return $result;
	}

	private function checkParameters(): void
	{
		if ($this->databaseId === null)
		{
			throw new ArgumentException('Database ID must be set', 'databaseId');
		}

		if ($this->tableName === null)
		{
			throw new ArgumentException('Table name must be set', 'tableName');
		}

		if ($this->sql === null)
		{
			throw new ArgumentException('SQL must be set', 'sql');
		}

		if (empty($this->owners))
		{
			throw new ArgumentException('At least one owner must be set', 'owners');
		}
	}

	private function getExistingDataset(string $name): Result
	{
		$result = new Result();

		try
		{
			$getResult = $this->datasetApi->getDatasetByName($name);
			if ($getResult->isSuccess() && $getResult->getHttpStatus() === HttpStatus::OK)
			{
				$existingDataset = Json::decode($getResult->getAnswer());
				if (isset($existingDataset['count']) && $existingDataset['count'] > 0)
				{
					$result->setData([
						'id' => (int)$existingDataset['result'][0]['id'],
					]);
				}
			}
			else
			{
				$errorMessage = "Failed to get dataset '{$name}'";
				$errorMessage .= "; Answer: {$getResult->getAnswer()}";
				$errorMessage .= "; Http status: {$getResult->getHttpStatus()}";
				$errorMessage .= "; Errors: " . implode(', ', $getResult->getErrorMessages());

				$result->addError(new Error($errorMessage));

				return $result;
			}
		}
		catch (ArgumentException)
		{
			$result->addError(new Error("Could not decode get dataset response for '{$name}'"));

			return $result;
		}

		return $result;
	}

	private function createDataset(): Result
	{
		$payload = [
			'database' => $this->databaseId,
			'schema' => $this->schema,
			'table_name' => $this->tableName,
			'sql' => $this->sql,
			'owners' => $this->owners,
			'is_managed_externally' => $this->isManagedExternally,
		];

		return $this->datasetApi->createDataset($payload);
	}

	private function updateDataset(int $id): Result
	{
		$payload = [
			'sql' => $this->sql,
			'owners' => $this->owners,
			'is_managed_externally' => $this->isManagedExternally,
		];

		return $this->datasetApi->updateDataset($id, $payload, true);
	}
}
