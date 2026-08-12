<?php

namespace Bitrix\Superset\Public\Providers;

use Bitrix\Main\Result;
use Bitrix\Superset\Internal\Services\ChartService;
use Bitrix\Superset\Public\Support\AbstractPublicEntryPoint;

final class ChartProvider extends AbstractPublicEntryPoint
{
	public function list(array $ids = []): Result
	{
		return $this->getService()->list($ids);
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
