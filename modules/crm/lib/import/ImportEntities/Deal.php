<?php

namespace Bitrix\Crm\Import\ImportEntities;

use Bitrix\Crm\Import\Builder\CsvExampleFileBuilder;
use Bitrix\Crm\Import\Collection\FieldCollection;
use Bitrix\Crm\Import\Contract\ImportEntityFieldInterface;
use Bitrix\Crm\Import\Contract\ImportEntityInterface;
use Bitrix\Crm\Import\Contract\Strategy\FieldBindingMapperInterface;
use Bitrix\Crm\Import\Dto\Entity\AbstractImportSettings;
use Bitrix\Crm\Import\Dto\Entity\ImportSettings\DealImportSettings;
use Bitrix\Crm\Import\Factory\ImportEntityFieldFactory;
use Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\AssignedById;
use Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\Begindate;
use Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\CategoryId;
use Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\Closed;
use Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\Closedate;
use Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\Comments;
use Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\CompanyId;
use Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\ContactId;
use Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\CreatedBy;
use Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\CreatedTime;
use Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\CurrencyId;
use Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\Deal\Probability;
use Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\Id;
use Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\Opened;
use Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\Opportunity;
use Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\SourceDescription;
use Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\SourceId;
use Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\Stage\MovedBy;
use Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\Stage\MovedTime;
use Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\Stage\StageId;
use Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\Title;
use Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\TypeId;
use Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\UpdatedBy;
use Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\UpdatedTime;
use Bitrix\Crm\Import\ImportEntityFields\Product\ProductId;
use Bitrix\Crm\Import\ImportEntityFields\Product\ProductPrice;
use Bitrix\Crm\Import\ImportEntityFields\Product\ProductQuantity;
use Bitrix\Crm\Import\Strategy\FieldBindingMapper\ByFieldName;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\Factory;
use Bitrix\Main\Localization\Loc;
use CCrmOwnerType;
use CCrmProduct;
use CCrmStatus;

final class Deal implements ImportEntityInterface, ImportEntityInterface\HasExampleFileInterface
{
	private readonly ImportEntityFieldFactory $fieldFactory;
	private ?FieldCollection $fieldCollection = null;
	private readonly Factory $factory;

	private const EXAMPLE_FILENAME = 'deal.csv';

	public function __construct(
		private readonly DealImportSettings $importSettings,
	)
	{
		$this->fieldFactory = new ImportEntityFieldFactory(CCrmOwnerType::Deal);
		$this->factory = Container::getInstance()->getFactory(CCrmOwnerType::Deal);
	}

	public function getFields(): FieldCollection
	{
		if ($this->fieldCollection !== null)
		{
			return $this->fieldCollection;
		}

		$defaultCategoryId = $this->importSettings->getCategoryId()
			?? $this->factory?->getDefaultCategory()?->getId()
		;

		$this->fieldCollection = new FieldCollection([
			new Id(CCrmOwnerType::Deal),
			new Title(CCrmOwnerType::Deal),
			new Probability(CCrmOwnerType::Deal),
			new CompanyId(CCrmOwnerType::Deal),
			new ContactId(CCrmOwnerType::Deal),
			new Opportunity(CCrmOwnerType::Deal),
			new CurrencyId(CCrmOwnerType::Deal),
			new SourceId(CCrmOwnerType::Deal),
			new SourceDescription(CCrmOwnerType::Deal),
			new ProductId(),
			new ProductPrice(),
			new ProductQuantity(),

			(new CategoryId(CCrmOwnerType::Deal))
				->configureDefaultCategoryId($defaultCategoryId)
			,

			(new StageId(CCrmOwnerType::Deal))
				->configureDefaultCategoryId($defaultCategoryId)
			,

			new Closed(CCrmOwnerType::Deal),
			new Opened(CCrmOwnerType::Deal),
			new TypeId(CCrmOwnerType::Deal),
			new Comments(CCrmOwnerType::Deal),
			new Begindate(CCrmOwnerType::Deal),
			new Closedate(CCrmOwnerType::Deal),

			(new AssignedById(CCrmOwnerType::Deal))
				->configureNameFormat($this->importSettings->getNameFormat())
			,
		]);

		if (Container::getInstance()->getUserPermissions()->isAdminForEntity(CCrmOwnerType::Deal))
		{
			$this->fieldCollection->pushList([
				(new CreatedTime(CCrmOwnerType::Deal)),
				(new CreatedBy(CCrmOwnerType::Deal))
					->configureNameFormat($this->importSettings->getNameFormat())
				,

				(new UpdatedTime(CCrmOwnerType::Deal)),
				(new UpdatedBy(CCrmOwnerType::Deal))
					->configureNameFormat($this->importSettings->getNameFormat())
				,

				(new MovedTime(CCrmOwnerType::Deal)),
				(new MovedBy(CCrmOwnerType::Deal))
					->configureNameFormat($this->importSettings->getNameFormat())
				,
			]);
		}

		$this->fieldCollection->merge($this->fieldFactory->getUtmFields());
		$this->fieldCollection->merge($this->fieldFactory->getUserFields());

		return $this->fieldCollection;
	}

	/**
	 * @return DealImportSettings
	 */
	public function getSettings(): AbstractImportSettings
	{
		return $this->importSettings;
	}

	public function getFieldBindingMapper(): FieldBindingMapperInterface
	{
		return new ByFieldName($this->getFields(), $this->getAliases(...));
	}

	private function getAliases(ImportEntityFieldInterface $field): array
	{
		/**
		 * CRM_IMPORT_ENTITY_DEAL_ALIAS_TITLE
		 * CRM_IMPORT_ENTITY_DEAL_ALIAS_PROBABILITY
		 * CRM_IMPORT_ENTITY_DEAL_ALIAS_STAGE_ID
		 * CRM_IMPORT_ENTITY_DEAL_ALIAS_TYPE_ID
		 * CRM_IMPORT_ENTITY_DEAL_ALIAS_CLOSE_DATE
		 * CRM_IMPORT_ENTITY_DEAL_ALIAS_CREATED_TIME
		 * CRM_IMPORT_ENTITY_DEAL_ALIAS_UPDATED_TIME
		 * CRM_IMPORT_ENTITY_DEAL_ALIAS_UPDATED_BY
		 */
		$alias = Loc::getMessage("CRM_IMPORT_ENTITY_DEAL_ALIAS_{$field->getId()}");
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
		$typeList = CCrmStatus::GetStatusListEx('DEAL_TYPE');
		$stageList = CCrmStatus::GetStatusListEx('DEAL_STAGE');

		$demoData = [
			'TITLE' => Loc::getMessage('CRM_IMPORT_ENTITY_DEAL_DEMO_DATA_TITLE'),
			'PROBABILITY' => Loc::getMessage('CRM_IMPORT_ENTITY_DEAL_DEMO_DATA_PROBABILITY'),
			'TYPE_ID' => $typeList['SALE'],
			'STAGE_ID' => $stageList['NEW'],
		];

		$product = CCrmProduct::GetByOriginID('CRM_DEMO_PRODUCT_BX_CMS');
		if (is_array($product))
		{
			$demoData['PRODUCT_ID'] = $product['~NAME'];
			$demoData['PRODUCT_PRICE'] = $demoData['OPPORTUNITY'] = $product['~PRICE'];
			$demoData['CURRENCY_ID'] = $product['~CURRENCY_ID'];
			$demoData['PRODUCT_QUANTITY'] = Loc::getMessage('CRM_IMPORT_ENTITY_DEAL_DEMO_DATA_PRODUCT_QUANTITY');

			return $demoData;
		}

		$demoData['OPPORTUNITY'] = Loc::getMessage('CRM_IMPORT_ENTITY_DEAL_DEMO_DATA_OPPORTUNITY');
		$demoData['CURRENCY_ID'] = Loc::getMessage('CRM_IMPORT_ENTITY_DEAL_DEMO_DATA_CURRENCY_ID');

		return [
			$demoData,
		];
	}
}
