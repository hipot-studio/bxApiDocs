<?php

namespace Bitrix\Superset\Internal\Api;

use Bitrix\Main;
use Bitrix\Superset\Internal\Connector\SupersetInstance;
use Bitrix\Superset\Internal\RequestResult;

class Dashboard
{
	private const DASHBOARD_API_LINK = '/api/v1/dashboard/';

	private ?SupersetInstance $connector;

	public function __construct(SupersetInstance $connector)
	{
		$this->connector = $connector;
	}

	/**
	 * Gets resources
	 *
	 * @param array|null $filter
	 * @param int|null $page
	 * @param int|null $pageSize
	 * @return RequestResult
	 */
	public function getDashboards(?array $filter, ?int $page = null, ?int $pageSize = null): RequestResult
	{
		$url = self::DASHBOARD_API_LINK;

		$query = [];

		if ($filter)
		{
			$query['filters'] = $filter;
		}

		if ($page || $pageSize)
		{
			if ($page)
			{
				$query['page'] = $page;
			}

			if ($pageSize)
			{
				$query['page_size'] = $pageSize;
			}
		}

		if ($query)
		{
			$query = Main\Web\Json::encode($query);
			$url = self::DASHBOARD_API_LINK . '?q=' . $query;
		}

		return $this->connector->get($url);
	}

	/**
	 * Gets dashboard
	 *
	 * @param int $id
	 * @return RequestResult
	 */
	public function getDashboardById(int $id): RequestResult
	{
		$url = self::DASHBOARD_API_LINK . $id;
		return $this->connector->get($url);
	}

	/**
	 * Gets dashboard by slug
	 *
	 * @param string $slug
	 * @return RequestResult
	 */
	public function getDashboardBySlug(string $slug): RequestResult
	{
		$url = self::DASHBOARD_API_LINK . $slug;
		return $this->connector->get($url);
	}

	/**
	 * Gets embedded dashboard info
	 *
	 * @param int $id
	 * @return RequestResult
	 */
	public function getEmbeddedDashboardInfo(int $id): RequestResult
	{
		$url = self::DASHBOARD_API_LINK . "{$id}/embedded";
		return $this->connector->get($url);
	}

	/**
	 * Imports dashboard to Superset
	 * Overwrite flag supports only 'true' value
	 *
	 * @param string $pathToFile
	 * @return RequestResult
	 */
	public function importDashboard(string $pathToFile, array $additionPayload = []): RequestResult
	{
		$content = fopen($pathToFile, 'rb');
		$url = self::DASHBOARD_API_LINK . 'import/';
		$payload = $additionPayload;
		$payload['formData'] = $content;
		$payload['overwrite'] = 'true';

		return $this->connector->postMultipart($url, $payload);
	}

	/**
	 * Updates dashboard by id
	 *
	 * Fields for update:
	 * 	"certification_details": "string",
	 * 	"certified_by": "string",
	 * 	"css": "string",
	 * 	"dashboard_title": "string",
	 * 	"external_url": "string",
	 * 	"is_managed_externally": true,
	 * 	"json_metadata": "string",
	 * 	"owners": [
	 * 		0
	 * 	],
	 * 	"position_json": "string",
	 * 	"published": true,
	 * 	"roles": [
	 * 		0
	 * 	],
	 * 	"slug": "string"
	 *
	 * @param int $id
	 * @param array $payload
	 * @return RequestResult
	 */
	public function updateDashboard(int $id, array $payload): RequestResult
	{
		$url = self::DASHBOARD_API_LINK . $id;
		return $this->connector->put($url, $payload);
	}

	/**
	 * Sets a dashboard's embedded configuration
	 *
	 * @param int $id
	 * @param array $allowedDomains
	 * @return RequestResult
	 */
	public function embedDashboard(int $id, array $allowedDomains = []): RequestResult
	{
		$url = self::DASHBOARD_API_LINK . "$id/embedded";
		$payload = [
			"allowed_domains" => $allowedDomains,
		];

		return $this->connector->post($url, $payload);
	}

	public function publishDashboard(int $id): RequestResult
	{
		$payload = [
			'published' => true,
		];

		return $this->updateDashboard($id, $payload);
	}

	/**
	 * Copies dashboard by id
	 *
	 * @param int $id
	 * @param array $payload
	 * @return RequestResult
	 */
	public function copyDashboard(int $id, array $payload): RequestResult
	{
		$url = self::DASHBOARD_API_LINK . "{$id}/copy/";
		return $this->connector->post($url, $payload);
	}

	/**
	 * Update dashboard owners
	 *
	 * @param int $id
	 * @param array $ownerIds
	 * @return RequestResult
	 */
	public function setDashboardOwners(int $id, array $ownerIds): RequestResult
	{
		$payload = [
			'owners' => $ownerIds,
		];

		return $this->updateDashboard($id, $payload);
	}

	public function exportDashboard(int $id): RequestResult
	{
		$url = self::DASHBOARD_API_LINK . 'export';
		$urlParams = http_build_query(['q' => "!($id)"]);
		$url = "$url/?$urlParams";

		return $this->connector->get($url);
	}

	/**
	 * Creates new dashboard
	 *
	 * Fields for create:
	 * 	"certification_details": "string",
	 * 	"certified_by": "string",
	 * 	"css": "string",
	 * 	"dashboard_title": "string",
	 * 	"external_url": "string",
	 * 	"is_managed_externally": true,
	 * 	"json_metadata": "string",
	 * 	"owners": [
	 * 		0
	 * 	],
	 * 	"position_json": "string",
	 * 	"published": true,
	 * 	"roles": [
	 * 		0
	 * 	],
	 * 	"slug": "string"
	 *
	 * @param array $payload
	 * @return RequestResult
	 */
	public function createDashboard(array $payload): RequestResult
	{
		return $this->connector->post(self::DASHBOARD_API_LINK, $payload);
	}

	/**
	 * Deletes multiple dashboards by ids
	 *
	 * @param int[] $ids
	 * @return RequestResult
	 */
	public function deleteDashboards(array $ids): RequestResult
	{
		$urlParams = http_build_query(['q' => Main\Web\Json::encode($ids)]);
		$url = self::DASHBOARD_API_LINK . "?{$urlParams}";

		return $this->connector->delete($url);
	}

	public function getDashboardCharts(int $id): RequestResult
	{
		$url = self::DASHBOARD_API_LINK . "{$id}/charts";
		return $this->connector->get($url);
	}

	public function getDashboardDatasets(int $id): RequestResult
	{
		$url = self::DASHBOARD_API_LINK . "{$id}/datasets";
		return $this->connector->get($url);
	}

	public function getDashboardsByOwnerId(int $ownerId): RequestResult
	{
		$query = "(filters:!((col:owners,opr:rel_m_m,value:{$ownerId})))";
		$url = self::DASHBOARD_API_LINK . '?q=' . $query;

		return $this->connector->get($url);
	}

	/**
	 * @param int[] $dashboardIds
	 * @return RequestResult
	 */
	public function getDashboardReusedObjects(array $dashboardIds): RequestResult
	{
		$url = self::DASHBOARD_API_LINK . 'reused_objects/?q=[' . implode(',', $dashboardIds) . ']';

		return $this->connector->get($url);
	}

	/**
	 * @param int[] $chartIds Non-empty switches /overview to drill-down mode.
	 * @param array{by?: string, order?: string} $sort Drill-down sort; ignored in skeleton mode.
	 * @param int|null $limit Drill-down row cap; ignored in skeleton mode.
	 * @param int $offset Drill-down page offset into the sorted rows; ignored in skeleton mode.
	 */
	public function getDashboardOverview(
		int $id,
		array $appliedFilters = [],
		array $urlParams = [],
		array $chartIds = [],
		array $sort = [],
		?int $limit = null,
		int $offset = 0,
	): RequestResult
	{
		$url = self::DASHBOARD_API_LINK . "{$id}/overview";
		if (!empty($urlParams))
		{
			$url .= '?' . http_build_query($urlParams);
		}

		$body = [];
		if (!empty($appliedFilters))
		{
			$body['applied_filters'] = $appliedFilters;
		}
		if (!empty($chartIds))
		{
			$body['chart_ids'] = array_values(array_map('intval', $chartIds));

			if (isset($sort['by']))
			{
				$body['sort_by'] = $sort['by'];
			}
			if (isset($sort['order']))
			{
				$body['sort_order'] = $sort['order'];
			}
			if ($limit !== null)
			{
				$body['row_limit'] = $limit;
			}
			if ($offset > 0)
			{
				$body['offset'] = $offset;
			}
		}

		return $this->connector->post($url, $body);
	}

	public function getFilterValues(
		int $id,
		string $filterColumn,
		array $appliedFilters = [],
		array $urlParams = [],
		?string $search = null,
		?int $limit = null,
	): RequestResult
	{
		$url = self::DASHBOARD_API_LINK . "{$id}/filter_values";
		if (!empty($urlParams))
		{
			$url .= '?' . http_build_query($urlParams);
		}

		$body = ['filter_column' => $filterColumn];
		if (!empty($appliedFilters))
		{
			$body['applied_filters'] = $appliedFilters;
		}
		if ($search !== null && $search !== '')
		{
			$body['search'] = $search;
		}
		if ($limit !== null)
		{
			$body['limit'] = $limit;
		}

		return $this->connector->post($url, $body);
	}
}
