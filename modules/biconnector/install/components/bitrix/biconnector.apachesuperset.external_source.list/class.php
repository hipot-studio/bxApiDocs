<?php

use Bitrix\BIConnector\Superset\Grid\ExternalSourceRepository;
use Bitrix\BIConnector\Superset\Grid\ExternalSourceGrid;
use Bitrix\BIConnector\Superset\Grid\Settings\ExternalSourceSettings;
use Bitrix\BIConnector\Internal\Grid\FilterPresetSynchronizer;
use Bitrix\Biconnector\Internal\Grid\GridStubRenderer;
use Bitrix\Main\Localization\Loc;
use Bitrix\UI\Toolbar\Facade\Toolbar;
use Bitrix\UI\Buttons;
use Bitrix\UI\Toolbar\ButtonLocation;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

class ApacheSupersetExternalSourceListComponent extends CBitrixComponent
{
	private const GRID_ID = 'biconnector_superset_external_source_grid';

	private ExternalSourceGrid $grid;
	private ExternalSourceRepository $repository;

	public function onPrepareComponentParams($arParams)
	{
		$arParams['ID'] = (int)($arParams['ID'] ?? 0);
		$arParams['CODE'] = $arParams['CODE'] ?? '';

		return parent::onPrepareComponentParams($arParams);
	}

	public function executeComponent()
	{
		if (!\Bitrix\Main\Loader::includeModule('biconnector') || !\Bitrix\Main\Loader::includeModule('crm'))
		{
			$this->arResult['ERROR_MESSAGE'] = \Bitrix\Main\Localization\Loc::getMessage('BICONNECTOR_SUPERSET_EXTERNAL_SOURCE_GRID_ERROR_LOAD_MODULES') ?? '';
			$this->includeComponentTemplate();

			return;
		}

		$this->init();

		$this->grid->processRequest();
		$rows = $this->loadRows();
		$this->arResult['SOURCE_TITLE_LIST'] = $this->prepareSourceTitleList($rows);
		$this->arResult['ENABLED_TRACKING_SOURCE_DATASET_INFO'] = in_array('CRM', array_column($rows, 'MODULE'), true);
		$this->arResult['GRID'] = $this->grid;

		$this->includeComponentTemplate();
	}

	private function init(): void
	{
		FilterPresetSynchronizer::sync(self::GRID_ID, ExternalSourceGrid::getFilterPresets());
		$this->initGrid();
		$this->initGridFilter();
		$this->initExternalSourceRepo();
		$this->initPagination();
		$this->initStub();
		$this->initCreateButton();
	}

	private function initGrid(): void
	{
		$settings = new ExternalSourceSettings([
			'ID' => self::GRID_ID,
			'SHOW_ROW_CHECKBOXES' => false,
			'SHOW_SELECTED_COUNTER' => false,
			'SHOW_TOTAL_COUNTER' => true,
			'EDITABLE' => false,
		]);

		$grid = new ExternalSourceGrid($settings);
		$this->grid = $grid;

		if (empty($this->grid->getOptions()->getSorting()['sort']))
		{
			$this->grid->getOptions()->setSorting('DATE_CREATE', 'desc');
		}
	}

	private function loadRows(): array
	{
		$rawData = $this->repository->getRawData();

		$this->grid->setRawRows(
			$rawData
		);

		return $rawData;
	}

	private function initGridFilter(): void
	{
		$filter = $this->grid->getFilter();
		$gridId = $this->grid->getId();

		if ($filter)
		{
			$options = \Bitrix\Main\Filter\Component\ComponentParams::get(
				$filter,
				[
					'GRID_ID' => $gridId,
					'FILTER_PRESETS' => ExternalSourceGrid::getFilterPresets(),
				]
			);
		}
		else
		{
			$options = [
				'FILTER_ID' => $gridId,
			];
		}

		Toolbar::addFilter($options);
	}

	private function initCreateButton(): void
	{
		$createButton = new Buttons\CreateButton([
			'dataset' => [
				'toolbar-collapsed-icon' => Buttons\Icon::ADD,
			],
		]);

		$createButton->getAttributeCollection()['onclick'] = 'BX.BIConnector.ExternalSourceManager.Instance.openCreateSourceSlider()';

		$createButton->setMenu([
			'closeByEsc' => true,
			'angle' => true,
			'offsetLeft' => 115,
			'autoHide' => true,
		]);

		Toolbar::addButton($createButton, ButtonLocation::AFTER_TITLE);
	}

	private function initExternalSourceRepo()
	{
		$this->repository = new ExternalSourceRepository($this->grid);
	}

	private function initPagination()
	{
		$totalCount = $this->repository->getTotalCountForPagination();
		$this->grid->initPagination($totalCount);
	}

	private function initStub(): void
	{
		if ($this->grid->getPagination()->getRecordCount() !== 0)
		{
			return;
		}

		$this->arResult['GRID_STUB'] = $this->getStub();
	}

	private function getStub(): ?string
	{
		$filter = $this->grid->getOrmFilter();
		if (
			$filter !== []
			&& (count($filter) > 1 || $filter['ACTIVE'] !== 'Y')
		)
		{
			return null;
		}
		$button = new Buttons\CreateButton([
			'text' => Loc::getMessage('BICONNECTOR_SUPERSET_EXTERNAL_SOURCE_GRID_STUB_BUTTON'),
			'color' => Buttons\Color::PRIMARY,
			'icon' => Buttons\Icon::ADD,
			'air' => true,
		]);

		$button->getAttributeCollection()['onclick'] =
			'BX.BIConnector.ExternalSourceManager.Instance.openCreateSourceSlider()'
		;

		return GridStubRenderer::render(
			'/bitrix/images/biconnector/workspace-analytic-grid/source-empty-state.webp',
			Loc::getMessage('BICONNECTOR_SUPERSET_EXTERNAL_SOURCE_GRID_STUB_TITLE_MSGVER_2') ?? '',
			Loc::getMessage('BICONNECTOR_SUPERSET_EXTERNAL_SOURCE_GRID_STUB_DESCRIPTION_MSGVER_2'),
			$button,
		);
	}

	private function prepareSourceTitleList(array $rows): array
	{
		$result = [];

		foreach ($rows as $row)
		{
			if ($row['MODULE'] === 'BI')
			{
				$id = (int)$row['ID'];
				$result[$id] = $row['TITLE'] ?? (string)$id;
			}
		}

		return $result;
	}
}
