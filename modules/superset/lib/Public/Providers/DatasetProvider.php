<?php

namespace Bitrix\Superset\Public\Providers;

use Bitrix\Main\Result;
use Bitrix\Superset\Internal\Services\DatasetService;
use Bitrix\Superset\Public\Dto\SupersetUserReferenceDto;
use Bitrix\Superset\Public\Support\AbstractPublicEntryPoint;

final class DatasetProvider extends AbstractPublicEntryPoint
{
	/**
	 * @param SupersetUserReferenceDto|null $user When set, Superset lists datasets as this user (act-as).
	 */
	public function list(
		array $ids = [],
		array $neqIds = [],
		?int $page = null,
		?int $pageSize = null,
		?SupersetUserReferenceDto $user = null,
	): Result
	{
		return $this->getService()->list($ids, $neqIds, $page, $pageSize, $user?->getLogin());
	}

	public function listPhysical(): Result
	{
		return $this->getService()->listPhysical();
	}

	public function listByTableName(string $tableName): Result
	{
		return $this->getService()->listByTableName($tableName);
	}

	/**
	 * @param SupersetUserReferenceDto|null $user When set, Superset reads the dataset as this user (act-as).
	 */
	public function getById(int $id, ?SupersetUserReferenceDto $user = null): Result
	{
		return $this->getService()->get($id, $user?->getLogin());
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
