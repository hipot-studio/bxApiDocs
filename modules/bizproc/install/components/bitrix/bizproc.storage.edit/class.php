<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

use Bitrix\Bizproc\Api\Enum\ErrorMessage;
use Bitrix\Bizproc\Public\Provider\Params\StorageType\StorageTypeFilter;
use Bitrix\Bizproc\Public\Provider\Params\StorageType\StorageTypeSort;
use Bitrix\Bizproc\Public\Provider\StorageTypeProvider;
use Bitrix\Main\Error;
use Bitrix\Main\ErrorCollection;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Application;
use Bitrix\Main\Provider\Params\GridParams;
use Bitrix\Main\Provider\Params\Pager;
use Bitrix\Main\UI\PageNavigation;
use Bitrix\Bizproc\Internal\Model\StorageTypeTable;
use Bitrix\Main\Loader;

class BizprocStorageEditComponent extends CBitrixComponent
{
	protected ErrorCollection $errorCollection;
	protected ?array $data = null;

	protected function init(): void
	{
		$this->errorCollection = new ErrorCollection();
		if (!$this->errorCollection->isEmpty())
		{
			return;
		}

		if (!Loader::includeModule('bizproc'))
		{
			$this->showError(Loc::getMessage('BIZPROC_STORAGE_EDIT_MODULE_NOT_INSTALLED') ?? '');

			return;
		}

		if (!(new CBPWorkflowTemplateUser(\CBPWorkflowTemplateUser::CurrentUser))->isAdmin())
		{
			$this->showError(ErrorMessage::ACCESS_DENIED->get());

			return;
		}

		try {
			$storageId = (int)(!empty($this->arParams['storageId'])
				? $this->arParams['storageId']
				: Application::getInstance()->getContext()->getRequest()->get('storageId'))
			;

			if ($storageId > 0)
			{
				$provider = new StorageTypeProvider();
				$this->data = $provider->getById($storageId)?->toArray();

				if (!$this->data)
				{
					$this->errorCollection[] = new Error(
						Loc::getMessage('BIZPROC_STORAGE_EDIT_FIELD_NOT_FOUND_ERROR')
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

		$this->arResult['storage'] = $this->prepareStorage();
		$this->arResult['form'] = $this->prepareForm();

		if ($this->arResult['storage']['id'] > 0)
		{
			$this->arResult['title'] = Loc::getMessage('BIZPROC_STORAGE_EDIT_TITLE_EDIT');
		}
		else
		{
			$this->arResult['title'] = Loc::getMessage('BIZPROC_STORAGE_EDIT_TITLE_ADD');
		}
		$this->setTitle($this->arResult['title']);

		$this->includeComponentTemplate();
	}

	protected function prepareStorage(): array
	{
		if (is_array($this->data))
		{
			return $this->data;
		}

		return [
			'id' => 0,
			'name' => '',
			'title' => '',
			'description' => '',
			'code' => '',
			'tableName' => '',
			'createdBy' => 0,
			'updatedBy' => 0,
			'createdAt' => 0,
			'updatedAt' => 0,
		];
	}

	protected function prepareForm(): array
	{
		$form = [];
		$fields = StorageTypeTable::getEntity()->getFields();

		$map = [
			'ID' => 'id',
			'NAME' => 'name',
			'TITLE' => 'title',
			'CODE' => 'code',
			'DESCRIPTION' => 'description',
			'TABLE_NAME' => 'tableName',
			'CREATED_BY' => 'createdBy',
			'UPDATED_BY' => 'updatedBy',
			'CREATED_TIME' => 'createdAt',
			'UPDATED_TIME' => 'updatedAt',
		];

		foreach ($fields as $field)
		{
			if (isset($map[$field->getName()]))
			{
				$form[$map[$field->getName()]] = [
					'label' => $field->getTitle(),
				];
			}
		}

		return $form;
	}

	protected function setUserNames(?array &$storages): void
	{
		if ($storages)
		{
			$userIds = [];
			foreach ($storages as $storage)
			{
				if (!empty($storage['createdBy']))
				{
					$userIds[] = $storage['createdBy'];
				}
				if (!empty($storage['updatedBy']))
				{
					$userIds[] = $storage['updatedBy'];
				}
			}

			$userIds = array_unique($userIds);

			$dbUsers = \Bitrix\Main\UserTable::getList([
				'filter' => ['ID' => $userIds],
				'select' => ['ID', 'LOGIN', 'NAME', 'LAST_NAME', 'SECOND_NAME'],
			]);
			$userIdToName = [];
			while ($row = $dbUsers->fetch())
			{
				$userIdToName[$row['ID']] = \CUser::FormatName(
					\CSite::GetNameFormat(false),
					[
						'LOGIN' => $row['LOGIN'] ?? '',
						'NAME' => $row['NAME'] ?? '',
						'LAST_NAME' => $row['LAST_NAME'] ?? '',
						'SECOND_NAME' => $row['SECOND_NAME'] ?? '',
					],
					true,
					false
				);
			}

			foreach ($storages as $key => $storage)
			{
				$storages[$key]['createdBy'] = $userIdToName[$storage['createdBy']] ?? '';
				$storages[$key]['updatedBy'] = $userIdToName[$storage['updatedBy']] ?? '';
			}
		}
	}

	protected function setTitle(string $title): void
	{
		global $APPLICATION;

		$APPLICATION->SetTitle($title);
	}

	private function showError(string $message)
	{
		$this->arResult['errorMessage'] = $message;
		$this->includeComponentTemplate('error');

		return null;
	}
}
