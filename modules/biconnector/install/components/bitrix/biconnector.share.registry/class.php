<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

use Bitrix\BIConnector\Access\AccessController;
use Bitrix\BIConnector\Access\ActionDictionary;
use Bitrix\BIConnector\Access\Model\DashboardAccessItem;
use Bitrix\BIConnector\Configuration\Feature;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboardTable;
use Bitrix\BIConnector\Internal\Grid\ShareRegistry\ShareRegistryGrid;
use Bitrix\BIConnector\Public\Provider\ShareRegistry\ShareRegistryFilter;
use Bitrix\BIConnector\Public\Provider\ShareRegistry\ShareRegistryProvider;
use Bitrix\Biconnector\Internal\Grid\GridStubRenderer;
use Bitrix\Intranet\Settings\Tools\ToolsManager;
use Bitrix\Intranet\Util as IntranetUtil;
use Bitrix\Main\Grid\Settings;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Provider\Params\Pager;
use Bitrix\UI\Toolbar\Facade\Toolbar;

class BIConnectorShareRegistryComponent extends CBitrixComponent
{
	private const STUB_IMAGE_PATH = '/bitrix/images/biconnector/workspace-analytic-grid/empty-state-icon.svg';

	private ShareRegistryGrid $grid;

	public function executeComponent(): void
	{
		if (!Loader::includeModule('biconnector'))
		{
			$this->arResult['ERROR_MESSAGES'][] = Loc::getMessage('BICONNECTOR_SHARE_REGISTRY_ERROR_INCLUDE_MODULE');
			$this->includeComponentTemplate();

			return;
		}

		$this->prepareResult();
		if (empty($this->arResult['ERROR_MESSAGES']))
		{
			$this->initFilterBar();
		}

		$this->includeComponentTemplate();
	}

	protected function prepareResult(): void
	{
		if (!$this->canControlShares())
		{
			$this->arResult['ERROR_MESSAGES'][] = Loc::getMessage('BICONNECTOR_SHARE_REGISTRY_ERROR_ACCESS');

			return;
		}

		$this->grid = new ShareRegistryGrid(new Settings([
			'ID' => ShareRegistryGrid::GRID_ID,
			'SHOW_ROW_CHECKBOXES' => true,
			'SHOW_CHECK_ALL_CHECKBOXES' => true,
			'SHOW_SELECTED_COUNTER' => true,
			'SHOW_TOTAL_COUNTER' => true,
			'SHOW_ROW_ACTIONS_MENU' => true,
			'SHOW_ACTION_PANEL' => true,
			'SHOW_PAGESIZE' => false,
			'EDITABLE' => false,
		]));

		$provider = $this->createProvider();
		$filter = ShareRegistryFilter::fromFilterValue($this->grid->getOrmFilter() ?? []);
		$totalCount = $provider->getCount($filter);
		// The page switch is an action of the pagination: it has to exist before the request is processed.
		$this->grid->initPagination($totalCount);
		$this->grid->processRequest();

		$pagination = $this->grid->getPagination();
		$items = $totalCount > 0
			? $provider->getList(
				$filter,
				new Pager(
					limit: $pagination?->getLimit() ?? ShareRegistryGrid::PAGE_SIZE,
					offset: $pagination?->getOffset() ?? 0,
				),
			)
			: []
		;
		$this->grid->setRawRows($this->convertItemsToRows($items));

		$this->arResult['GRID'] = $this->grid;
		$this->arResult['GRID_STUB'] = $totalCount === 0 && !$this->grid->hasUserFilter() ? $this->getStub() : null;
	}

	/**
	 * The same checks as the actions of the registry run, so a page the actions would refuse is not shown.
	 */
	protected function canControlShares(): bool
	{
		return $this->isBiConstructorAvailable()
			&& AccessController::getCurrent()->check(ActionDictionary::ACTION_BIC_SHARE_CONTROL)
		;
	}

	/**
	 * Mirrors the BIConstructorAccess and IntranetUser filters of the ShareControl controller.
	 */
	protected function isBiConstructorAvailable(): bool
	{
		if (!Feature::isBuilderEnabled())
		{
			return false;
		}

		if (Loader::includeModule('intranet'))
		{
			if (!ToolsManager::getInstance()->checkAvailabilityByToolId('crm_bi') || !IntranetUtil::isIntranetUser())
			{
				return false;
			}
		}

		return AccessController::getCurrent()->check(ActionDictionary::ACTION_BIC_ACCESS);
	}

	protected function createProvider(): ShareRegistryProvider
	{
		return new ShareRegistryProvider();
	}

	/**
	 * @param array[] $items Rows of {@see ShareRegistryProvider::getList()}.
	 */
	private function convertItemsToRows(array $items): array
	{
		$dashboards = $this->getViewableDashboardUrls(array_column($items, 'dashboardId'));

		$rows = [];
		foreach ($items as $item)
		{
			$detailUrl = $dashboards[$item['dashboardId']] ?? null;
			$rows[] = [
				'ID' => $item['id'],
				'DASHBOARD_ID' => $item['dashboardId'],
				'DASHBOARD_TITLE' => $item['dashboardTitle'],
				'DASHBOARD_TYPE' => $item['dashboardType'],
				'AUTHOR_ID' => $item['authorId'],
				'STATUS' => $item['status'],
				'DATE_EXPIRE' => $item['dateExpire'],
				'DATE_CREATE' => $item['dateCreate'],
				'DATE_LAST_OPENED' => $item['dateLastOpened'],
				'DISABLED_BY_ID' => $item['disabledById'],
				'DATE_DISABLED' => $item['dateDisabled'],
				'EXTERNAL_FILTER_VALUES' => $item['externalFilterValues'],
				'URL_PARAMETER_VALUES' => $item['urlParameterValues'],
				'IS_DASHBOARD_BANNED' => $item['isDashboardBanned'],
				'CAN_VIEW_DASHBOARD' => $detailUrl !== null,
				'DETAIL_URL' => $detailUrl ?? '',
			];
		}

		return $rows;
	}

	/**
	 * The controller right does not include viewing reports, so the link to a report is given only
	 * where the current user may open it.
	 *
	 * @param int[] $dashboardIds
	 * @return array<int, string> dashboard id => detail url
	 */
	private function getViewableDashboardUrls(array $dashboardIds): array
	{
		$dashboardIds = array_values(array_unique(array_filter(array_map('intval', $dashboardIds))));
		if (empty($dashboardIds))
		{
			return [];
		}

		$dashboards = SupersetDashboardTable::getList([
			'select' => ['ID', 'TYPE', 'STATUS'],
			'filter' => ['@ID' => $dashboardIds],
		])->fetchCollection();

		$accessController = AccessController::getCurrent();
		$urls = [];
		foreach ($dashboards as $dashboard)
		{
			$accessItem = DashboardAccessItem::createFromArray([
				'ID' => $dashboard->getId(),
				'TYPE' => $dashboard->getType(),
				'STATUS' => $dashboard->getStatus(),
			]);

			if ($accessController->check(ActionDictionary::ACTION_BIC_DASHBOARD_VIEW, $accessItem))
			{
				$urls[$dashboard->getId()] = $dashboard->getDetailUrl()->getUri();
			}
		}

		return $urls;
	}

	private function initFilterBar(): void
	{
		$filter = $this->grid->getFilter();
		$options = $filter
			? \Bitrix\Main\Filter\Component\ComponentParams::get(
				$filter,
				[
					'GRID_ID' => $this->grid->getId(),
					'ENABLE_LIVE_SEARCH' => true,
				],
			)
			: ['FILTER_ID' => $this->grid->getId()]
		;

		Toolbar::addFilter($options);
	}

	private function getStub(): string
	{
		return GridStubRenderer::render(
			self::STUB_IMAGE_PATH,
			(string)Loc::getMessage('BICONNECTOR_SHARE_REGISTRY_STUB_TITLE'),
			(string)Loc::getMessage('BICONNECTOR_SHARE_REGISTRY_STUB_DESCRIPTION'),
		);
	}
}
