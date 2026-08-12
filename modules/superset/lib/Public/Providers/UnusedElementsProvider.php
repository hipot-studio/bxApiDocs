<?php

namespace Bitrix\Superset\Public\Providers;

use Bitrix\Main\Result;
use Bitrix\Superset\Internal\Services\UnusedElementsService;
use Bitrix\Superset\Public\Support\AbstractPublicEntryPoint;

final class UnusedElementsProvider extends AbstractPublicEntryPoint
{
	public function list(array $ormParams = []): Result
	{
		return $this->getService()->get($ormParams);
	}

	private function getService(): UnusedElementsService
	{
		return new UnusedElementsService($this->server, $this->connector);
	}
}
