<?php

namespace Bitrix\Crm\Import\ImportEntities;

use Bitrix\Crm\Import\Collection\FieldCollection;
use Bitrix\Crm\Import\Contract\ImportEntityFieldInterface;
use Bitrix\Crm\Import\Dto\Entity\AbstractImportSettings;
use Bitrix\Crm\Import\Dto\Entity\ImportSettings\SmartInvoiceImportSettings;
use Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\Comments;
use Bitrix\Crm\Item;
use Bitrix\Main\Localization\Loc;

final class SmartInvoice extends Dynamic
{
	private const EXAMPLE_FILENAME = 'smart_invoice.csv';

	public function __construct(
		private readonly SmartInvoiceImportSettings $importSettings,
	)
	{
		parent::__construct($importSettings);
	}

	public function getFields(): FieldCollection
	{
		if ($this->fieldCollection !== null)
		{
			return $this->fieldCollection;
		}

		$this->fieldCollection = parent::getFields();
		$this->fieldCollection->push(new Comments($this->entityTypeId));

		return $this->fieldCollection;
	}

	/**
	 * @return SmartInvoiceImportSettings
	 */
	public function getSettings(): AbstractImportSettings
	{
		return $this->importSettings;
	}

	protected function getExampleRows(): array
	{
		return [
			[
				Item::FIELD_NAME_TITLE => Loc::getMessage('CRM_IMPORT_ENTITY_SMART_INVOICE_DEMO_DATA_TITLE'),
				Item::FIELD_NAME_COMPANY_ID => Loc::getMessage('CRM_IMPORT_ENTITY_SMART_INVOICE_DEMO_DATA_COMPANY_ID'),
				Item::FIELD_NAME_CONTACT_ID => Loc::getMessage('CRM_IMPORT_ENTITY_SMART_INVOICE_DEMO_DATA_CONTACT_ID'),
				Item::FIELD_NAME_STAGE_ID => Loc::getMessage('CRM_IMPORT_ENTITY_SMART_INVOICE_DEMO_DATA_STAGE_ID'),
			],
		];
	}

	protected function getExampleFilename(): string
	{
		return self::EXAMPLE_FILENAME;
	}

	protected function getAliases(ImportEntityFieldInterface $field): array
	{
		/**
		 * CRM_IMPORT_ENTITY_SMART_INVOICE_ALIAS_TITLE
		 * CRM_IMPORT_ENTITY_SMART_INVOICE_ALIAS_CREATED_TIME
		 * CRM_IMPORT_ENTITY_SMART_INVOICE_ALIAS_UPDATED_TIME
		 * CRM_IMPORT_ENTITY_SMART_INVOICE_ALIAS_CLOSEDATE
		 * CRM_IMPORT_ENTITY_SMART_INVOICE_ALIAS_MOVED_TIME
		 * CRM_IMPORT_ENTITY_SMART_INVOICE_ALIAS_STAGE_ID
		 */
		$alias = Loc::getMessage("CRM_IMPORT_ENTITY_SMART_INVOICE_ALIAS_{$field->getId()}");
		if ($alias === null)
		{
			return [];
		}

		return [
			$alias,
		];
	}
}
