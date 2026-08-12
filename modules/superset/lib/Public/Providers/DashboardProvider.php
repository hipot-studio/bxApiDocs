<?php

namespace Bitrix\Superset\Public\Providers;

use Bitrix\Main\Result;
use Bitrix\Superset\Internal\Services\DashboardService;
use Bitrix\Superset\Public\Support\AbstractPublicEntryPoint;

final class DashboardProvider extends AbstractPublicEntryPoint
{
	public function getById(int $dashboardId): Result
	{
		return $this->getService()->get($dashboardId);
	}

	public function list(array $ids = [], array $neqIds = []): Result
	{
		return $this->getService()->list($ids, $neqIds);
	}

	public function getEmbedded(int $dashboardId): Result
	{
		return $this->getService()->getEmbedded($dashboardId);
	}

	public function issueGuestToken(int $dashboardId, array $supersetUserData, array $rlsRules = [], int $expSeconds = 0): Result
	{
		return $this->getService()->getGuestToken($dashboardId, $supersetUserData, $rlsRules, $expSeconds);
	}

	public function getDatasets(int $dashboardId): Result
	{
		return $this->getService()->datasets($dashboardId);
	}

	public function getReusedObjects(array $dashboardIds): Result
	{
		return $this->getService()->getReusedObjects($dashboardIds);
	}

	/**
	 * @param int[] $chartIds
	 * @param array{by?: string, order?: string} $sort
	 */
	public function getOverview(
		int $dashboardId,
		array $appliedFilters = [],
		array $urlParams = [],
		int $streamTimeout = 60 * 5,
		array $chartIds = [],
		array $sort = [],
		?int $limit = null,
		int $offset = 0,
	): Result
	{
		return $this->getService()->getOverview(
			$dashboardId,
			$appliedFilters,
			$urlParams,
			$streamTimeout,
			$chartIds,
			$sort,
			$limit,
			$offset,
		);
	}

	public function getFilterValues(
		int $dashboardId,
		string $filterColumn,
		array $appliedFilters = [],
		array $urlParams = [],
		?string $search = null,
		?int $limit = null,
		int $streamTimeout = 60,
	): Result
	{
		return $this->getService()->getFilterValues(
			$dashboardId,
			$filterColumn,
			$appliedFilters,
			$urlParams,
			$search,
			$limit,
			$streamTimeout,
		);
	}

	private function getService(): DashboardService
	{
		return new DashboardService($this->server, $this->connector);
	}
}
