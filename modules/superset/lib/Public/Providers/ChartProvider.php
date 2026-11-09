<?php

namespace Bitrix\Superset\Public\Providers;

use Bitrix\Main\Result;
use Bitrix\Superset\Internal\Services\ChartService;
use Bitrix\Superset\Public\Dto\SupersetUserReferenceDto;
use Bitrix\Superset\Public\Support\AbstractPublicEntryPoint;

final class ChartProvider extends AbstractPublicEntryPoint
{
	/**
	 * @param SupersetUserReferenceDto|null $user When set, Superset lists charts as this user (act-as).
	 */
	public function list(
		array $ids = [],
		?string $nameFilter = null,
		?int $page = null,
		?int $pageSize = null,
		?SupersetUserReferenceDto $user = null,
	): Result
	{
		return $this->getService()->list($ids, $nameFilter, $page, $pageSize, $user?->getLogin());
	}

	/**
	 * @param SupersetUserReferenceDto|null $user When set, Superset reads the chart as this user (act-as).
	 */
	public function getById(int $id, ?SupersetUserReferenceDto $user = null): Result
	{
		return $this->getService()->get($id, $user?->getLogin());
	}

	private function getService(): ChartService
	{
		return new ChartService($this->server, $this->connector);
	}
}
