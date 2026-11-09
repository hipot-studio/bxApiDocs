<?php

use Bitrix\BIConnector\Access\Table\TableVisibilityService;
use Bitrix\BIConnector\ExternalSource\SourceManager;
use Bitrix\BIConnector\Internal\Grid\FilterPresetSynchronizer;
use Bitrix\BIConnector\Superset\Filter\StaleConditionResolver;
use Bitrix\BIConnector\Superset\Grid\DatasetRepository;
use Bitrix\BIConnector\Superset\Grid\ExternalDatasetGrid;
use Bitrix\BIConnector\Superset\Grid\TableGroupRepository;
use Bitrix\BIConnector\Superset\Grid\TableSectionParams;
use Bitrix\BIConnector\Superset\Grid\Settings\ExternalDatasetSettings;
use Bitrix\Biconnector\Internal\Grid\GridStubRenderer;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\UI\Filter\Options as FilterOptions;
use Bitrix\UI\Toolbar\Facade\Toolbar;
use Bitrix\UI\Buttons;
use Bitrix\UI\Toolbar\ButtonLocation;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

class ApacheSupersetExternalDatasetListComponent extends CBitrixComponent
{
	private const GRID_ID = 'biconnector_superset_external_dataset_grid';

	// Ids of the items of the create menu; the page knows the same ones and holds what each one does.
	private const CREATE_MENU_ITEM_IMPORT_CSV = 'import-csv';
	private const CREATE_MENU_ITEM_EXTERNAL_CONNECTION = 'external-connection';
	private const CREATE_MENU_ITEM_NEW_GROUP = 'new-group';

	private ExternalDatasetGrid $grid;
	private DatasetRepository $repository;

	/** @var array<string, array> Filters already checked for conditions that no longer resolve. */
	private array $resolvedFilters = [];

	public function onPrepareComponentParams($arParams)
	{
		$arParams['ID'] = (int)($arParams['ID'] ?? 0);
		$arParams['CODE'] = $arParams['CODE'] ?? '';

		return parent::onPrepareComponentParams($arParams);
	}

	public function executeComponent()
	{
		if (!\Bitrix\Main\Loader::includeModule('biconnector'))
		{
			$this->arResult['ERROR_MESSAGE'] = \Bitrix\Main\Localization\Loc::getMessage('BICONNECTOR_APACHE_SUPERSET_DATASET_GRID_ERROR_LOAD_MODULES') ?? '';
			$this->includeComponentTemplate();

			return;
		}

		$this->init();

		$this->grid->processRequest();
		$this->loadRows();
		$this->arResult['GRID'] = $this->grid;

		$this->includeComponentTemplate();
	}

	private function init(): void
	{
		FilterPresetSynchronizer::sync(self::GRID_ID, ExternalDatasetGrid::getFilterPresets());
		$this->initGrid();
		$this->initGridFilter();
		$this->initCreateButton();
	}

	private function initGrid(): void
	{
		$settings = new ExternalDatasetSettings([
			'ID' => self::GRID_ID,
			'SHOW_ROW_CHECKBOXES' => false,
			'SHOW_SELECTED_COUNTER' => false,
			'SHOW_TOTAL_COUNTER' => true,
			'EDITABLE' => false,
		]);

		$this->grid = new ExternalDatasetGrid($settings);

		// One answer of the axis and one repository of the groups for the whole hit: the template, the
		// counter and the page ask them about the same rows and the same codes, and each of them is
		// answered from the reads of the first ask.
		$visibility = new TableVisibilityService();
		$groupRepository = new TableGroupRepository($visibility);
		$this->repository = new DatasetRepository($visibility, $groupRepository);
		$this->arResult += (new TableSectionParams($groupRepository))->toArray();

		if (empty($this->grid->getOptions()->getSorting()['sort']))
		{
			$this->grid->getOptions()->setSorting('ID', 'desc');
		}

		$ormParams = $this->getOrmParams();
		$this->dropStaleGroupsFromSavedFilter();
		$totalCount = $this->repository->getTotalCount($ormParams['filter'] ?? []);
		$this->grid->initPagination($totalCount);
		$this->grid->openFirstPageOnLevelChange($this->repository->getSelectedGroupIds());

		if (!$totalCount)
		{
			$this->arResult['GRID_STUB'] = $this->getStub();
		}
	}

	/**
	 * Params of the grid with the conditions that no longer resolve taken out of the filter.
	 *
	 * The level of the section lives in the filter and outlives what it names: a group may be
	 * deleted or closed for the person while the condition on it stays saved. Left alone, such a
	 * condition leaves the section silently empty, so it is dropped and the full list opens.
	 */
	private function getOrmParams(): array
	{
		$ormParams = $this->grid->getOrmParams();

		if (!empty($ormParams['filter']) && is_array($ormParams['filter']))
		{
			$ormParams['filter'] = $this->resolveStaleConditions($ormParams['filter']);
		}

		return $ormParams;
	}

	/**
	 * The check costs a read of the groups the condition names, and the params of the grid are asked
	 * for twice a hit - by the counter and by the page - so the answer is kept for the second ask.
	 */
	private function resolveStaleConditions(array $filter): array
	{
		$cacheKey = md5(serialize($filter));
		$this->resolvedFilters[$cacheKey] ??= StaleConditionResolver::forTableSection()
			->resolve($filter)
			->fields
		;

		return $this->resolvedFilters[$cacheKey];
	}

	/**
	 * The page already ignores a group that no longer resolves, but the saved filter still names it:
	 * its chip stays in the filter bar and the page script takes it for the level of a group.
	 * The group is taken out of the saved filter as well, so the bar shows the level the page shows.
	 */
	private function dropStaleGroupsFromSavedFilter(): void
	{
		$options = new FilterOptions(self::GRID_ID, ExternalDatasetGrid::getFilterPresets());
		$presetId = (string)$options->getCurrentFilterId();
		$fields = $options->getFilterSettings($presetId)['fields'] ?? null;
		if (!is_array($fields) || empty($fields[DatasetRepository::FILTER_FIELD_GROUP]))
		{
			return;
		}

		$resolved = StaleConditionResolver::forTableSection()->resolve($fields);
		if ($resolved->stale === [])
		{
			return;
		}

		$options->setFilterSettings(
			$presetId,
			[
				'fields' => $resolved->fields,
				'additional' => $options->getAdditionalPresetFields($presetId),
			],
			false,
			false,
		);
		$options->save();
	}

	private function loadRows(): void
	{
		$ormParams = $this->getOrmParams();
		$rowsData = $this->repository->getPageRows(
			$ormParams,
			$ormParams['offset'] ?? 0,
			$ormParams['limit'] ?? 20,
		);

		$this->grid->setRawRows($rowsData);
	}

	private function initGridFilter(): void
	{
		$filter = $this->grid->getFilter();
		if ($filter)
		{
			$options = \Bitrix\Main\Filter\Component\ComponentParams::get(
				$this->grid->getFilter(),
				[
					'GRID_ID' => $this->grid->getId(),
					'FILTER_PRESETS' => ExternalDatasetGrid::getFilterPresets(),
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

	private function initCreateButton(): void
	{
		$menuItems = $this->getCreateMenuItems();
		$this->arResult['CREATE_MENU_ITEMS'] = $menuItems;
		$this->arResult['IS_GROUP_LEVEL_SELECTED'] = $this->repository->isGroupLevelSelected();

		if ($menuItems === [])
		{
			$button = new Buttons\CreateButton([
				'dataset' => [
					'toolbar-collapsed-icon' => Buttons\Icon::ADD,
				],
			]);

			$button->getAttributeCollection()['onclick'] = 'BX.BIConnector.DatasetImportV2.Slider.open("csv")';
		}
		else
		{
			$button = new Buttons\Split\CreateButton([
				'dataset' => [
					'toolbar-collapsed-icon' => Buttons\Icon::ADD,
				],
			]);

			$button->getMainButton()->getAttributeCollection()['onclick'] = 'BX.BIConnector.DatasetImportV2.Slider.open("csv")';

			// The menu is built by the section itself at every opening, the way the panel of the reports
			// section does it: the level of the section changes without a reload of the page, and a menu
			// drawn by the server would keep telling of the level it was drawn on.
			$button->getMenuButton()->getAttributeCollection()['onclick'] =
				'BX.BIConnector.ExternalDatasetManager.Instance.showCreationMenu(event)'
			;

			$button->getAttributeCollection()->addJsonOption(
				'menuTarget',
				\Bitrix\UI\Buttons\Split\Type::MENU
			);
		}

		Toolbar::addButton($button, ButtonLocation::AFTER_TITLE);
	}

	/**
	 * Items of the menu of the create button, in the order they are shown.
	 *
	 * The creation of a group lives here and not in a button of its own, the way the panel of the
	 * reports section keeps it: one control creates everything the section holds. The item belongs to
	 * the one who arranges the rights on tables; everybody else sees the menu exactly as it was.
	 *
	 * Only the id and the caption travel to the page: what an item does is known to the section, and
	 * the phrases stay in the language file of the component.
	 *
	 * @return array<int, array{id: string, text: string}> An empty list leaves the create button
	 *                                                     without a menu at all.
	 */
	private function getCreateMenuItems(): array
	{
		$items = [];

		if (SourceManager::isExternalConnectionsAvailable())
		{
			$items[] = [
				'id' => self::CREATE_MENU_ITEM_IMPORT_CSV,
				'text' => Loc::getMessage('BICONNECTOR_APACHE_SUPERSET_DATASET_GRID_MENU_ITEM_IMPORT_CSV_MSGVER_1'),
			];
			$items[] = [
				'id' => self::CREATE_MENU_ITEM_EXTERNAL_CONNECTION,
				'text' => Loc::getMessage('BICONNECTOR_APACHE_SUPERSET_DATASET_GRID_MENU_ITEM_EXTERNAL_CONNECTION'),
			];
		}

		if (!empty($this->arResult['IS_AVAILABLE_GROUP_MANAGEMENT']))
		{
			$items[] = [
				'id' => self::CREATE_MENU_ITEM_NEW_GROUP,
				'text' => Loc::getMessage('BICONNECTOR_APACHE_SUPERSET_DATASET_GRID_MENU_ITEM_NEW_GROUP'),
			];
		}

		return $items;
	}

	private function getStub(): ?string
	{
		if ($this->grid->getOrmFilter() !== [])
		{
			return null;
		}

		return GridStubRenderer::render(
			'/bitrix/images/biconnector/workspace-analytic-grid/dataset-empty-state.webp',
			Loc::getMessage('BICONNECTOR_APACHE_SUPERSET_DATASET_GRID_STUB_TITLE_MSGVER_2') ?? '',
			Loc::getMessage('BICONNECTOR_APACHE_SUPERSET_DATASET_GRID_STUB_DESCRIPTION_MSGVER_3'),
		);
	}
}
