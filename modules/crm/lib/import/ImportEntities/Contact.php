<?php

namespace Bitrix\Crm\Import\ImportEntities;

use Bitrix\Crm\Address\Enum\FieldName;
use Bitrix\Crm\EntityAddressType;
use Bitrix\Crm\Import\Builder\CsvExampleFileBuilder;
use Bitrix\Crm\Import\Collection\FieldCollection;
use Bitrix\Crm\Import\Contract\ImportEntityFieldInterface;
use Bitrix\Crm\Import\Contract\ImportEntityInterface;
use Bitrix\Crm\Import\Contract\Strategy\FieldBindingMapperInterface;
use Bitrix\Crm\Import\Dto\Entity\AbstractImportSettings;
use Bitrix\Crm\Import\Dto\Entity\ImportSettings\ContactImportSettings;
use Bitrix\Crm\Import\Factory\ImportEntityFieldFactory;
use Bitrix\Crm\Import\Hook\PostSaveHooks\MultipleSaveAddress;
use Bitrix\Crm\Import\ImportEntityFields\Address;
use Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\AssignedById;
use Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\Birthdate;
use Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\Comments;
use Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\CompanyId;
use Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\Contact\Export;
use Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\Contact\Photo;
use Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\FullName;
use Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\Honorific;
use Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\Id;
use Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\LastName;
use Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\Name;
use Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\Opened;
use Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\Post;
use Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\SecondName;
use Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\SourceDescription;
use Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\SourceId;
use Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\TypeId;
use Bitrix\Crm\Import\Strategy\FieldBindingMapper\ByFieldName;
use Bitrix\Crm\Requisite\ImportHelper;
use Bitrix\Crm\Settings\ContactSettings;
use Bitrix\Main\Localization\Loc;
use CCrmOwnerType;
use CCrmStatus;

final class Contact implements
	ImportEntityInterface,
	ImportEntityInterface\HasPostSaveHooksInterface,
	ImportEntityInterface\HasExampleFileInterface
{
	private readonly ImportEntityFieldFactory $fieldFactory;
	private readonly ContactSettings $contactSettings;
	private ?FieldCollection $fieldCollection = null;

	private const EXAMPLE_FILENAME = 'contact.csv';

	public function __construct(
		private readonly ContactImportSettings $importSettings,
	)
	{
		$this->fieldFactory = new ImportEntityFieldFactory(\CCrmOwnerType::Contact);
		$this->contactSettings = ContactSettings::getCurrent();
	}

	public function getFields(): FieldCollection
	{
		if ($this->fieldCollection !== null)
		{
			return $this->fieldCollection;
		}

		$this->fieldCollection = new FieldCollection([
			new Id(CCrmOwnerType::Contact),
			new Honorific(CCrmOwnerType::Contact),
			new Name(CCrmOwnerType::Contact),
			new LastName(CCrmOwnerType::Contact),
			new SecondName(CCrmOwnerType::Contact),
			(new FullName(CCrmOwnerType::Contact))
				->configureNameFormat($this->importSettings->getNameFormat())
			,
			new Birthdate(CCrmOwnerType::Contact),
			new Photo(CCrmOwnerType::Contact),
			new CompanyId(CCrmOwnerType::Contact),
			(new AssignedById(CCrmOwnerType::Contact))
				->configureNameFormat($this->importSettings->getNameFormat())
			,
		]);

		if ($this->contactSettings->areOutmodedRequisitesEnabled())
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
			]);
		}

		$this->fieldCollection
			->merge($this->fieldFactory->getMultiFields())
			->merge($this->fieldFactory->getUserFields())
			->pushList([
				new Post(CCrmOwnerType::Contact),
				new Comments(CCrmOwnerType::Contact),
				new TypeId(CCrmOwnerType::Contact),
				new SourceId(CCrmOwnerType::Contact),
				new SourceDescription(CCrmOwnerType::Contact),
				new Export(CCrmOwnerType::Contact),
				new Opened(CCrmOwnerType::Contact),
			])
		;

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
	 * @return ContactImportSettings
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
		/**
		 * CRM_IMPORT_ENTITY_CONTACT_ALIAS_EXPORT
		 */
		$alias = Loc::getMessage("CRM_IMPORT_ENTITY_CONTACT_ALIAS_{$field->getId()}");
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
		$typeList = CCrmStatus::GetStatusListEx('CONTACT_TYPE');
		$sourceList = CCrmStatus::GetStatusListEx('SOURCE');

		$exampleRow = [
			'NAME' => Loc::getMessage('CRM_IMPORT_ENTITY_CONTACT_DEMO_DATA_NAME'),
			'LAST_NAME' => Loc::getMessage('CRM_IMPORT_ENTITY_CONTACT_DEMO_DATA_LAST_NAME'),
			'TYPE_ID' => $typeList['SUPPLIER'],
			'SOURCE_ID' => $sourceList['TRADE_SHOW'],
			'PHONE_MOBILE' => Loc::getMessage('CRM_IMPORT_ENTITY_CONTACT_DEMO_DATA_PHONE_MOBILE'),
			'EMAIL_WORK' => Loc::getMessage('CRM_IMPORT_ENTITY_CONTACT_DEMO_DATA_EMAIL_WORK'),
			'EXPORT' => Loc::getMessage('MAIN_YES'),
			'OPENED' => Loc::getMessage('MAIN_YES'),
		];

		if ($this->importSettings->getRequisiteOptions()->isImportRequisite())
		{
			$requisiteExampleRows = ImportHelper::GetRequisiteDemoData(
				entityTypeId: CCrmOwnerType::Contact,
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
