<?php

if (!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED !== true)
{
	die();
}

use Bitrix\Crm\Component\Base;
use Bitrix\Crm\Controller\ErrorCode;
use Bitrix\Crm\Import\Builder\DictionaryBuilder;
use Bitrix\Crm\Import\Builder\ImportSettingsConfigurator;
use Bitrix\Crm\Import\Dto\Entity\AbstractImportSettings;
use Bitrix\Crm\Import\Dto\UI\Dictionary;
use Bitrix\Crm\Import\Dto\UI\ImportOptions;
use Bitrix\Crm\Import\Factory\ImportEntityFactory;
use Bitrix\Crm\Service\Container;
use Bitrix\Main\Loader;

Loader::requireModule('crm');

final class CrmItemImportComponent extends Base
{
	private AbstractImportSettings $importSettings;
	private DictionaryBuilder $dictionaryBuilder;

	protected function init(): void
	{
		parent::init();

		$this->entityTypeId = $this->getEntityTypeIdFromParams();
		if (
			!CCrmOwnerType::IsDefined($this->entityTypeId)
			|| !Container::getInstance()->getUserPermissions()->entityType()->canImportItems($this->entityTypeId)
		)
		{
			$this->addError(ErrorCode::getAccessDeniedError());

			return;
		}

		$importSettings = $this->createImportSettings();
		if ($importSettings === null)
		{
			$this->addError(ErrorCode::getEntityTypeNotSupportedError($this->entityTypeId));

			return;
		}

		$this->importSettings = $importSettings;
		$this->dictionaryBuilder = new DictionaryBuilder();
	}

	public function executeComponent(): void
	{
		$this->init();
		if (!$this->errorCollection->isEmpty())
		{
			$this->showFirstErrorViaInfoErrorUI();

			return;
		}

		$this->arResult['importOptions'] = (new ImportOptions($this->entityTypeId))
			->setImportSettings($this->importSettings)
			->setDictionary($this->createDictionary())
		;

		$this->includeComponentTemplate();
	}

	private function createImportSettings(): ?AbstractImportSettings
	{
		$importSettings = (new ImportEntityFactory())->createImportSettings($this->entityTypeId);
		if ($importSettings === null)
		{
			return null;
		}

		(new ImportSettingsConfigurator($importSettings))
			->configureByRequest($this->request)
			->configureByImportSettingsOrigin()
		;

		return $importSettings;
	}

	private function createDictionary(): Dictionary
	{
		return $this->dictionaryBuilder->build($this->entityTypeId);
	}
}
