<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

use Bitrix\BIConnector\ExternalStatLoggerManager;
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
use Bitrix\BIConnector\Public\Services\AhaMoment\AhaMomentSpotlightOptions;
use Bitrix\BIConnector\Public\Services\AhaMoment\AhaMomentSpotlightResolver;
use Bitrix\BIConnector\Services\ApacheSuperset;
use Bitrix\BIConnector\Superset\Selfhost\License\SelfHostedLicenseView;
use Bitrix\Biconnector\Internal\Grid\GridStubRenderer;
use Bitrix\Main\Application;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Provider\Params\GridParams;
use Bitrix\Main\Provider\Params\Pager;
use Bitrix\UI\Buttons;
use Bitrix\UI\Toolbar\ButtonLocation;
use Bitrix\UI\Toolbar\Facade\Toolbar;

class BIConnectorUsageStatComponent extends CBitrixComponent
{
	private const GRID_ID_COMMON = 'biconnector_usage_stat';
	private const GRID_ID_SUPERSET = 'biconnector_usage_stat_superset';

	private const EXT_STAT_ONBOARDING_SPOTLIGHT_ID = 'biconnector-usage-stat-ext-stat-onboarding';

	private const EXT_STAT_BUTTON_ID = 'biconnector-usage-stat-collect-button';
	private const TOGGLE_EXT_STAT_JS = 'BX.BIConnector.UsageStatGridManager.Instance.toggleExternalStatCollection()';

	private const STUB_IMAGE_PATH = '/bitrix/images/biconnector/workspace-analytic-grid/statistics-empty-state.webp';

	private UsageStatGrid $grid;
	private bool $isBiBuilderService = false;
	private int $totalRowsCount = 0;
	private bool $hasUserFilter = false;
	private UsageStatProvider $provider;

	private bool $viewOverLimit = false;

	private bool $isExternalStatEnabled = false;
	private int $externalStatHoursLeft = 0;
	private bool $canToggleExternalStat = false;
	private array $extStatOnboarding = ['canShow' => false];

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

		$canManageKeys = CurrentUser::get()->canDoOperation('biconnector_key_manage');
		$canView = $canManageKeys || $this->isBiBuilderService;
		$this->arResult['CAN_VIEW'] = $canView;
		if (!$canView)
		{
			$this->arResult['ERROR_MESSAGES'][] = Loc::getMessage('CC_BBUS_ERROR_ACCESS');
			$this->includeComponentTemplate();

			return;
		}

		// the toolbar is built inside init(), so the collection state has to be known before it
		$this->initExternalStatState($canManageKeys);

		$this->init();
		$this->grid->processRequest();
		$this->loadRows();

		$this->arResult['GRID'] = $this->grid;
		$this->arResult['GRID_STUB'] = $this->totalRowsCount === 0 && !$this->hasUserFilter ? $this->getStub() : null;
		$this->arResult['IS_BI_BUILDER_SERVICE'] = $this->isBiBuilderService;
		$this->arResult['BICONNECTOR_LIMIT'] = $this->getLimit();
		$this->arResult['IS_EXTERNAL_STAT_ENABLED'] = $this->isExternalStatEnabled;
		$this->arResult['EXTERNAL_STAT_HOURS_LEFT'] = $this->externalStatHoursLeft;
		$this->arResult['CAN_TOGGLE_EXTERNAL_STAT'] = $this->canToggleExternalStat;
		$this->arResult['EXT_STAT_ONBOARDING'] = $this->extStatOnboarding;
		$this->arResult['EXT_STAT_BUTTON_ID'] = self::EXT_STAT_BUTTON_ID;
		// JS switches the caption on toggle, so both variants come from the same phrases as the server render
		$this->arResult['EXT_STAT_BUTTON_LABELS'] = [
			'enable' => (string)Loc::getMessage('CT_BBSU_EXT_STAT_ENABLE'),
			'enabled' => (string)Loc::getMessage('CT_BBSU_EXT_STAT_ENABLED'),
		];
		$this->arResult['SELFHOST_LICENSE_NOTICE'] = SelfHostedLicenseView::createForCurrentUser()->getNoticeParams();

		$this->includeComponentTemplate();
	}

	/**
	 * The collection window is managed for external BI systems only: BI Builder queries are always logged.
	 */
	private function initExternalStatState(bool $canManageKeys): void
	{
		if ($this->isBiBuilderService)
		{
			return;
		}

		$this->canToggleExternalStat = $canManageKeys;
		$this->isExternalStatEnabled = ExternalStatLoggerManager::isActive();
		$this->externalStatHoursLeft = ExternalStatLoggerManager::getHoursLeft();
		$this->extStatOnboarding = $this->prepareExtStatOnboarding();
	}

	/**
	 * The hint is shown once and marked as viewed by the aha-moment spotlight on the client.
	 */
	private function prepareExtStatOnboarding(): array
	{
		if (!$this->canToggleExternalStat)
		{
			return ['canShow' => false];
		}

		$config = $this->getAhaMomentSpotlightResolver()->resolve(
			new AhaMomentSpotlightOptions(
				baseId: self::EXT_STAT_ONBOARDING_SPOTLIGHT_ID,
				maxShows: 1,
			),
		);

		return [
			'canShow' => $config->canShow(),
			'id' => $config->getSpotlightId(),
			'showDelaySeconds' => $config->getShowDelaySeconds(),
		];
	}

	private function getAhaMomentSpotlightResolver(): AhaMomentSpotlightResolver
	{
		return ServiceLocator::getInstance()->get('biconnector.service.ahaMomentSpotlightResolver');
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

		if ($this->canToggleExternalStat)
		{
			Toolbar::addButton($this->getExternalStatButton(), ButtonLocation::AFTER_TITLE);
		}

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

	private function getExternalStatButton(): Buttons\Button
	{
		$button = new Buttons\Button([
			'color' => Buttons\Color::SUCCESS,
			'size' => Buttons\Size::LARGE,
			'text' => $this->getExternalStatButtonText(),
			'id' => self::EXT_STAT_BUTTON_ID,
			'click' => new Buttons\JsCode(self::TOGGLE_EXT_STAT_JS),
		]);
		// an explicit icon also keeps the toolbar from forcing the default "add" one on a success button
		$button->setIcon($this->isExternalStatEnabled ? Buttons\Icon::STOP : Buttons\Icon::START);
		// the onboarding hint binds to the rendered node by this attribute
		$button->setUniqId(self::EXT_STAT_BUTTON_ID);
		$button->addDataAttribute('testid', 'usage-stat-collect-btn');

		return $button;
	}

	private function getExternalStatButtonText(): string
	{
		if (!$this->isExternalStatEnabled)
		{
			return (string)Loc::getMessage('CT_BBSU_EXT_STAT_ENABLE');
		}

		return (string)Loc::getMessage('CT_BBSU_EXT_STAT_ENABLED');
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
		$button = null;

		if ($this->viewOverLimit)
		{
			$title = (string)Loc::getMessage('CC_BBUS_EMPTYSTATE_OVER_LIMIT_TITLE');
			$description = (string)(Loader::includeModule('bitrix24')
				? Loc::getMessage('CC_BBUS_EMPTYSTATE_OVER_LIMIT_DESCRIPTION_B24')
				: Loc::getMessage('CC_BBUS_EMPTYSTATE_OVER_LIMIT_DESCRIPTION'));
		}
		elseif ($this->isBiBuilderService)
		{
			$title = (string)Loc::getMessage('CC_BBUS_EMPTYSTATE_TITLE_SUPERSET_MSGVER_1');
			$description = (string)Loc::getMessage('CC_BBUS_EMPTYSTATE_DESCRIPTION_SUPERSET');
		}
		elseif ($this->isExternalStatEnabled)
		{
			$title = (string)Loc::getMessage('CC_BBUS_EMPTYSTATE_TITLE');
			$description = (string)Loc::getMessage('CC_BBUS_EMPTYSTATE_DESCRIPTION_COLLECTING');
		}
		else
		{
			$title = (string)Loc::getMessage('CC_BBUS_EMPTYSTATE_TITLE');
			$description = (string)Loc::getMessage('CC_BBUS_EMPTYSTATE_DESCRIPTION_DISABLED');
			$button = $this->canToggleExternalStat ? $this->getStubEnableButton() : null;
		}

		// the stub renderer escapes its content, so the [br] placeholder becomes a newline shown by CSS
		$description = str_replace('[br]', "\n", $description);

		return GridStubRenderer::render(
			self::STUB_IMAGE_PATH,
			$title,
			$description,
			$button,
		);
	}

	/**
	 * The grid rebuilds the stub markup on every redraw without a button init pass,
	 * so the handler is bound as a plain attribute instead of a button event.
	 */
	private function getStubEnableButton(): Buttons\Button
	{
		$button = new Buttons\Button([
			'text' => (string)Loc::getMessage('CT_BBSU_EXT_STAT_ENABLE'),
		]);
		$button
			->setAirDesign()
			->setStyle(Buttons\AirButtonStyle::FILLED_SUCCESS)
			->setSize(Buttons\Size::MEDIUM)
		;
		$button->setIcon(Buttons\Icon::START);
		$button->addAttribute('onclick', self::TOGGLE_EXT_STAT_JS);
		$button->addDataAttribute('testid', 'usage-stat-stub-collect-btn');

		return $button;
	}
}
