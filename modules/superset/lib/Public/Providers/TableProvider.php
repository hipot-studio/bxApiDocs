<?php

namespace Bitrix\Superset\Public\Providers;

use Bitrix\Main\Result;
use Bitrix\Superset\Internal\Services\TableService;
use Bitrix\Superset\Public\Support\AbstractPublicEntryPoint;

final class TableProvider extends AbstractPublicEntryPoint
{
	public function list(?string $schema = null): Result
	{
		return $this->getService()->list($schema);
	}

	public function getMetadata(string $tableName, ?string $schema = null): Result
	{
		return $this->getService()->getMetadata($tableName, $schema);
	}

	private function getService(): TableService
	{
		return new TableService($this->server, $this->connector);
	}
}
