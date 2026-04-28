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
use Bitrix\Crm\Import\Dto\Entity\ImportSettings\LeadImportSettings;
use Bitrix\Crm\Import\Factory\ImportEntityFieldFactory;
use Bitrix\Crm\Import\Hook\PostSaveHooks\MultipleSaveAddress;
use Bitrix\Crm\Import\ImportEntityFields\Address;
use Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\AssignedById;
use Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\Birthdate;
use Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\Comments;
use Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\CreatedBy;
use Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\CreatedTime;
use Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\CurrencyId;
use Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\FullName;
use Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\Honorific;
use Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\Id;
use Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\LastName;
use Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\Lead\CompanyName;
use Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\Lead\StageDescription;
use Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\Name;
use Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\Opened;
use Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\Opportunity;
use Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\Post;
use Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\SecondName;
use Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\SourceDescription;
use Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\SourceId;
use Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\Stage\MovedBy;
use Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\Stage\MovedTime;
use Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\Stage\StageId;
use Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\Title;
use Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\UpdatedBy;
use Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\UpdatedTime;
use Bitrix\Crm\Import\ImportEntityFields\Product\ProductId;
use Bitrix\Crm\Import\ImportEntityFields\Product\ProductPrice;
use Bitrix\Crm\Import\ImportEntityFields\Product\ProductQuantity;
use Bitrix\Crm\Import\Strategy\FieldBindingMapper\ByFieldName;
use Bitrix\Crm\Service\Container;
use Bitrix\Main\Localization\Loc;
use CCrmOwnerType;
use CCrmProduct;
use CCrmStatus;

final class Lead implements
	ImportEntityInterface,
	ImportEntityInterface\HasPostSaveHooksInterface,
	ImportEntityInterface\HasExampleFileInterface
{
	private readonly ImportEntityFieldFactory $fieldFactory;
	private ?FieldCollection $fieldCollection = null;

	private const EXAMPLE_FILENAME = 'lead.csv';

	public function __construct(
		private readonly LeadImportSettings $importSettings,
	)
	{
		$this->fieldFactory = new ImportEntityFieldFactory(CCrmOwnerType::Lead);
	}

	public function getFields(): FieldCollection
	{
		if ($this->fieldCollection !== null)
		{
			return $this->fieldCollection;
		}

		$this->fieldCollection = new FieldCollection([
			new Id(CCrmOwnerType::Lead),
			new Title(CCrmOwnerType::Lead),
			new Honorific(CCrmOwnerType::Lead),
			new Name(CCrmOwnerType::Lead),
			new LastName(CCrmOwnerType::Lead),
			new SecondName(CCrmOwnerType::Lead),
			(new FullName(CCrmOwnerType::Lead))
				->configureNameFormat($this->importSettings->getNameFormat())
			,
			new Birthdate(CCrmOwnerType::Lead),

			new Address(type: EntityAddressType::Primary, id: FieldName::FULL_ADDRESS),
			new Address(type: EntityAddressType::Primary, id: FieldName::ADDRESS_1),
			new Address(type: EntityAddressType::Primary, id: FieldName::ADDRESS_2),
			new Address(type: EntityAddressType::Primary, id: FieldName::CITY),
			new Address(type: EntityAddressType::Primary, id: FieldName::REGION),
			new Address(type: EntityAddressType::Primary, id: FieldName::PROVINCE),
			new Address(type: EntityAddressType::Primary, id: FieldName::POSTAL_CODE),
			new Address(type: EntityAddressType::Primary, id: FieldName::COUNTRY),
		]);

		$this->fieldCollection->merge($this->fieldFactory->getMultiFields());

		$this->fieldCollection->pushList([
			new CompanyName(CCrmOwnerType::Lead),
			new Post(CCrmOwnerType::Lead),
			new Comments(CcrmOwnerType::Lead),
			new StageId(CCrmOwnerType::Lead),
			new StageDescription(CCrmOwnerType::Lead),
			new ProductId(),
			new ProductPrice(),
			new ProductQuantity(),
			new Opportunity(CCrmOwnerType::Lead),
			new CurrencyId(CCrmOwnerType::Lead),
			new SourceId(CCrmOwnerType::Lead),
			new SourceDescription(CCrmOwnerType::Lead),
			new Opened(CCrmOwnerType::Lead),
			(new AssignedById(CCrmOwnerType::Lead))
				->configureNameFormat($this->importSettings->getNameFormat())
			,
		]);

		if (Container::getInstance()->getUserPermissions()->isAdminForEntity(CCrmOwnerType::Lead))
		{
			$this->fieldCollection->pushList([
				(new CreatedTime(CCrmOwnerType::Lead)),
				(new CreatedBy(CCrmOwnerType::Lead))
					->configureNameFormat($this->importSettings->getNameFormat())
				,

				(new UpdatedTime(CCrmOwnerType::Lead)),
				(new UpdatedBy(CCrmOwnerType::Lead))
					->configureNameFormat($this->importSettings->getNameFormat())
				,

				(new MovedTime(CCrmOwnerType::Lead)),
				(new MovedBy(CCrmOwnerType::Lead))
					->configureNameFormat($this->importSettings->getNameFormat())
				,
			]);
		}

		$this->fieldCollection->merge($this->fieldFactory->getUtmFields());
		$this->fieldCollection->merge($this->fieldFactory->getUserFields());

		return $this->fieldCollection;
	}

	/**
	 * @return LeadImportSettings
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
		 * CRM_IMPORT_ENTITY_LEAD_ALIAS_TITLE
		 * CRM_IMPORT_ENTITY_LEAD_ALIAS_CREATED_TIME
		 * CRM_IMPORT_ENTITY_LEAD_ALIAS_UPDATED_TIME
		 * CRM_IMPORT_ENTITY_LEAD_ALIAS_UPDATED_BY
		 */
		$alias = Loc::getMessage("CRM_IMPORT_ENTITY_LEAD_ALIAS_{$field->getId()}");
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
		$stageList = CCrmStatus::GetStatusListEx('STATUS');
		$sourceList = CCrmStatus::GetStatusListEx('SOURCE');

		$exampleRow = [
			'TITLE' => Loc::getMessage('CRM_IMPORT_ENTITY_LEAD_DEMO_DATA_TITLE'),
			'NAME' => Loc::getMessage('CRM_IMPORT_ENTITY_LEAD_DEMO_DATA_NAME'),
			'LAST_NAME' => Loc::getMessage('CRM_IMPORT_ENTITY_LEAD_DEMO_DATA_LAST_NAME'),
			'POST' => Loc::getMessage('CRM_IMPORT_ENTITY_LEAD_DEMO_DATA_POST'),
			'STAGE_ID' => $stageList['NEW'],
			'SOURCE_ID' => $sourceList['PARTNER'],
			'OPENED' => Loc::getMessage('MAIN_YES'),
			'EMAIL_HOME' => Loc::getMessage('CRM_IMPORT_ENTITY_LEAD_DEMO_DATA_EMAIL_HOME')
		];

		$product = CCrmProduct::GetByOriginID('CRM_DEMO_PRODUCT_BX_CMS');
		if (is_array($product))
		{
			$exampleRow['PRODUCT_ID'] = $product['~NAME'];
			$exampleRow['PRODUCT_PRICE'] = $exampleRow['OPPORTUNITY'] = $product['~PRICE'];
			$exampleRow['CURRENCY_ID'] = $product['~CURRENCY_ID'];
			$exampleRow['PRODUCT_QUANTITY'] = Loc::getMessage('CRM_IMPORT_ENTITY_LEAD_DEMO_DATA_PRODUCT_QUANTITY');

			return [
				$exampleRow,
			];
		}

		$exampleRow['OPPORTUNITY'] = Loc::getMessage('CRM_IMPORT_ENTITY_LEAD_DEMO_DATA_OPPORTUNITY');
		$exampleRow['CURRENCY_ID'] = Loc::getMessage('CRM_IMPORT_ENTITY_LEAD_DEMO_DATA_CURRENCY_ID');

		return [
			$exampleRow,
		];
	}
}
