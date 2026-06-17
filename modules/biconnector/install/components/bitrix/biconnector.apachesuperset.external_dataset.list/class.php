<?php

use Bitrix\BIConnector\ExternalSource\SourceManager;
use Bitrix\BIConnector\Superset\Grid\DatasetRepository;
use Bitrix\BIConnector\Superset\Grid\ExternalDatasetGrid;
use Bitrix\BIConnector\Superset\Grid\Settings\ExternalDatasetSettings;
use Bitrix\Main\Localization\Loc;
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

	private ExternalDatasetGrid $grid;
	private DatasetRepository $repository;

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
		$this->repository = new DatasetRepository();

		if (empty($this->grid->getOptions()->getSorting()['sort']))
		{
			$this->grid->getOptions()->setSorting('ID', 'desc');
		}

		$ormParams = $this->grid->getOrmParams();
		$totalCount = $this->repository->getTotalCount($ormParams['filter'] ?? []);
		$this->grid->initPagination($totalCount);

		if (!$totalCount)
		{
			$this->arResult['GRID_STUB'] = $this->getStub();
		}
	}

	private function loadRows(): void
	{
		$ormParams = $this->grid->getOrmParams();
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
		if (SourceManager::isExternalConnectionsAvailable())
		{
			$button = new Buttons\Split\CreateButton([
				'dataset' => [
					'toolbar-collapsed-icon' => Buttons\Icon::ADD,
				],
			]);

			$button->getMainButton()->getAttributeCollection()['onclick'] = 'BX.BIConnector.DatasetImport.Slider.open("csv")';

			$menuItems = [
				[
					'text' => Loc::getMessage('BICONNECTOR_APACHE_SUPERSET_DATASET_GRID_MENU_ITEM_IMPORT_CSV_MSGVER_1'),
					'onclick' => new Buttons\JsCode('this.close(); BX.BIConnector.DatasetImport.Slider.open("csv")'),
				],
				[
					'text' => Loc::getMessage('BICONNECTOR_APACHE_SUPERSET_DATASET_GRID_MENU_ITEM_EXTERNAL_CONNECTION'),
					'onclick' => new Buttons\JsCode('this.close(); BX.BIConnector.DatasetImport.Slider.open("1c")'),
				],
			];

			$button->setMenu([
				'items' => $menuItems,
				'closeByEsc' => true,
				'angle' => true,
				'offsetLeft' => 20,
				'autoHide' => true,
			]);

			$button->getAttributeCollection()->addJsonOption(
				'menuTarget',
				\Bitrix\UI\Buttons\Split\Type::MENU
			);
		}
		else
		{
			$button = new Buttons\CreateButton([
				'dataset' => [
					'toolbar-collapsed-icon' => Buttons\Icon::ADD,
				],
			]);

			$button->getAttributeCollection()['onclick'] = 'BX.BIConnector.DatasetImport.Slider.open("csv")';
		}

		Toolbar::addButton($button, ButtonLocation::AFTER_TITLE);
	}

	private function getStub(): ?string
	{
		if ($this->grid->getOrmFilter() !== [])
		{
			return null;
		}

		$iconPath = $this->getPath() . '/images/not-found.svg';
		$title = Loc::getMessage('BICONNECTOR_APACHE_SUPERSET_DATASET_GRID_STUB_TITLE_MSGVER_1') ?? '';
		$description = Loc::getMessage('BICONNECTOR_APACHE_SUPERSET_DATASET_GRID_STUB_DESCRIPTION_MSGVER_2') ?? '';

		return <<<HTML
			<div class="biconnector-dataset-grid-stub-container">
				<div class="biconnector-dataset-grid-stub-logo">
					<img src="{$iconPath}" alt="Not Found">
				</div>
				<div class="main-grid-empty-block-title">
					{$title}
				</div>
				<div class="main-grid-empty-block-description document-list-stub-description">
					{$description}
				</div>
			</div>
		HTML;
	}
}
