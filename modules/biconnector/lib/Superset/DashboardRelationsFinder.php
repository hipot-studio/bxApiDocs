<?php

namespace Bitrix\BIConnector\Superset;

use Bitrix\BIConnector;
use Bitrix\BIConnector\Integration\Superset\Model;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboardCollection;
use Bitrix\BIConnector\Integration\Superset\Integrator\Integrator;
use Bitrix\BIConnector\Integration\Superset\Model\Dashboard;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboardTable;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

final class DashboardRelationsFinder
{
	private const TYPE_DASHBOARD = 'dashboard';
	private const TYPE_CHART = 'chart';
	private const TYPE_DATASET = 'dataset';

	private Integrator $integrator;

	/**
	 * @var Array<int, Dashboard> map (externalId -> DashboardModel) of market dashboard ids that figured in finder request
	 */
	private array $usedMarketDashboardExternalIdToModel = [];
	private string $supersetDomain = '';

	public function __construct(Integrator $integrator)
	{
		$this->integrator = $integrator;
	}

	private function buildLinkToEntity(string $url): string
	{
		return rtrim($this->supersetDomain, '/') . $url;
	}

	private function fillMarketDashboardExternalIdToModel($dashboardsRelationsMap): void
	{
		$externalIds = [];
		foreach ($dashboardsRelationsMap as $dashboardRelations)
		{
			if (!empty($dashboardRelations['charts']))
			{
				foreach ($dashboardRelations['charts'] as $chart)
				{
					if (!empty($chart['dashboards']))
					{
						foreach ($chart['dashboards'] as $dashboard)
						{
							$externalIds[] = $dashboard['id'];
						}
					}
				}
			}

			if (!empty($dashboardRelations['datasets']))
			{
				foreach ($dashboardRelations['datasets'] as $dataset)
				{
					foreach ($dataset['charts'] as $chart)
					{
						if (!empty($chart['dashboards']))
						{
							foreach ($chart['dashboards'] as $dashboard)
							{
								$externalIds[] = $dashboard['id'];
							}
						}
					}
				}
			}
		}

		$externalIds = array_unique($externalIds);
		$existingExternalIds = array_keys($this->usedMarketDashboardExternalIdToModel);
		$notLoadedExternalIds = array_diff($externalIds, $existingExternalIds);
		$dashboards = SupersetDashboardTable::getList([
			'select' => ['*', 'APP'],
			'filter' => [
				'=EXTERNAL_ID' => $notLoadedExternalIds,
				'=TYPE' => [SupersetDashboardTable::DASHBOARD_TYPE_MARKET, SupersetDashboardTable::DASHBOARD_TYPE_SYSTEM],
			],
		])
			->fetchCollection()
		;

		foreach ($dashboards as $dashboard)
		{
			$this->usedMarketDashboardExternalIdToModel[$dashboard->getExternalId()] = new Dashboard($dashboard);
		}
	}

	/**
	 * @param array $dashboardExternalIds
	 * @return Result dashboards map in data
	 */
	private function loadDashboardsReusedObjects(array $dashboardExternalIds): Result
	{
		$result = new Result();

		if (!BIConnector\Integration\Superset\SupersetInitializer::isSupersetReady())
		{
			$result->addError(new Error('Cannot get related objects while superset is not READY'));

			return $result;
		}

		$response = $this->integrator->getDashboardReusedObjects($dashboardExternalIds);
		if ($response->hasErrors())
		{
			$result->addErrors($response->getErrors());

			return $result;
		}

		$data = $response->getData();

		if (!isset($data['domain']))
		{
			$result->addError(new Error('Domain not found in related dashboards response'));

			return $result;
		}

		$this->supersetDomain = $data['domain'];

		if (isset($data['dashboards']) && is_array($data['dashboards']))
		{
			$this->fillMarketDashboardExternalIdToModel($data['dashboards']);
			$result->setData($data['dashboards']);
		}
		else
		{
			$result->setData([]);
		}

		return $result;
	}

	/**
	 * @param int[] $ids
	 * @return void
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\ObjectPropertyException
	 * @throws \Bitrix\Main\SystemException
	 */
	private function getMarketDashboardCollectionFromIds(array $ids): SupersetDashboardCollection
	{
		return SupersetDashboardTable::getList([
			'select' => ['ID', 'EXTERNAL_ID', 'TYPE'],
			'filter' => [
				'=ID' => $ids,
				'=TYPE' => SupersetDashboardTable::DASHBOARD_TYPE_MARKET,
			],
		])
			->fetchCollection()
		;
	}

	public function findRelatedMarketDashboards(Dashboard $dashboard): Result
	{
		$result = new Result();

		// Check if dashboard is custom
		if (!$dashboard->isCustomDashboard())
		{
			$result->setData([]);

			return $result;
		}

		$dashboardLoadResult = $this->loadDashboardsReusedObjects([$dashboard->getExternalId()]);
		if (!$dashboardLoadResult->isSuccess())
		{
			$result->addErrors($dashboardLoadResult->getErrors());

			return $result;
		}

		$dashboardsRelations = $dashboardLoadResult->getData();
		if (empty($dashboardsRelations))
		{
			$result->setData([]);
		}

		$dashboardRelations = current($dashboardsRelations);

		/** @var Dashboard[] $resultData */
		$foundDashboards = [
			...$this->findMarketRelationsInCharts($dashboardRelations['charts'] ?? []),
			...$this->findMarketRelationsInDatasets($dashboardRelations['datasets'] ?? []),
		];


		$resultData = [];
		/** @var Dashboard $foundDashboard */
		foreach ($foundDashboards as $foundDashboard)
		{
			$resultData[$foundDashboard->getId()] = $foundDashboard;
		}

		$result->setData(array_values($resultData));

		return $result;
	}

	/**
	 * @param Dashboard[] $dashboardRelatedCharts
	 * @param bool $breakSearchOnSystemDashboard using for dataset charts searching, if some chart using system dashboard - it's system dataset, no need to search for market dashboards
	 * @return array
	 */
	private function findMarketRelationsInCharts(array $charts, bool $breakSearchOnSystemDashboard = false): array
	{
		$result = [];
		foreach ($charts as $relatedChart)
		{
			$foundDashboards = [];
			foreach ($relatedChart['dashboards'] as $dashboard)
			{
				if (isset($this->usedMarketDashboardExternalIdToModel[$dashboard['id']]))
				{
					$marketDashboard = $this->usedMarketDashboardExternalIdToModel[$dashboard['id']];
					if ($marketDashboard->isSystemDashboard())
					{
						if ($breakSearchOnSystemDashboard)
						{
							return [];
						}

						continue 2;
					}

					$foundDashboards[] = $marketDashboard;
				}
			}

			$result = [
				...$result,
				...$foundDashboards,
			];
		}

		return $result;
	}

	private function findMarketRelationsInDatasets(array $dashboardRelatedDatasets): array
	{
		$result = [];
		foreach ($dashboardRelatedDatasets as $dataset)
		{
			if (!$dataset['is_virtual'])
			{
				continue;
			}

			$result = [
				...$this->findMarketRelationsInCharts($dataset['charts'], true),
				...$result,
			];
		}

		return $result;
	}

	public function getMarketDashboardReusedEntities(array $dashboardIds): Result
	{
		$result = new Result();

		$dashboardCollection = $this->getMarketDashboardCollectionFromIds($dashboardIds);

		$innerDashboardsByExternalIdMap = [];
		foreach ($dashboardCollection as $item)
		{
			$innerDashboardsByExternalIdMap[$item->getExternalId()] = $item;
		}

		$externalIds = $dashboardCollection->getExternalIdList();
		if (empty($externalIds))
		{
			$result->setData([]);

			return $result;
		}

		$dashboardLoadResult = $this->loadDashboardsReusedObjects($externalIds);
		if (!$dashboardLoadResult->isSuccess())
		{
			$result->addErrors($dashboardLoadResult->getErrors());

			return $result;
		}

		$dashboardsRelationsList = $dashboardLoadResult->getData();

		$resultData  = [];
		foreach ($innerDashboardsByExternalIdMap as $externalId => $dashboardModel)
		{
			if (isset($dashboardsRelationsList[$externalId]))
			{
				$dashboardRelations = $this->fetchReusedEntitiesFromDashboardRelations($dashboardsRelationsList[$externalId]);

				if (!empty($dashboardRelations))
				{
					$resultData[$dashboardModel->getId()] = $dashboardRelations;
				}
			}
		}

		$result->setData($resultData);

		return $result;
	}

	/**
	 * @param array{charts?: array, datasets?: array} $dashboardRelationsMap
	 * @return array
	 */
	private function fetchReusedEntitiesFromDashboardRelations(array $dashboardRelationsMap): array
	{
		return [
			...$this->fetchReusedEntitiesFromCharts($dashboardRelationsMap['charts'] ?? []),
			...$this->fetchReusedEntitiesFromDatasets($dashboardRelationsMap['datasets'] ?? []),
		];
	}

	/**
	 * Find reused charts and returns dashboards that use it, except market dashboards
	 * @param array $dashboardChartsUsage
	 * @return array
	 */
	private function fetchReusedEntitiesFromCharts(array $dashboardChartsUsage): array
	{
		$consumeDashboards = [];
		foreach ($dashboardChartsUsage as $chart)
		{
			foreach ($chart['dashboards'] as $chartDashboard)
			{
				if (isset($this->usedMarketDashboardExternalIdToModel[$chartDashboard['id']]))
				{
					continue 2;
				}
			}

			$chartData = [
				'title' => $chart['name'],
				'link' => $this->buildLinkToEntity($chart['url']),
				'type' => self::TYPE_CHART,
			];

			foreach ($chart['dashboards'] as $chartDashboard)
			{
				if (isset($this->usedMarketDashboardExternalIdToModel[$chartDashboard['id']]))
				{
					break;
				}

				if (!isset($consumeDashboards[$chartDashboard['id']]))
				{
					$consumeDashboards[$chartDashboard['id']] = [
						'title' => $chartDashboard['title'],
						'link' => $this->buildLinkToEntity($chartDashboard['url']),
						'type' => self::TYPE_DASHBOARD,
						'entities' => [],
					];
				}

				$consumeDashboards[$chartDashboard['id']]['entities'][] = $chartData;
			}
		}

		return array_values($consumeDashboards);
	}

	/**
	 * Find reused datasets and returns charts that use it, except market dashboards
	 * @param array $dashboardChartsUsage
	 * @return array
	 */
	private function fetchReusedEntitiesFromDatasets(array $dashboardDatasetsUsage): array
	{
		$consumedCharts = [];
		foreach ($dashboardDatasetsUsage as $dataset)
		{
			$datasetData = [
				'title' => $dataset['name'],
				'link' => $this->buildLinkToEntity($dataset['url']),
				'type' => self::TYPE_DATASET,
			];

			if (!$dataset['is_virtual'])
			{
				continue;
			}

			$datasetConsumedCharts = [];
			foreach ($dataset['charts'] as $chart)
			{
				foreach ($chart['dashboards'] as $dashboard)
				{
					// If chart has system/market dashboard, it is a chart created by the system that should not be taken there
					if (isset($this->usedMarketDashboardExternalIdToModel[$dashboard['id']]))
					{
						continue 3;
					}
				}

				// no need to check existing in array, chart have 1-1 relation with dataset
				$datasetConsumedCharts[] = [
					'title' => $chart['name'],
					'link' =>  $this->buildLinkToEntity($chart['url']),
					'type' => self::TYPE_CHART,
					'entities' => [$datasetData],
				];
			}

			$consumedCharts = [
				...$consumedCharts,
				...$datasetConsumedCharts,
			];
		}

		return $consumedCharts;
	}
}