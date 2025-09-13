<?php

namespace Bitrix\BIConnector\Integration\UI\EntitySelector;

use Bitrix\BIConnector\Access\AccessController;
use Bitrix\BIConnector\Access\ActionDictionary;
use Bitrix\BIConnector\Configuration\DashboardTariffConfigurator;
use Bitrix\BIConnector\Integration\Superset\Integrator\Integrator;
use Bitrix\BIConnector\Integration\Superset\Model\Dashboard;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboardTable;
use Bitrix\BIConnector\Integration\Superset\SupersetController;
use Bitrix\BIConnector\Superset\Scope\ScopeService;
use Bitrix\UI\EntitySelector\BaseProvider;
use Bitrix\UI\EntitySelector\Dialog;
use Bitrix\UI\EntitySelector\Item;
use Bitrix\UI\EntitySelector\SearchQuery;

class SupersetDashboardProvider extends BaseProvider
{
	public const ENTITY_ID = 'biconnector-superset-dashboard';
	protected const ELEMENTS_LIMIT = 50;

	public function __construct(array $options = [])
	{
		parent::__construct();

		$this->options['loadProxyData'] = (bool)($options['loadProxyData'] ?? true);
		$this->options['checkAccessRights'] = (bool)($options['checkAccessRights'] ?? true);
	}

	public function isAvailable(): bool
	{
		return $GLOBALS['USER']->isAuthorized();
	}

	public function fillDialog(Dialog $dialog): void
	{
		$recentItems = $dialog->getRecentItems()->getEntityItems(self::ENTITY_ID);
		$recentItemsCount = count($recentItems);

		if ($recentItemsCount < self::ELEMENTS_LIMIT)
		{
			$elements = $this->getElements([], self::ELEMENTS_LIMIT);
			$dialog->addRecentItems($elements);
		}
	}

	public function doSearch(SearchQuery $searchQuery, Dialog $dialog): void
	{
		$searchQuery->setCacheable(false);
		$query = $searchQuery->getQuery();

		$filter = [
			'%TITLE' => $query,
		];
		$items = $this->getElements($filter);

		$dialog->addItems($items);
	}

	public function getItems(array $ids): array
	{
		$filter = !empty($ids) ? ['ID' => $ids] : [];

		return $this->getElements($filter);
	}

	public function getElements(array $filter = [], ?int $limit = null): array
	{
		$result = [];
		$ormParams = [
			'filter' => $filter,
			'limit' => $limit ?? self::ELEMENTS_LIMIT,
		];
		$integrator = Integrator::getInstance();
		$superset = new SupersetController($integrator);

		if ($this->options['checkAccessRights'])
		{
			$accessFilter = AccessController::getCurrent()->getEntityFilter(
				ActionDictionary::ACTION_BIC_DASHBOARD_VIEW,
				SupersetDashboardTable::class
			);
			$ormParams['filter'] = [
				$accessFilter,
				$ormParams['filter'],
			];
		}

		$elements = $superset->getDashboardRepository()->getList($ormParams, $this->options['loadProxyData']);
		foreach ($elements as $element)
		{
			if (DashboardTariffConfigurator::isAvailableDashboard($element->getAppId()))
			{
				if ($this->options['loadProxyData'])
				{
					if ($element->isSupersetDashboardDataLoaded())
					{
						$result[] = $this->makeItem($element);
					}
				}
				else
				{
					$result[] = $this->makeItem($element);
				}
			}
		}

		return $result;
	}

	private function makeItem(Dashboard $dashboard): Item
	{
		$scopes = [];
		$scopeCodes = ScopeService::getInstance()->getDashboardScopes($dashboard->getId());
		foreach ($scopeCodes as $scopeCode)
		{
			$scopes[] = [
				'code' => $scopeCode,
				'name' => ScopeService::getInstance()->getScopeName($scopeCode),
			];
		}
		$itemParams = [
			'id' => $dashboard->getId(),
			'entityId' => self::ENTITY_ID,
			'title' => $dashboard->getTitle(),
			'description' => null,
			'avatar' => $this->getDashboardIcon($dashboard),
			'avatarOptions' => [
				'borderRadius' => '4px',
			],
			'customData' => [
				'scopes' => $scopes,
				'type' => $dashboard->getType(),
			],
		];

		return new Item($itemParams);
	}

	private function getDashboardIcon(Dashboard $dashboard): string
	{
		return match ($dashboard->getType())
		{
			SupersetDashboardTable::DASHBOARD_TYPE_SYSTEM => '/bitrix/images/biconnector/superset-dashboard-selector/icon-type-system.png',
			SupersetDashboardTable::DASHBOARD_TYPE_MARKET => '/bitrix/images/biconnector/superset-dashboard-selector/icon-type-market.png',
			SupersetDashboardTable::DASHBOARD_TYPE_CUSTOM => '/bitrix/images/biconnector/superset-dashboard-selector/icon-type-custom.png',
			default => '/bitrix/images/biconnector/superset-dashboard-selector/icon-type-system.png',
		};
	}
}
