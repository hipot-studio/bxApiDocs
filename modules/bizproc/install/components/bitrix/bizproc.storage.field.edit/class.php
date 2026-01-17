<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

use Bitrix\Bizproc\Api\Enum\ErrorMessage;
use Bitrix\Bizproc\Internal\Entity\StorageField\StorageField;
use Bitrix\Bizproc\Public\Provider\StorageFieldProvider;
use Bitrix\Main\Error;
use Bitrix\Main\ErrorCollection;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Application;
use Bitrix\Bizproc\Internal\Model\StorageFieldTable;
use Bitrix\Bizproc\Internal\Repository\Mapper\StorageFieldMapper;
use Bitrix\Main\Loader;
use Bitrix\Bizproc\FieldType;

class BizprocStorageFieldEditComponent extends CBitrixComponent
{
	protected ErrorCollection $errorCollection;
	protected ?array $data = null;
	protected ?StorageField $field = null;

	protected function init(): void
	{
		$this->errorCollection = new ErrorCollection();
		if (!$this->errorCollection->isEmpty())
		{
			return;
		}

		if (!Loader::includeModule('bizproc'))
		{
			$this->errorCollection->setError(new Error(
					Loc::getMessage('BIZPROC_STORAGE_FIELD_EDIT_NOT_INSTALLED') ?? '')
			);

			return;
		}

		if (!(new CBPWorkflowTemplateUser(\CBPWorkflowTemplateUser::CurrentUser))->isAdmin())
		{
			$this->errorCollection->setError(ErrorMessage::ACCESS_DENIED->getError());

			return;
		}

		try {
			$this->arParams['skipSave'] = (bool)($this->arParams['skipSave']
				?? Application::getInstance()->getContext()->getRequest()->get('skipSave'))
			;
			$fieldId = (int)(!empty($this->arParams['fieldId'])
				? $this->arParams['fieldId']
				: Application::getInstance()->getContext()->getRequest()->get('fieldId'))
			;
			$storageId = (int)(!empty($this->arParams['storageId'])
				? $this->arParams['storageId']
				: Application::getInstance()->getContext()->getRequest()->get('storageId'))
			;
			if (!$this->arParams['skipSave'] && !$storageId)
			{
				$this->errorCollection[] = new Error(
					Loc::getMessage('BIZPROC_STORAGE_EDIT_FIELD_NOT_FOUND_STORAGE')
				);
			}

			if ($fieldId > 0)
			{
				$provider = new StorageFieldProvider();
				$this->field = $provider->getById($fieldId);
				if ($this->field)
				{
					$this->data = $this->field->toArray();
				}

				if (!$this->data)
				{
					$this->errorCollection[] = new Error(
						Loc::getMessage('BIZPROC_STORAGE_FIELD_EDIT_STORAGE_NOT_FOUND_ERROR')
					);
				}
			}

			if (Loader::includeModule('ui'))
			{
				\Bitrix\UI\Toolbar\Facade\Toolbar::deleteFavoriteStar();
			}
		}
		catch (\Throwable $e)
		{
			$this->errorCollection[] = new Error($e->getMessage());
		}
	}

	public function executeComponent(): void
	{
		$this->init();
		if (!$this->errorCollection->isEmpty())
		{
			$this->arResult['errors'] = $this->errorCollection->toArray();
			$this->includeComponentTemplate();

			return;
		}


		$this->arResult['types'] = $this->getFieldsTypes();
		$this->arResult['field'] = $this->prepareField();
		$this->arResult['form'] = $this->prepareForm();

		if ($this->arResult['field']['id'] > 0)
		{
			$this->arResult['title'] = Loc::getMessage('BIZPROC_STORAGE_FIELD_EDIT_TITLE_EDIT') ?? '';
		}
		else
		{
			$this->arResult['title'] = Loc::getMessage('BIZPROC_STORAGE_FIELD_EDIT_TITLE_ADD') ?? '';
		}
		$this->setTitle($this->arResult['title']);

		$this->includeComponentTemplate();
	}

	protected function prepareField(): array
	{
		if (is_array($this->data))
		{
			return $this->data;
		}

		return [
			'id' => 0,
			'storageId' => 0,
			'code' => 'storage' . floor(microtime(true) * 1000),
			'sort' => 500,
			'name' => Loc::getMessage('BIZPROC_STORAGE_FIELD_EDIT_TITLE_ADD') ?? '',
			'description' => '',
			'type' => 'string',
			'multiple' => false,
			'mandatory' => false,
			'settings' => null,
		];
	}

	protected function prepareForm(): array
	{
		$form = [];
		$fields = StorageFieldTable::getEntity()->getFields();

		$map = StorageFieldMapper::getFieldsMap();

		foreach ($fields as $field)
		{
			$form[$map[$field->getName()]] = [
				'label' => $field->getTitle(),
			];
		}

		return $form;
	}

	protected function setTitle(string $title): void
	{
		global $APPLICATION;

		$APPLICATION->SetTitle($title);
	}

	private function getFieldsTypes(): array
	{
		$baseTypes = FieldType::getBaseTypesMap();
		unset($baseTypes[FieldType::INTERNALSELECT], $baseTypes[FieldType::FILE], $baseTypes[FieldType::SELECT]);

		$documentTypes = \CBPHelper::GetDocumentFieldTypes();

		$fieldTypes = [];

		foreach ($documentTypes as $key => $value)
		{
			if (!isset($baseTypes[$key]))
			{
				continue;
			}

			$fieldTypes[$key] = $value['Name'];
		}

		return $fieldTypes;
	}
}
