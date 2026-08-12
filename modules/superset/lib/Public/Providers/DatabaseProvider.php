<?php

namespace Bitrix\Superset\Public\Providers;

use Bitrix\Main\Result;
use Bitrix\Superset\Internal\Services\DatabaseService;
use Bitrix\Superset\Public\Support\AbstractPublicEntryPoint;

final class DatabaseProvider extends AbstractPublicEntryPoint
{
	public function getTrinoConnection(): Result
	{
		return $this->getService()->getTrinoConnection();
	}

	private function getService(): DatabaseService
	{
		return new DatabaseService($this->server, $this->connector);
	}
}
