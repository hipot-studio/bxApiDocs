<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

use Bitrix\BIConnector\Internal\Entity\UsageStatEntry;
use Bitrix\BIConnector\Internal\Entity\UsageStatEntryCollection;
use Bitrix\BIConnector\Internal\Grid\UsageStat\Settings\UsageStatSettings;
use Bitrix\BIConnector\Internal\Grid\UsageStat\UsageStatGrid;
use Bitrix\BIConnector\KeyTable;
use Bitrix\BIConnector\LimitManager;
use Bitrix\BIConnector\Manager;
use Bitrix\BIConnector\Public\Provider\UsageStat\Params\UsageStatFilter;
use Bitrix\BIConnector\Public\Provider\UsageStat\Params\UsageStatSelect;
use Bitrix\BIConnector\Public\Provider\UsageStat\Params\UsageStatSort;
use Bitrix\BIConnector\Public\Provider\UsageStat\UsageStatProvider;
use Bitrix\BIConnector\Services\ApacheSuperset;
use Bitrix\Main\Application;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Provider\Params\GridParams;
use Bitrix\Main\Provider\Params\Pager;
use Bitrix\UI\Buttons;
use Bitrix\UI\Toolbar\Facade\Toolbar;

class BIConnectorUsageStatComponent extends CBitrixComponent
{
	private const GRID_ID_COMMON = 'biconnector_usage_stat';
	private const GRID_ID_SUPERSET = 'biconnector_usage_stat_superset';

	private UsageStatGrid $grid;
	private bool $isBiBuilderService = false;
	private int $totalRowsCount = 0;
	private bool $hasUserFilter = false;
	private UsageStatProvider $provider;

	private bool $viewOverLimit = false;

	public function onPrepareComponentParams($arParams)
	{
		$arParams['BI_ANALYTIC'] ??= 'N';
		$arParams['KEY_EDIT_URL'] ??= '';

		return parent::onPrepareComponentParams($arParams);
	}

	public function executeComponent(): void
	{
		if (!Loader::includeModule('biconnector'))
		{
			$this->arResult['ERROR_MESSAGES'][] = Loc::getMessage('CC_BBUS_ERROR_INCLUDE_MODULE');
			$this->includeComponentTemplate();

			return;
		}

		$this->isBiBuilderService = ($this->arParams['BI_ANALYTIC'] ?? '') === 'Y';

		$request = Application::getInstance()->getContext()->getRequest();
		$this->viewOverLimit = $request->get('over_limit') === 'Y';

		$canView = CurrentUser::get()->canDoOperation('biconnector_key_manage') || $this->isBiBuilderService;
		$this->arResult['CAN_VIEW'] = $canView;
		if (!$canView)
		{
			$this->arResult['ERROR_MESSAGES'][] = Loc::getMessage('CC_BBUS_ERROR_ACCESS');
			$this->includeComponentTemplate();

			return;
		}

		$this->init();
		$this->grid->processRequest();
		$this->loadRows();

		$this->arResult['GRID'] = $this->grid;
		$this->arResult['GRID_STUB'] = $this->totalRowsCount === 0 && !$this->hasUserFilter ? $this->getStub() : null;
		$this->arResult['IS_BI_BUILDER_SERVICE'] = $this->isBiBuilderService;
		$this->arResult['BICONNECTOR_LIMIT'] = $this->getLimit();

		$this->includeComponentTemplate();
	}

	private function init(): void
	{
		$this->provider = new UsageStatProvider();
		$this->initGrid();
		$this->initGridFilterBar();
		$this->initToolbarButtons();
	}

	private function initGrid(): void
	{
		$settings = new UsageStatSettings([
			'ID' => $this->isBiBuilderService ? self::GRID_ID_SUPERSET : self::GRID_ID_COMMON,
			'SHOW_ROW_CHECKBOXES' => false,
			'SHOW_SELECTED_COUNTER' => false,
			'SHOW_TOTAL_COUNTER' => false,
			'EDITABLE' => false,
		]);
		$settings
			->setIsBiBuilderService($this->isBiBuilderService)
			->setKeyEditUrl((string)($this->arParams['KEY_EDIT_URL'] ?? ''))
		;

		$this->grid = new UsageStatGrid($settings);

		$this->totalRowsCount = $this->provider->getCount($this->getGridFilter());
		$this->grid->initPagination($this->totalRowsCount);
	}

	private function loadRows(): void
	{
		$ormParams = $this->grid->getOrmParams();

		$gridParams = new GridParams(
			pager: new Pager(
				limit: (int)($ormParams['limit'] ?? 50),
				offset: (int)($ormParams['offset'] ?? 0),
			),
			filter: $this->getGridFilter(),
			sort: new UsageStatSort($ormParams['order']),
			select: new UsageStatSelect($ormParams['select']),
		);

		$entries = $this->provider->getList($gridParams);
		$rows = $this->convertEntriesToRows($entries);

		if (!$this->isBiBuilderService)
		{
			$accessKeys = $this->fetchAccessKeys($entries);
			foreach ($rows as &$row)
			{
				$row['ACCESS_KEY'] = $accessKeys[(int)$row['KEY_ID']] ?? null;
			}
			unset($row);
		}

		$this->grid->setRawRows($rows);
	}

	private function getGridFilter(): UsageStatFilter
	{
		$baseFilter = $this->buildBaseFilter();
		$ormFilter = (array)$this->grid->getOrmFilter();

		return new UsageStatFilter($baseFilter + $ormFilter);
	}

	private function convertEntriesToRows(UsageStatEntryCollection $entries): array
	{
		$rows = [];
		foreach ($entries as $entry)
		{
			/** @var UsageStatEntry $entry */
			$rows[] = [
				'ID' => $entry->getId(),
				'TIMESTAMP_X' => $entry->getTimestamp(),
				'KEY_ID' => $entry->getKeyId(),
				'SERVICE_ID' => $entry->getServiceId(),
				'SOURCE_ID' => $entry->getSourceId(),
				'FIELDS' => $entry->getFields(),
				'FILTERS' => $entry->getFilters(),
				'INPUT' => $entry->getInput(),
				'REQUEST_METHOD' => $entry->getRequestMethod(),
				'REQUEST_URI' => $entry->getRequestUri(),
				'ROW_NUM' => $entry->getRowNum(),
				'DATA_SIZE' => $entry->getDataSize(),
				'REAL_TIME' => $entry->getRealTime(),
				'IS_OVER_LIMIT' => $entry->isOverLimit() ? 'Y' : 'N',
				'SOURCE' => $entry->getSource(),
				'EXTERNAL_DASHBOARD_ID' => $entry->getExternalDashboardId(),
				'EXTERNAL_DASHBOARD_NAME' => $entry->getExternalDashboardName(),
				'EXTERNAL_CHART_ID' => $entry->getExternalChartId(),
				'EXTERNAL_CHART_NAME' => $entry->getExternalChartName(),
				'EXTERNAL_DATASET_ID' => $entry->getExternalDatasetId(),
				'EXTERNAL_DATASET_NAME' => $entry->getExternalDatasetName(),
			];
		}

		return $rows;
	}

	/**
	 * @return array<int, string> map keyId => accessKey
	 */
	private function fetchAccessKeys(UsageStatEntryCollection $entries): array
	{
		$keyIds = [];
		foreach ($entries as $entry)
		{
			/** @var UsageStatEntry $entry */
			$keyId = $entry->getKeyId();
			if ($keyId !== null)
			{
				$keyIds[$keyId] = true;
			}
		}

		if ($keyIds === [])
		{
			return [];
		}

		$result = KeyTable::getList([
			'select' => ['ID', 'ACCESS_KEY'],
			'filter' => ['=ID' => array_keys($keyIds)],
		]);

		$accessKeys = [];
		while ($row = $result->fetch())
		{
			$accessKeys[(int)$row['ID']] = $row['ACCESS_KEY'];
		}

		return $accessKeys;
	}

	private function initGridFilterBar(): void
	{
		$this->hasUserFilter = !empty($this->grid->getOrmFilter());

		$filter = $this->grid->getFilter();
		if ($filter)
		{
			$options = \Bitrix\Main\Filter\Component\ComponentParams::get(
				$filter,
				[
					'GRID_ID' => $this->grid->getId(),
					'DISABLE_SEARCH' => true,
				]
			);
		}
		else
		{
			$options = [
				'FILTER_ID' => $this->grid->getId(),
			];
		}

		Toolbar::addFilter($options);
	}

	private function initToolbarButtons(): void
	{
		$request = Application::getInstance()->getContext()->getRequest();
		$frame = $request->get('IFRAME') === 'Y' ? '&IFRAME=Y' : '';

		if ($this->viewOverLimit || $this->getLimit() > 0)
		{
			Toolbar::addButton([
				'link' => $this->viewOverLimit ? '?over_limit=N' . $frame : '?over_limit=Y' . $frame,
				'text' => $this->viewOverLimit
					? Loc::getMessage('CT_BBSU_SHOW_ALL')
					: Loc::getMessage('CT_BBSU_SHOW_OVERLIMIT')
				,
				'color' => Buttons\Color::LIGHT_BORDER,
			]);
		}

		$refreshButton = new Buttons\Button([
			'icon' => Buttons\Icon::RELOAD,
			'color' => Buttons\Color::PRIMARY,
			'click' => 'reloadUsageStats',
		]);
		$refreshButton->addAttribute('title', Loc::getMessage('CT_BBSU_REFRESH'));

		Toolbar::addButton($refreshButton);
	}

	private function buildBaseFilter(): array
	{
		$filter = [];
		if ($this->viewOverLimit)
		{
			$filter['=IS_OVER_LIMIT'] = 'Y';
		}

		if ($this->isBiBuilderService)
		{
			$filter['=SERVICE_ID'] = 'superset';
		}
		else
		{
			$filter['!=SERVICE_ID'] = 'superset';
		}

		return $filter;
	}

	private function getLimitManager(): LimitManager
	{
		$limitManager = LimitManager::getInstance();
		if ($this->isBiBuilderService)
		{
			$limitManager->setService(Manager::getInstance()->createService(ApacheSuperset::getServiceId()));
		}

		return $limitManager;
	}

	private function getLimit(): int
	{
		return $this->getLimitManager()->getLimit();
	}

	private function getStub(): string
	{
		if ($this->viewOverLimit)
		{
			$title = (string)Loc::getMessage('CC_BBUS_EMPTYSTATE_OVER_LIMIT_TITLE');
			$description = (string)(Loader::includeModule('bitrix24')
				? Loc::getMessage('CC_BBUS_EMPTYSTATE_OVER_LIMIT_DESCRIPTION_B24')
				: Loc::getMessage('CC_BBUS_EMPTYSTATE_OVER_LIMIT_DESCRIPTION'));
		}
		else
		{
			if ($this->isBiBuilderService)
			{
				$title = (string)Loc::getMessage('CC_BBUS_EMPTYSTATE_TITLE_SUPERSET');
			}
			else
			{
				$title = (string)Loc::getMessage('CC_BBUS_EMPTYSTATE_TITLE');
			}
			$description = (string)Loc::getMessage('CC_BBUS_EMPTYSTATE_DESCRIPTION');
		}

		return <<<HTML
			<div class="biconnector-empty">
				<div class="biconnector-empty__icon --statistic"></div>
				<div class="main-grid-empty-block-title">{$title}</div>
				<div class="main-grid-empty-block-description">{$description}</div>
			</div>
		HTML;
	}
}
