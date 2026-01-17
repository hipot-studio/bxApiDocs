<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

use Bitrix\Main\Localization\Loc;
use Bitrix\Bizproc\Public\Provider\StorageFieldProvider;
use Bitrix\Bizproc\Public\Provider\Params\StorageField\StorageFieldFilter;
use Bitrix\Bizproc\Public\Provider\Params\StorageField\StorageFieldSort;
use Bitrix\Main\Provider\Params\GridParams;
use Bitrix\Main\Provider\Params\Pager;
use Bitrix\Main\Loader;
use Bitrix\Bizproc\Api\Enum\ErrorMessage;
use Bitrix\UI\Toolbar\ButtonLocation;
use Bitrix\UI\Toolbar\Facade\Toolbar;
use Bitrix\UI\Buttons;
use Bitrix\Bizproc\Internal\Model\StorageFieldTable;
use Bitrix\Bizproc\FieldType;

CBitrixComponent::includeComponentClass('bitrix:main.field.config.list');

class BizprocStorageFieldListComponent extends MainUfListComponent
{
	protected const NAV_PARAM_NAME = 'field-list';
	protected int $storageTypeId = 0;
	protected StorageFieldProvider $provider;

	protected function init(): void
	{
		$this->errorCollection = new \Bitrix\Main\ErrorCollection();
		$request = \Bitrix\Main\Application::getInstance()->getContext()->getRequest();

		$this->storageTypeId = (int)($this->arParams['storageId'] ?? $request->get('storageId'));
		if (!$this->storageTypeId)
		{
			$this->errorCollection[] = new \Bitrix\Main\Error(
				Loc::getMessage('BIZPROC_STORAGE_FIELD_LIST_NO_STORAGE_TYPE_ID')
			);

			return;
		}

		if (!Loader::includeModule('bizproc'))
		{
			$this->errorCollection->setError(new \Bitrix\Main\Error(
				Loc::getMessage('BIZPROC_STORAGE_FIELD_LIST_MODULE_NOT_INSTALLED')
			));

			return;
		}

		if (!(new CBPWorkflowTemplateUser(\CBPWorkflowTemplateUser::CurrentUser))->isAdmin())
		{
			$this->errorCollection->setError(ErrorMessage::ACCESS_DENIED->getError());

			return;
		}

		$this->provider = new StorageFieldProvider();
	}

	public function executeComponent()
	{
		$this->setTitle($this->arParams['title'] ?? Loc::getMessage('BIZPROC_STORAGE_FIELD_LIST_TITLE') ?? '');

		$this->init();
		if (!$this->errorCollection->isEmpty())
		{
			$this->arResult['errors'] = $this->errorCollection->toArray();
			$this->includeComponentTemplate();

			return;
		}

		$this->addToolbarButtons();
		$this->loadGridData();
		$this->includeComponentTemplate();
	}

	protected function addToolbarButtons(): void
	{
		if (Loader::includeModule('ui'))
		{
			Toolbar::deleteFavoriteStar();
			$createButton = new Buttons\CreateButton([
				'tag' => Buttons\Tag::LINK,
				'click' => new Buttons\JsCode(
					"BX.StorageFieldList({$this->storageTypeId});"
				),
				'color' => Buttons\Color::PRIMARY,
			]);
			Toolbar::addButton($createButton, ButtonLocation::AFTER_TITLE);
		}
	}

	protected function loadGridData(): void
	{
		try {
			$navigation = $this->getPageNavigation();
			$gridSort = $this->getGridOptions()->GetSorting([
				'sort' => $this->getDefaultSort(),
			]);
			$gridParams = new GridParams(
				pager: Pager::buildFromPageNavigation($navigation),
				filter: new StorageFieldFilter(['STORAGE_ID' => $this->storageTypeId]),
				sort: new StorageFieldSort($gridSort['sort']),
			);

			$fieldCollection = $this->provider->getList($gridParams);
			$data = $fieldCollection->toArray();
			$totalCount = $this->provider->getCount(['STORAGE_ID' => $this->storageTypeId]);
			$navigation->setRecordCount($totalCount);

			$this->arResult['grid'] = $this->prepareGrid($data);
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
			['id' => 'NAME', 'default' => true, 'sort' => 'NAME'],
			['id' => 'DESCRIPTION', 'default' => true],
			['id' => 'TYPE', 'default' => true, 'sort' => 'TYPE'],
			['id' => 'SORT', 'default' => true, 'sort' => 'SORT'],
			['id' => 'MULTIPLE', 'default' => true, 'sort' => 'MULTIPLE'],
			['id' => 'MANDATORY', 'default' => true, 'sort' => 'MANDATORY'],
		];

		$fields = StorageFieldTable::getEntity()->getFields();

		foreach ($fields as $field)
		{
			$key = array_search($field->getName(), array_column($columns, 'id'), true);
			if ($key)
			{
				$columns[$key]['name'] = $field->getTitle();
			}
		}

		return $columns;
	}

	protected function getFieldColumns(array $field): array
	{
		$documentTypes = \CBPHelper::GetDocumentFieldTypes();
		$id = (int)$field['id'];

		return [
			'ID' => (int)$field['id'],
			'CODE' => "<a onclick=\"BX.StorageFieldList({$this->storageTypeId}, {$id});\">".htmlspecialcharsbx($field['code'])."</a>",
			'NAME' => "<a onclick=\"BX.StorageFieldList({$this->storageTypeId}, {$id});\">".htmlspecialcharsbx($field['name'])."</a>",
			'DESCRIPTION' => htmlspecialcharsbx($field['description']),
			'TYPE' => htmlspecialcharsbx($documentTypes[$field['type']]['Name'] ?? ''),
			'SORT' => (int)$field['sort'],
			'MULTIPLE' => htmlspecialcharsbx(
				$field['multiple']
					? Loc::getMessage('BIZPROC_STORAGE_FIELD_LIST_BOOLEAN_YES')
					: Loc::getMessage('BIZPROC_STORAGE_FIELD_LIST_BOOLEAN_NO')
			),
			'MANDATORY' => htmlspecialcharsbx(
				$field['mandatory']
					? Loc::getMessage('BIZPROC_STORAGE_FIELD_LIST_BOOLEAN_YES')
					: Loc::getMessage('BIZPROC_STORAGE_FIELD_LIST_BOOLEAN_NO')
			),
		];
	}

	protected function getListFilter(): array
	{
		return [];
	}

	protected function getUserTypes(): array
	{
		if (!$this->userTypes)
		{
			global $USER_FIELD_MANAGER;
			$userTypes = $USER_FIELD_MANAGER->GetUserType();

			foreach ($userTypes as $typeId => $type)
			{
				$fieldTypeClass = Factory::getFieldTypeClass($typeId);
				if (!$fieldTypeClass)
				{
					unset($userTypes[$typeId]);
				}
			}

			$this->userTypes = $userTypes;
		}

		return $this->userTypes;
	}
}
