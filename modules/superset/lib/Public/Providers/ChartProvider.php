<?php

namespace Bitrix\Superset\Public\Providers;

use Bitrix\Main\Result;
use Bitrix\Superset\Internal\Services\ChartService;
use Bitrix\Superset\Public\Support\AbstractPublicEntryPoint;

final class ChartProvider extends AbstractPublicEntryPoint
{
	public function list(array $ids = [], ?string $nameFilter = null, ?int $page = null, ?int $pageSize = null): Result
	{
		return $this->getService()->list($ids, $nameFilter, $page, $pageSize);
	}

	public function getById(int $id): Result
	{
		return $this->getService()->get($id);
	}

	private function getService(): ChartService
	{
		return new ChartService($this->server, $this->connector);
	}
}
