<?php

namespace Bitrix\Superset\Public\Providers;

use Bitrix\Main\Result;
use Bitrix\Superset\Internal\Services\DatasetService;
use Bitrix\Superset\Public\Support\AbstractPublicEntryPoint;

final class DatasetProvider extends AbstractPublicEntryPoint
{
	public function list(array $ids = [], array $neqIds = []): Result
	{
		return $this->getService()->list($ids, $neqIds);
	}

	public function listByTableName(string $tableName): Result
	{
		return $this->getService()->listByTableName($tableName);
	}

	public function getById(int $id): Result
	{
		return $this->getService()->get($id);
	}

	public function getByName(string $name): Result
	{
		return $this->getService()->getByName($name);
	}

	public function getCreateUrl(string $datasetName, bool $isVirtual = false): Result
	{
		return $this->getService()->getCreateUrl($datasetName, $isVirtual);
	}

	public function getUrlById(int $id): Result
	{
		return $this->getService()->getUrlById($id);
	}

	private function getService(): DatasetService
	{
		return new DatasetService($this->server, $this->connector);
	}
}
