<?php

namespace Bitrix\Crm\Import\ImportEntities;

use Bitrix\Crm\Address\Enum\FieldName;
use Bitrix\Crm\EntityAddress;
use Bitrix\Crm\EntityAddressType;
use Bitrix\Crm\Import\Builder\CsvExampleFileBuilder;
use Bitrix\Crm\Import\Collection\FieldCollection;
use Bitrix\Crm\Import\Contract\ImportEntityFieldInterface;
use Bitrix\Crm\Import\Contract\ImportEntityInterface;
use Bitrix\Crm\Import\Contract\Strategy\FieldBindingMapperInterface;
use Bitrix\Crm\Import\Dto\Entity\AbstractImportSettings;
use Bitrix\Crm\Import\Dto\Entity\ImportSettings\CompanyImportSettings;
use Bitrix\Crm\Import\Factory\ImportEntityFieldFactory;
use Bitrix\Crm\Import\Hook\PostSaveHooks\MultipleSaveAddress;
use Bitrix\Crm\Import\ImportEntityFields\Address;
use Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\AssignedById;
use Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\Comments;
use Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\Company\BankingDetails;
use Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\Company\Employees;
use Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\Company\Industry;
use Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\Company\IsMyCompany;
use Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\Company\Logo;
use Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\Company\Revenue;
use Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\CreatedBy;
use Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\CreatedTime;
use Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\CurrencyId;
use Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\Id;
use Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\Opened;
use Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\Title;
use Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\TypeId;
use Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\UpdatedBy;
use Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\UpdatedTime;
use Bitrix\Crm\Import\Strategy\FieldBindingMapper\ByFieldName;
use Bitrix\Crm\Requisite\ImportHelper;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Settings\CompanySettings;
use Bitrix\Main\Localization\Loc;
use CCrmOwnerType;
use CCrmStatus;

final class Company implements
	ImportEntityInterface,
	ImportEntityInterface\HasPostSaveHooksInterface,
	ImportEntityInterface\HasExampleFileInterface
{
	private readonly ImportEntityFieldFactory $fieldFactory;
	private ?FieldCollection $fieldCollection = null;
	private CompanySettings $companySettings;

	private const EXAMPLE_FILENAME = 'company.csv';

	public function __construct(
		private readonly CompanyImportSettings $importSettings,
	)
	{
		$this->fieldFactory = new ImportEntityFieldFactory(CCrmOwnerType::Company);
		$this->companySettings = CompanySettings::getCurrent();
	}

	public function getFields(): FieldCollection
	{
		if ($this->fieldCollection !== null)
		{
			return $this->fieldCollection;
		}

		$this->fieldCollection = new FieldCollection([
			new Id(CCrmOwnerType::Company),
			new Title(CCrmOwnerType::Company),
			new Logo(CCrmOwnerType::Company),
			new TypeId(CCrmOwnerType::Company),
			new Industry(CCrmOwnerType::Company),
			new Employees(CCrmOwnerType::Company),
			new Revenue(CCrmOwnerType::Company),
			new CurrencyId(CCrmOwnerType::Company),
			new Comments(CCrmOwnerType::Company),

			(new AssignedById(CCrmownerType::Company))
				->configureNameFormat($this->importSettings->getNameFormat())
			,
		]);

		if ($this->companySettings->areOutmodedRequisitesEnabled())
		{
			$this->fieldCollection->pushList([
				new Address(type: EntityAddressType::Primary, id: FieldName::FULL_ADDRESS),
				new Address(type: EntityAddressType::Primary, id: FieldName::ADDRESS_1),
				new Address(type: EntityAddressType::Primary, id: FieldName::ADDRESS_2),
				new Address(type: EntityAddressType::Primary, id: FieldName::CITY),
				new Address(type: EntityAddressType::Primary, id: FieldName::REGION),
				new Address(type: EntityAddressType::Primary, id: FieldName::PROVINCE),
				new Address(type: EntityAddressType::Primary, id: FieldName::POSTAL_CODE),
				new Address(type: EntityAddressType::Primary, id: FieldName::COUNTRY),

				new Address(type: EntityAddressType::Registered, id: FieldName::FULL_ADDRESS),
				new Address(type: EntityAddressType::Registered, id: FieldName::ADDRESS_1),
				new Address(type: EntityAddressType::Registered, id: FieldName::ADDRESS_2),
				new Address(type: EntityAddressType::Registered, id: FieldName::CITY),
				new Address(type: EntityAddressType::Registered, id: FieldName::REGION),
				new Address(type: EntityAddressType::Registered, id: FieldName::PROVINCE),
				new Address(type: EntityAddressType::Registered, id: FieldName::POSTAL_CODE),
				new Address(type: EntityAddressType::Registered, id: FieldName::COUNTRY),
			]);
		}

		$this->fieldCollection->pushList([
			new BankingDetails(CCrmOwnerType::Company),
			new Opened(CCrmOwnerType::Company),
			new IsMyCompany(CCrmOwnerType::Company),
		]);

		if (Container::getInstance()->getUserPermissions()->isAdminForEntity(CCrmOwnerType::Company))
		{
			$this->fieldCollection->pushList([
				(new CreatedTime(CCrmownerType::Company)),
				(new CreatedBy(CCrmownerType::Company))
					->configureNameFormat($this->importSettings->getNameFormat()),

				(new UpdatedTime(CCrmownerType::Company)),
				(new UpdatedBy(CCrmownerType::Company))
					->configureNameFormat($this->importSettings->getNameFormat()),
			]);
		}

		$this->fieldCollection->merge($this->fieldFactory->getMultiFields());
		$this->fieldCollection->merge($this->fieldFactory->getUserFields());
		$this->fieldCollection->merge($this->fieldFactory->getUtmFields());

		if ($this->importSettings->getRequisiteOptions()->isImportRequisite())
		{
			$options = [];
			if (!$this->importSettings->getRequisiteOptions()->isRequisitePresetAssociate())
			{
				$options['PRESET_IDS'] = [
					$this->importSettings->getRequisiteOptions()->getDefaultRequisitePresetId(),
				];
			}

			$this->fieldCollection->merge($this->fieldFactory->getRequisiteFields($options));
		}

		return $this->fieldCollection;
	}

	/**
	 * @return CompanyImportSettings
	 */
	public function getSettings(): AbstractImportSettings
	{
		return $this->importSettings;
	}

	public function getPostSaveHooks(): array
	{
		return [
			new MultipleSaveAddress(),
		];
	}

	public function getFieldBindingMapper(): FieldBindingMapperInterface
	{
		return new ByFieldName($this->getFields(), $this->getAliases(...));
	}

	private function getAliases(ImportEntityFieldInterface $field): array
	{
		if ($field instanceof Address)
		{
			if ($field->getId() === FieldName::FULL_ADDRESS)
			{
				return [
					EntityAddress::getFullAddressLabel($field->getAddressType()),
				];
			}

			return [
				EntityAddress::getShortLabel($field->getAddressFieldId(), $field->getAddressType()),
			];
		}

		/**
		 * CRM_IMPORT_ENTITY_COMPANY_ALIAS_TITLE
		 * CRM_IMPORT_ENTITY_COMPANY_ALIAS_CREATED_TIME
		 * CRM_IMPORT_ENTITY_COMPANY_ALIAS_UPDATED_BY
		 * CRM_IMPORT_ENTITY_COMPANY_ALIAS_UPDATED_TIME
		 */
		$alias = Loc::getMessage("CRM_IMPORT_ENTITY_COMPANY_ALIAS_{$field->getId()}");
		if ($alias === null)
		{
			return [];
		}

		return [
			$alias,
		];
	}

	public function getExampleFilePath(): string
	{
		return (new CsvExampleFileBuilder())
			->setFilename(self::EXAMPLE_FILENAME)
			->setFields($this->getFields())
			->setExampleRows($this->getExampleRows())
			->build();
	}

	private function getExampleRows(): array
	{
		$typeList = CCrmStatus::GetStatusListEx('COMPANY_TYPE');
		$industryList = CCrmStatus::GetStatusListEx('INDUSTRY');
		$employeeList = CCrmStatus::GetStatusListEx('EMPLOYEES');

		$exampleRow = [
			'TITLE' => Loc::getMessage('CRM_IMPORT_ENTITY_COMPANY_DEMO_DATA_TITLE'),
			'COMPANY_TYPE' => $typeList['CUSTOMER'],
			'INDUSTRY' => $industryList['IT'],
			'EMPLOYEES' => $employeeList['EMPLOYEES_1'],
			'REVENUE' => Loc::getMessage('CRM_IMPORT_ENTITY_COMPANY_DEMO_DATA_REVENUE'),
			'CURRENCY_ID' => Loc::getMessage('CRM_IMPORT_ENTITY_COMPANY_DEMO_DATA_CURRENCY_ID'),
			'PHONE_WORK' => Loc::getMessage('CRM_IMPORT_ENTITY_COMPANY_DEMO_DATA_PHONE_WORK'),
			'EMAIL_WORK' => Loc::getMessage('CRM_IMPORT_ENTITY_COMPANY_DEMO_DATA_EMAIL_WORK'),
			'OPENED' => Loc::getMessage('MAIN_YES'),
			'IS_MY_COMPANY' => Loc::getMessage('MAIN_NO'),
		];

		if ($this->importSettings->getRequisiteOptions()->isImportRequisite())
		{
			$requisiteExampleRows = ImportHelper::GetRequisiteDemoData(
				entityTypeId: CCrmOwnerType::Company,
				exportHeaders: $this->getFields()->toArray(),
				presetId: $this->importSettings->getRequisiteOptions()->getDefaultRequisitePresetId(),
			);

			$requisiteExampleRows[0] = array_merge($requisiteExampleRows[0], $exampleRow);

			return $requisiteExampleRows;
		}

		return [
			$exampleRow,
		];
	}
}
