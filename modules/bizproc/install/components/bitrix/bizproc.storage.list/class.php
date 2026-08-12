<?php

declare(strict_types=1);

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

use Bitrix\Bizproc\Internal\Grid\StorageList\Filter\StorageListFilter;
use Bitrix\Bizproc\Internal\Grid\StorageList\StorageListGrid;
use Bitrix\Bizproc\Public\Provider\Params\StorageType\StorageTypeFilter;
use Bitrix\Bizproc\Public\Provider\Params\StorageType\StorageTypeSort;
use Bitrix\Bizproc\Public\Provider\StorageTypeProvider;
use Bitrix\Main\Grid\Component\ComponentParams;
use Bitrix\Main\Grid\Panel\Actions;
use Bitrix\Main\Grid\Panel\Snippet;
use Bitrix\Main\Grid\Panel\Types;
use Bitrix\Main\Grid\Settings;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Bizproc\Api\Enum\ErrorMessage;
use Bitrix\Main\ErrorCollection;
use Bitrix\Main\Provider\Params\GridParams;
use Bitrix\Main\Provider\Params\Pager;
use Bitrix\Main\UI\Filter\Options;
use Bitrix\UI\Toolbar\Facade\Toolbar;

class BizprocStorageListComponent extends CBitrixComponent
{
	private const GRID_ID = 'bizproc-storage-list';
	private const DEFAULT_PAGE_SIZE = 50;
	private const DEFAULT_OFFSET = 0;

	private ErrorCollection $errorCollection;

	private function getErrorMessages(): array
	{
		return array_map(
			static fn(\Bitrix\Main\Error $error): string => $error->getMessage(),
			$this->errorCollection->toArray(),
		);
	}

	protected function setTitle(string $title): void
	{
		global $APPLICATION;

		$APPLICATION->SetTitle($title);
	}

	public function executeComponent(): void
	{
		$this->errorCollection = new ErrorCollection();

		if (!Loader::includeModule('bizproc'))
		{
			$this->errorCollection->setError(new \Bitrix\Main\Error(
				Loc::getMessage('BIZPROC_STORAGE_LIST_MODULE_NOT_INSTALLED')
			));
			$this->arResult['errors'] = $this->getErrorMessages();
			$this->includeComponentTemplate();

			return;
		}

		if (!(new CBPWorkflowTemplateUser(\CBPWorkflowTemplateUser::CurrentUser))->isAdmin())
		{
			$this->errorCollection->setError(ErrorMessage::ACCESS_DENIED->getError());
			$this->arResult['errors'] = $this->getErrorMessages();
			$this->includeComponentTemplate();

			return;
		}

		$settings = new Settings([
			'ID' => self::GRID_ID,
		]);

		$grid = new StorageListGrid($settings);
		$grid->processRequest();

		$storageListFilter = new StorageListFilter(self::GRID_ID);
		$filterOptions = new Options($storageListFilter->getId());
		$filter = new StorageTypeFilter($filterOptions->getFilter($storageListFilter->getFields()));

		$provider = new StorageTypeProvider();

		$totalCount = $provider->getCount($filter);
		$grid->getPagination()?->setRecordCount($totalCount);

		$gridSort = $grid->getOptions()->getSorting([
			'sort' => ['ID' => 'ASC'],
		]);

		$pagination = $grid->getPagination();
		$gridParams = new GridParams(
			pager: new Pager(
				limit: $pagination?->getLimit() ?? self::DEFAULT_PAGE_SIZE,
				offset: $pagination?->getOffset() ?? self::DEFAULT_OFFSET,
			),
			filter: $filter,
			sort: new StorageTypeSort($gridSort['sort']),
		);

		$collection = $provider->getList($gridParams);

		$rows = [];
		foreach ($collection as $item)
		{
			$rows[] = [
				'ID' => $item->getId(),
				'TITLE' => $item->getTitle(),
				'DESCRIPTION' => $item->getDescription(),
				'CODE' => $item->getCode(),
			];
		}

		$grid->setRawRows($rows);

		$this->setTitle(Loc::getMessage('BIZPROC_STORAGE_LIST_TITLE') ?? '');

		if (Loader::includeModule('ui'))
		{
			Toolbar::deleteFavoriteStar();
			Toolbar::addFilter($storageListFilter->getOptions(self::GRID_ID));
		}

			$this->arResult['GRID_PARAMS'] = [
				...ComponentParams::get($grid),
				'SHOW_ROW_CHECKBOXES' => true,
				'SHOW_CHECK_ALL_CHECKBOXES' => true,
				'SHOW_SELECTED_COUNTER' => true,
				'ACTION_PANEL' => $this->buildGridActions(),
				'SHOW_ACTION_PANEL' => true,
			];
		$this->includeComponentTemplate();
	}

	private function buildGridActions(): array
	{
		$snippet = new Snippet();

		return [
			'GROUPS' => [
				[
					'ITEMS' => [
						[
							'TYPE' => Types::DROPDOWN,
							'ID' => self::GRID_ID . '_group_action',
							'NAME' => 'groupAction',
							'ITEMS' => [
								[
									'NAME' => Loc::getMessage('BIZPROC_STORAGE_LIST_ACTION_PANEL_PLACEHOLDER') ?? '',
									'VALUE' => 'default',
									'ONCHANGE' => [
										['ACTION' => Actions::RESET_CONTROLS],
									],
								],
								[
									'NAME' => Loc::getMessage('BIZPROC_STORAGE_LIST_ACTION_PANEL_DELETE') ?? '',
									'VALUE' => 'delete',
									'ONCHANGE' => [
										[
											'ACTION' => Actions::CREATE,
											'DATA' => [
												$snippet->getApplyButton(['ONCHANGE' => $this->getMultipleDeleteAction()]),
											],
										],
									],
								],
							],
						],
					],
				],
			],
			];
		}

		private function getMultipleDeleteAction(): array
		{
			return [
				[
					'ACTION' => Actions::CALLBACK,
					'CONFIRM' => true,
					'CONFIRM_MESSAGE' => Loc::getMessage('BIZPROC_STORAGE_LIST_DELETE_CONFIRM') ?? '',
					'CONFIRM_APPLY_BUTTON' => Loc::getMessage('BIZPROC_STORAGE_LIST_DELETE_CONFIRM_OK') ?? '',
					'DATA' => [
						[
							'JS' => "BX.Bizproc.Component.StorageList.Instance?.deleteSelected();",
						],
					],
				],
			];
		}
	}
