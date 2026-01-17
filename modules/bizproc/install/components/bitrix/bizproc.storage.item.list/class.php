<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

use Bitrix\Bizproc\Internal\Model\StorageRecordTable;
use Bitrix\Bizproc\Internal\Service\StorageField\FieldService;
use Bitrix\Main\Localization\Loc;
use Bitrix\Bizproc\Public\Provider\StorageItemProvider;
use Bitrix\Bizproc\Public\Provider\StorageTypeProvider;
use Bitrix\Bizproc\Public\Provider\Params\StorageItem\StorageItemFilter;
use Bitrix\Bizproc\Public\Provider\Params\StorageItem\StorageItemSort;
use Bitrix\Main\Provider\Params\GridParams;
use Bitrix\Main\Provider\Params\Pager;
use Bitrix\Main\Loader;
use Bitrix\Bizproc\Api\Enum\ErrorMessage;
use Bitrix\Main\Grid\Options;
use Bitrix\Main\UI\PageNavigation;
use Bitrix\Main\ErrorCollection;
use Bitrix\Main\Application;
use Bitrix\Main\Type\DateTime;
use Bitrix\UI\Toolbar\ButtonLocation;
use Bitrix\UI\Toolbar\Facade\Toolbar;
use Bitrix\UI\Buttons;

class BizprocStorageItemListComponent extends CBitrixComponent
{
	protected const NAV_PARAM_NAME = 'storage-item-list';
	protected const DEFAULT_PAGE_SIZE = 10;
	protected int $storageTypeId = 0;
	protected StorageItemProvider $storageItemProvider;
	protected StorageTypeProvider $storageTypeProvider;
	protected ErrorCollection $errorCollection;
	protected ?Options $gridOptions = null;
	protected ?PageNavigation $pageNavigation = null;
	protected ?string $gridId = null;
	protected ?array $userTypes = null;
	protected FieldService $fieldService;

	protected function init(): void
	{
		$this->errorCollection = new ErrorCollection();
		$request = Application::getInstance()->getContext()->getRequest();

		$this->storageTypeId = (int)($this->arParams['storageId'] ?? $request->get('storageId'));
		if (!$this->storageTypeId)
		{
			$this->errorCollection[] = new \Bitrix\Main\Error(
				Loc::getMessage('BIZPROC_STORAGE_ITEM_LIST_NO_STORAGE_TYPE_ID')
			);

			return;
		}

		if (!Loader::includeModule('bizproc'))
		{
			$this->errorCollection->setError(new \Bitrix\Main\Error(
				Loc::getMessage('BIZPROC_STORAGE_ITEM_LIST_MODULE_NOT_INSTALLED')
			));

			return;
		}

		if (!(new CBPWorkflowTemplateUser(\CBPWorkflowTemplateUser::CurrentUser))->isAdmin())
		{
			$this->errorCollection->setError(ErrorMessage::ACCESS_DENIED->getError());

			return;
		}

		$this->storageTypeProvider = new StorageTypeProvider();
		if (!$this->storageTypeProvider->exists($this->storageTypeId))
		{
			$this->errorCollection[] = new \Bitrix\Main\Error(
				Loc::getMessage('BIZPROC_STORAGE_ITEM_LIST_NO_STORAGE_TYPE_ID')
			);

			return;
		}

		$this->fieldService = new FieldService($this->storageTypeId);
		$this->storageItemProvider = new StorageItemProvider($this->storageTypeId);
	}

	public function executeComponent()
	{
		$this->init();
		if (!$this->errorCollection->isEmpty())
		{
			$this->arResult['errors'] = $this->errorCollection->toArray();
			$this->includeComponentTemplate();

			return;
		}

		$title = $this->storageTypeProvider->getById($this->storageTypeId, ['TITLE'])?->getTitle();
		$this->setTitle($title ?? Loc::getMessage('BIZPROC_STORAGE_ITEM_LIST_TITLE') ?? '');

		$this->addToolbarButtons();
		$this->loadGridData();
		$this->includeComponentTemplate();
	}

	protected function addToolbarButtons(): void
	{
		if (Loader::includeModule('ui'))
		{
			Toolbar::deleteFavoriteStar();
			$settingsMenu = (new Buttons\SettingsButton())->setMenu(
				[
					'items' => [
						[
							'text' => Loc::getMessage('BIZPROC_STORAGE_ITEM_STORAGE_DELETE') ?? '',
							'onclick' => new \Bitrix\UI\Buttons\JsCode(
								'BX.Bizproc.Component.StorageItemList.removeStorage(' . $this->storageTypeId . ');'
							)
						],
					],
				]
			);
			Toolbar::addButton($settingsMenu, ButtonLocation::RIGHT);
		}
	}

	protected function loadGridData(): void
	{
		try {
			$navigation = $this->getPageNavigation();
			$gridSort = $this->getGridOptions()->GetSorting([
				'sort' => ['CREATED_TIME' => 'desc'],
			]);
			$gridParams = new GridParams(
				pager: Pager::buildFromPageNavigation($navigation),
				filter: new StorageItemFilter(),
				sort: new StorageItemSort($gridSort['sort']),
			);

			$itemCollection = $this->storageItemProvider->getList($gridParams);
			$grid = [];
			if ($itemCollection)
			{
				$data = $itemCollection->toArray();
				$totalCount = $this->storageItemProvider->getCount(['STORAGE_ID' => $this->storageTypeId]);
				$navigation->setRecordCount($totalCount);
				$grid = $this->prepareGrid($data);
			}

			$this->arResult['grid'] = $grid;
		}
		catch (\Throwable $e)
		{
			$this->errorCollection->setError(new \Bitrix\Main\Error($e->getMessage()));
			$this->arResult['errors'] = $this->errorCollection->toArray();
		}
	}

	protected function getGridColumns(): array
	{
		$columns = [
			['id' => 'ID', 'name' => 'ID', 'default' => true, 'sort' => 'ID'],
			['id' => 'CODE', 'default' => false, 'sort' => 'CODE'],
			['id' => 'WORKFLOW_ID', 'default' => true],
			['id' => 'DOCUMENT_ID', 'default' => true],
			['id' => 'TEMPLATE_ID', 'default' => true, 'sort' => 'TEMPLATE_ID'],
			['id' => 'CREATED_BY', 'default' => true, 'sort' => 'CREATED_BY'],
			['id' => 'CREATED_TIME', 'default' => true, 'sort' => 'CREATED_TIME'],
			['id' => 'UPDATED_BY', 'default' => true, 'sort' => 'UPDATED_BY'],
			['id' => 'UPDATED_TIME', 'default' => true, 'sort' => 'UPDATED_TIME'],
		];

		$fields = $this->fieldService->getDynamicFields();
		if ($fields)
		{
			foreach ($fields as $field)
			{
				$columns[] = ['id' => strtoupper($field->getCode()), 'name' => $field->getName(), 'default' => true];
			}
		}

		$fields = StorageRecordTable::getEntity()->getFields();
		foreach ($fields as $field)
		{
			$key = array_search($field->getName(), array_column($columns, 'id'), true);
			if ($key !== false)
			{
				$columns[$key]['name'] = $field->getTitle();
			}
		}

		return $columns;
	}

	/**
	 * @param array $field
	 * @return array<string, mixed>
	 */
	protected function getFieldColumns(array $field): array
	{
		$fieldColumns = [
			'ID' => (int)$field['id'],
			'CODE' => htmlspecialcharsbx($field['code']),
			'WORKFLOW_ID' => htmlspecialcharsbx($field['workflowId']),
			'DOCUMENT_ID' => htmlspecialcharsbx($field['documentId']),
			'TEMPLATE_ID' => (int)$field['templateId'],
			'CREATED_BY' => htmlspecialcharsbx($field['createdBy']),
			'CREATED_TIME' => $field['createdAt'] ? DateTime::createFromTimestamp($field['createdAt']) : null,
			'UPDATED_BY' => htmlspecialcharsbx($field['updatedBy']),
			'UPDATED_TIME' => $field['updatedAt'] ? DateTime::createFromTimestamp($field['updatedAt']) : null
		];

		$fields = $this->fieldService->getDynamicFields();
		$documentService = CBPRuntime::GetRuntime(true)->getDocumentService();
		$documentType = ['bizproc', 'CBPVirtualDocument', 'type_0'];

		if ($fields)
		{
			foreach ($fields as $storageField)
			{
				$fieldProperties = $storageField->toProperty();
				$fieldType = $documentService->getFieldTypeObject($documentType, $fieldProperties);
				if (!$fieldType)
				{
					continue;
				}

				$code = $fieldProperties['FieldName'] ?? null;
				$fieldColumns[strtoupper($code)] = $fieldType->formatValue($field[$code] ?? null);
			}
		}

		return $fieldColumns;
	}

	protected function setTitle(string $title): void
	{
		global $APPLICATION;

		$APPLICATION->SetTitle($title);
	}

	protected function getPageNavigation(): PageNavigation
	{
		if (!$this->pageNavigation)
		{
			$gridOptions = $this->getGridOptions();
			$navParams = $gridOptions->getNavParams();
			$pageSize = $navParams['nPageSize'] ?? static::DEFAULT_PAGE_SIZE;

			$this->pageNavigation = new PageNavigation(static::NAV_PARAM_NAME);
			$this->pageNavigation->allowAllRecords(false)->setPageSize($pageSize)->initFromUri();
		}

		return $this->pageNavigation;
	}

	protected function getGridOptions(): Options
	{
		if (!$this->gridOptions)
		{
			$this->gridOptions = new Options($this->getGridId());
		}

		return $this->gridOptions;
	}

	protected function getGridId(): string
	{
		if (!$this->gridId)
		{
			$this->gridId =
				!empty($this->arParams['gridId'])
					? $this->arParams['gridId']
					: 'bizproc-storage-item-list' . $this->storageTypeId
			;
		}

		return $this->gridId;
	}

	/**
	 * @param array $fields
	 * @return array<string, mixed>
	 */
	protected function prepareGrid(array $fields): array
	{
		$grid = ['GRID_ID' => $this->getGridId(), 'ROWS' => []];

		if (!empty($fields))
		{
			$userIds = [];
			foreach ($fields as $field)
			{
				if ($field['createdBy'])
				{
					$userIds[] = (int)$field['createdBy'];
				}
				if ($field['updatedBy'])
				{
					$userIds[] = (int)$field['updatedBy'];
				}
			}

			$userNames = $this->getUserNames(array_unique($userIds));

			foreach ($fields as $key => $field)
			{
				$fields[$key]['createdBy'] = $userNames[$field['createdBy'] ?? 0] ?? '';
				$fields[$key]['updatedBy'] = $userNames[$field['updatedBy'] ?? 0] ?? '';
				$grid['ROWS'][] = [
					'id' => $field['id'],
					'data' => $field,
					'columns' => $this->getFieldColumns($fields[$key]),
				];
			}
		}

		$grid['COLUMNS'] = $this->getGridColumns();
		$grid['NAV_PARAM_NAME'] = static::NAV_PARAM_NAME;
		$grid['CURRENT_PAGE'] = $this->getPageNavigation()->getCurrentPage();
		$grid['NAV_OBJECT'] = $this->getPageNavigation();
		$grid['TOTAL_ROWS_COUNT'] = $this->getPageNavigation()->getRecordCount();
		$grid['AJAX_MODE'] = 'Y';
		$grid['ALLOW_ROWS_SORT'] = false;
		$grid['AJAX_OPTION_JUMP'] = 'N';
		$grid['AJAX_OPTION_STYLE'] = 'N';
		$grid['AJAX_OPTION_HISTORY'] = 'N';
		$grid['AJAX_ID'] = \CAjax::GetComponentID(
			'bitrix:main.ui.grid',
			'',
			''
		);
		$grid['SHOW_PAGESIZE'] = true;
		$grid['PAGE_SIZES'] = [
			['NAME' => '10', 'VALUE' => '10'],
			['NAME' => '20', 'VALUE' => '20'],
			['NAME' => '50', 'VALUE' => '50']
		];
		$grid['SHOW_ROW_CHECKBOXES'] = false;
		$grid['SHOW_CHECK_ALL_CHECKBOXES'] = false;
		$grid['SHOW_ACTION_PANEL'] = false;

		return $grid;
	}

	protected function getUserNames(array $userIds): array
	{
		if (empty($userIds))
		{
			return [];
		}

		$userNames = [];

		$result = \Bitrix\Main\UserTable::getList([
			'filter' => ['ID' => $userIds],
			'select' => ['ID', 'LOGIN', 'NAME', 'LAST_NAME', 'SECOND_NAME'],
		]);

		while ($user = $result->Fetch())
		{
			$userNames[(int)$user['ID']] = \CUser::FormatName(
				\CSite::GetNameFormat(false),
				[
					'LOGIN' => $user['LOGIN'] ?? '',
					'NAME' => $user['NAME'] ?? '',
					'LAST_NAME' => $user['LAST_NAME'] ?? '',
					'SECOND_NAME' => $user['SECOND_NAME'] ?? '',
				],
				true,
				false
			);
		}

		return $userNames;
	}
}
