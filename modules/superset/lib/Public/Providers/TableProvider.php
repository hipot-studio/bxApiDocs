<?php

namespace Bitrix\Superset\Public\Providers;

use Bitrix\Main\Result;
use Bitrix\Superset\Internal\Services\TableService;
use Bitrix\Superset\Public\Dto\SupersetUserReferenceDto;
use Bitrix\Superset\Public\Support\AbstractPublicEntryPoint;

final class TableProvider extends AbstractPublicEntryPoint
{
	/**
	 * @param SupersetUserReferenceDto|null $user When set, Superset lists tables as this user (act-as).
	 */
	public function list(?string $schema = null, ?SupersetUserReferenceDto $user = null): Result
	{
		return $this->getService()->list($schema, $user?->getLogin());
	}

	public function getMetadata(
		string $tableName,
		?string $schema = null,
		?SupersetUserReferenceDto $user = null,
	): Result
	{
		return $this->getService()->getMetadata($tableName, $schema, $user?->getLogin());
	}

	private function getService(): TableService
	{
		return new TableService($this->server, $this->connector);
	}
}
