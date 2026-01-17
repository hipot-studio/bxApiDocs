<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

use Bitrix\Crm\AutomatedSolution\AutomatedSolutionManager;
use Bitrix\Crm\AutomatedSolution\Entity\AutomatedSolutionTable;
use Bitrix\Crm\Component\Base;
use Bitrix\Crm\Component\EntityList\Grid;
use Bitrix\Crm\Component\EntityList\Settings\PermissionItem;
use Bitrix\Crm\Integration\Analytics\Dictionary;
use Bitrix\Crm\Security\Role\Manage\Manager\CustomSectionListSelection;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Summary\SummaryFactory;
use Bitrix\Main\Grid\Settings;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ModuleManager;
use Bitrix\Main\Type\Collection;
use Bitrix\Main\Type\DateTime;
use Bitrix\UI\Buttons;
use Bitrix\UI\Buttons\AirButtonStyle;
use Bitrix\UI\Buttons\Color;
use Bitrix\UI\Buttons\Icon;
use Bitrix\UI\Buttons\JsCode;
use Bitrix\UI\Toolbar;

if (!Loader::includeModule('crm'))
{
	return;
}

class CrmAutomatedSolutionListComponent extends Base
{
	private const NOT_ORM_FIELDS = [
		'LAST_ACTIVITY_TIME',
		'TYPE_IDS',
		'PERMISSIONS',
	];

	public const TOOLBAR_SETTINGS_BUTTON_ID = 'automated_solution-list-toolbar-settings-button';
	public const TOOLBAR_MARKETPLACE_BUTTON_ID = 'automated_solution-list-toolbar-marketplace-button';

	private AutomatedSolutionManager $manager;
	private SummaryFactory $summaryFactory;

	private ?Grid\AutomatedSolutionGrid $grid = null;

	protected function init(): void
	{
		parent::init();

		if (!$this->userPermissions->automatedSolution()->canEdit())
		{
			$this->addError(\Bitrix\Crm\Controller\ErrorCode::getAccessDeniedError());

			return;
		}

		$this->manager = \Bitrix\Crm\Service\Container::getInstance()->getAutomatedSolutionManager();
		$this->summaryFactory = \Bitrix\Crm\Service\Container::getInstance()->getSummaryFactory();
	}

	public function executeComponent()
	{
		$this->init();

		if($this->getErrors())
		{
			$this->includeComponentTemplate();
			return;
		}

		$this->getApplication()->SetTitle(Loc::getMessage('CRM_AUTOMATED_SOLUTION_LIST_TITLE'));

		$this->arResult['grid'] = $this->prepareGridParams();

		$this->includeComponentTemplate();
	}

	protected function getToolbarParameters(): array
	{
		$buttons = [];
		if ($this->userPermissions->automatedSolution()->canEdit())
		{
			$createUrl = $this->router->getAutomatedSolutionDetailUrl(0);

			\Bitrix\Crm\Service\Container::getInstance()->getLocalization()->loadMessages();

			$buttons[Toolbar\ButtonLocation::AFTER_TITLE][] = new Buttons\Button([
				'color' => Buttons\Color::SUCCESS,
				'text' => Loc::getMessage('CRM_COMMON_ACTION_CREATE'),
				'link' => $createUrl->getUri(),
			]);
			$marketUrl = \Bitrix\Crm\Integration\Market\Router::getCategoryPath('automated_solutions_seats');

			$marketButton = new Buttons\Button([
				'text' => Loc::getMessage('CRM_AUTOMATED_SOLUTION_LIST_MARKET'),
				'color' => Color::SECONDARY,
				'useAirDesign' => true,
				'icon' => Icon::MARKET,
				'style' => AirButtonStyle::FILLED,
				'onclick' => new JsCode(
					"BX.SidePanel.Instance.open('" . $marketUrl . "')",
				),
			]);
			$marketButton->addAttribute('id', static::TOOLBAR_MARKETPLACE_BUTTON_ID);

			$buttons[Toolbar\ButtonLocation::RIGHT][] = $marketButton;
		}

		$settingsItems = $this->getSettingsItems();
		if (count($settingsItems) > 0)
		{
			$settingsButton = new Buttons\SettingsButton([
				'menu' => [
					'id' => 'automated_solution-list-toolbar-settings-menu',
					'items' => $settingsItems,
					'offsetLeft' => 20,
					'closeByEsc' => true,
					'angle' => true
				],
			]);

			$settingsButton->addAttribute('id', static::TOOLBAR_SETTINGS_BUTTON_ID);
			$buttons[Toolbar\ButtonLocation::RIGHT][] = $settingsButton;
		}

		return array_merge(parent::getToolbarParameters(), [
			'buttons' => $buttons,
			'filter' => [
				'FILTER_ID' => $this->getGrid()->getFilter()->getID(),
				'GRID_ID' => $this->getGrid()->getId(),
				'FILTER' => $this->getGrid()->getFilter()->getFieldArrays(),
				'ENABLE_LABEL' => true,
				'RESET_TO_DEFAULT_MODE' => true,
				'DISABLE_SEARCH' => false,
				'ENABLE_LIVE_SEARCH' => true,
				'ENABLE_ADDITIONAL_FILTERS' => true,
				'ENABLE_FIELDS_SEARCH' => 'Y',
				'CONFIG' => [
					'popupColumnsCount' => 4,
					'popupWidth' => 800,
					'showPopupInCenter' => true,
				],
				'USE_CHECKBOX_LIST_FOR_SETTINGS_POPUP' => ModuleManager::isModuleInstalled('ui'),
			],
			'isWithFavoriteStar' => true,
			'hideBorder' => true,
		]);
	}

	private function getSettingsItems(): array
	{
		$items = [];


		if (
			Container::getInstance()->getUserPermissions()->automatedSolution()->canEdit()
			&& \Bitrix\Main\Loader::includeModule('rest')
			&& is_callable('\Bitrix\Rest\Marketplace\Url::getConfigurationPlacementUrl')
			&& \Bitrix\Crm\Restriction\RestrictionManager::getAutomatedSolutionExportImportRestriction()->hasPermission()
		)
		{
			$items[] = [
				'text' => Loc::getMessage('CRM_AUTOMATED_SOLUTION_LIST_EXPORT_IMPORT_ITEM'),
				'href' => \Bitrix\Rest\Marketplace\Url::getConfigurationPlacementUrl(
					'automated_solution',
					'automated_solution_list'
				),
				'onclick' => new Buttons\JsHandler('BX.Crm.Router.Instance.closeSettingsMenu'),
			];
		}

		$permissionItem = new PermissionItem(new CustomSectionListSelection());
		$permissionItem->setAnalytics([
			'c_section' => Dictionary::SECTION_AUTOMATION,
			'c_sub_section' => Dictionary::SUB_SECTION_LIST,
		]);
		if ($permissionItem->canShow())
		{
			$items[] = $permissionItem->toArray();
		}

		return $items;
	}

	private function prepareGridParams(): array
	{
		$grid = $this->getGrid();

		$totalCount = AutomatedSolutionTable::getCount(
			$grid->getOrmFilter() ?? []
		);
		$grid->initPagination($totalCount);

		$grid->processRequest();

		$ormParams = $grid->getOrmParams();
		$ormParams['select'][] = 'SOURCE_ID';
		if (!in_array('PERMISSIONS', $ormParams['select'], true))
		{
			$ormParams['select'][] = 'PERMISSIONS';
		}

		$rawRows = $this->getRawRows($ormParams);
		$grid->setRawRows($rawRows);

		$params =  [
			'USE_CHECKBOX_LIST_FOR_SETTINGS_POPUP' => true,
		];

		if (empty($rawRows))
		{
			$params['STUB'] = [
				'title' => Loc::getMessage('CRM_AUTOMATED_SOLUTION_STUB_TITLE'),
				'description' => Loc::getMessage('CRM_AUTOMATED_SOLUTION_STUB_DESCRIPTION'),
			];
		}
		else
		{
			foreach ($rawRows as $rawRow)
			{
				if (AutomatedSolutionTable::isImportedFromMarketplace((int)$rawRow['SOURCE_ID']))
				{
					$this->arResult['hasImportedAutomatedSolution'] = true;
				}
			}
		}

		return \Bitrix\Main\Grid\Component\ComponentParams::get($grid, $params);
	}

	private function getGrid(): Grid\AutomatedSolutionGrid
	{
		$this->grid ??= new Grid\AutomatedSolutionGrid(
			new Settings([
				'ID' => 'crm-automated-solution-list',
			]),
			$this->manager,
			$this->userPermissions,
			$this->router,
		);

		return $this->grid;
	}

	private function getRawRows(array $ormParams): iterable
	{
		$isSelectLastActivityTime = in_array('LAST_ACTIVITY_TIME', $ormParams['select'], true);
		$isSelectTypeIds = $isSelectLastActivityTime || in_array('TYPE_IDS', $ormParams['select'], true);
		$isSelectPermissions = in_array('PERMISSIONS', $ormParams['select'], true);

		// remove not-orm fields
		$ormParams['select'] = array_diff($ormParams['select'], self::NOT_ORM_FIELDS);

		$rows = AutomatedSolutionTable::getList($ormParams)->fetchAll();

		return $this->enrichRawRows($rows, $isSelectLastActivityTime, $isSelectTypeIds, $isSelectPermissions);
	}

	private function enrichRawRows(
		array $rawRows,
		bool $isSelectLastActivityTime,
		bool $isSelectTypeIds,
		bool $isSelectPermissions,
	): array
	{
		if ($isSelectPermissions)
		{
			foreach ($rawRows as &$row)
			{
				$automatedSolution = $this->manager->getAutomatedSolution($row['ID']);
				$row['PERMISSIONS'] = $automatedSolution['CODE'];
			}
			unset($row);
		}

		if (!$isSelectLastActivityTime && !$isSelectTypeIds)
		{
			return $rawRows;
		}

		$automatedSolutionIds = array_column($rawRows, 'ID');
		Collection::normalizeArrayValuesByInt($automatedSolutionIds);

		$solutionIdToTypeIdsMap = $this->manager->getBoundTypeIdsForMultipleAutomatedSolutions($automatedSolutionIds);
		foreach ($rawRows as &$row)
		{
			$typeIds = $solutionIdToTypeIdsMap[$row['ID']] ?? [];

			$row['TYPE_IDS'] = $typeIds;
		}
		unset($row);

		if (!$isSelectLastActivityTime)
		{
			return $rawRows;
		}

		$allTypeIds = [];
		foreach ($solutionIdToTypeIdsMap as $typeIds)
		{
			$allTypeIds = array_merge($allTypeIds, $typeIds);
		}
		$allTypeIds = array_unique($allTypeIds);

		$typeIdToLastActivityTimeMap = [];
		foreach ($allTypeIds as $typeId)
		{
			$typeIdToLastActivityTimeMap[$typeId] =
				$this->summaryFactory->getDynamicTypeSummary($typeId)?->getLastActivityTime()
			;
		}

		foreach ($rawRows as &$row)
		{
			$typeIds = $solutionIdToTypeIdsMap[$row['ID']] ?? [];

			$row['LAST_ACTIVITY_TIME'] = $this->findMaxLastActivityTime($typeIds, $typeIdToLastActivityTimeMap);
		}

		return $rawRows;
	}

	/**
	 * @param int[] $typeIds
	 * @param Array<int, DateTime|null> $typeIdToLastActivityTimeMap
	 *
	 * @return DateTime|null
	 */
	private function findMaxLastActivityTime(array $typeIds, array $typeIdToLastActivityTimeMap): ?DateTime
	{
		/** @var DateTime|null $maxTime */
		$maxTime = null;
		foreach ($typeIds as $typeId)
		{
			$lastActivityTime = $typeIdToLastActivityTimeMap[$typeId] ?? null;
			if ($maxTime === null || $lastActivityTime?->getTimestamp() > $maxTime->getTimestamp())
			{
				$maxTime = $lastActivityTime;
			}
		}

		return $maxTime;
	}
}
