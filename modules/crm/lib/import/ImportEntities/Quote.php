<?php

namespace Bitrix\Crm\Import\ImportEntities;

use Bitrix\Crm\EO_Status;
use Bitrix\Crm\Import\Builder\CsvExampleFileBuilder;
use Bitrix\Crm\Import\Collection\FieldCollection;
use Bitrix\Crm\Import\Contract\ImportEntityFieldInterface;
use Bitrix\Crm\Import\Contract\ImportEntityInterface;
use Bitrix\Crm\Import\Contract\Strategy\FieldBindingMapperInterface;
use Bitrix\Crm\Import\Dto\Entity\AbstractImportSettings;
use Bitrix\Crm\Import\Dto\Entity\ImportSettings\QuoteImportSettings;
use Bitrix\Crm\Import\Factory\ImportEntityFieldFactory;
use Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\AssignedById;
use Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\Begindate;
use Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\Closed;
use Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\Closedate;
use Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\Comments;
use Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\CompanyId;
use Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\ContactId;
use Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\CreatedBy;
use Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\CreatedTime;
use Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\CurrencyId;
use Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\Id;
use Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\LeadId;
use Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\Opened;
use Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\Opportunity;
use Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\Quote\ActualDate;
use Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\Quote\Content;
use Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\Quote\DealId;
use Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\Quote\Terms;
use Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\Stage\StageId;
use Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\Title;
use Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\UpdatedBy;
use Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\UpdatedTime;
use Bitrix\Crm\Import\ImportEntityFields\Product\ProductId;
use Bitrix\Crm\Import\ImportEntityFields\Product\ProductPrice;
use Bitrix\Crm\Import\ImportEntityFields\Product\ProductQuantity;
use Bitrix\Crm\Import\Strategy\FieldBindingMapper\ByFieldName;
use Bitrix\Crm\Item;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\Factory;
use Bitrix\Main\Localization\Loc;
use CCrmOwnerType;
use CCrmProduct;

final class Quote implements ImportEntityInterface, ImportEntityInterface\HasExampleFileInterface
{
	private readonly Factory $factory;
	private ?FieldCollection $fieldCollection = null;
	private readonly int $entityTypeId;
	private readonly ImportEntityFieldFactory $fieldFactory;

	private const EXAMPLE_FILENAME = 'quote.csv';

	public function __construct(
		private readonly QuoteImportSettings $importSettings,
	)
	{
		$this->entityTypeId = CCrmOwnerType::Quote;
		$this->fieldFactory = new ImportEntityFieldFactory($this->entityTypeId);
		$this->factory = Container::getInstance()->getFactory(CCrmOwnerType::Quote);
	}

	public function getFields(): FieldCollection
	{
		if ($this->fieldCollection !== null)
		{
			return $this->fieldCollection;
		}

		$this->fieldCollection = new FieldCollection([
			new Id($this->entityTypeId),
			new Title($this->entityTypeId),

			(new AssignedById($this->entityTypeId))
				->configureNameFormat($this->importSettings->getNameFormat()),

			new Opened($this->entityTypeId),
			new Content($this->entityTypeId),
			new Terms($this->entityTypeId),
			new Comments($this->entityTypeId),
			new DealId($this->entityTypeId),
			new LeadId($this->entityTypeId),
		]);

		if ($this->factory->isClientEnabled())
		{
			$this->fieldCollection->pushList([
				new ContactId($this->entityTypeId),
				new CompanyId($this->entityTypeId),
			]);
		}

		if ($this->factory->isLinkWithProductsEnabled())
		{
			$this->fieldCollection->pushList([
				new Opportunity($this->entityTypeId),
				new CurrencyId($this->entityTypeId),
				new ProductId(),
				new ProductPrice(),
				new ProductQuantity(),
			]);
		}

		if ($this->factory->isStagesSupported())
		{
			$this->fieldCollection->push(new StageId($this->entityTypeId));
		}

		$this->fieldCollection->pushList([
			new Closed($this->entityTypeId),
			new Begindate($this->entityTypeId),
			new Closedate($this->entityTypeId),
			new ActualDate($this->entityTypeId),
		]);

		if (Container::getInstance()->getUserPermissions()->isAdminForEntity(CCrmOwnerType::Quote))
		{
			$this->fieldCollection->pushList([
				(new CreatedTime($this->entityTypeId)),
				(new CreatedBy($this->entityTypeId))
					->configureNameFormat($this->importSettings->getNameFormat())
				,

				(new UpdatedTime($this->entityTypeId)),
				(new UpdatedBy($this->entityTypeId))
					->configureNameFormat($this->importSettings->getNameFormat())
				,
			]);
		}

		if ($this->factory->isCrmTrackingEnabled())
		{
			$this->fieldCollection->merge($this->fieldFactory->getUtmFields());
		}

		$this->fieldCollection->merge($this->fieldFactory->getUserFields());

		return $this->fieldCollection;
	}

	/**
	 * @return QuoteImportSettings
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
		 * CRM_IMPORT_ENTITY_QUOTE_ALIAS_CREATED_TIME
		 * CRM_IMPORT_ENTITY_QUOTE_ALIAS_UPDATED_TIME
		 * CRM_IMPORT_ENTITY_QUOTE_ALIAS_UPDATED_BY
		 * CRM_IMPORT_ENTITY_QUOTE_ALIAS_COMMENTS
		 * CRM_IMPORT_ENTITY_QUOTE_ALIAS_STAGE_ID
		 * CRM_IMPORT_ENTITY_QUOTE_ALIAS_CLOSED
		 */
		$alias = Loc::getMessage("CRM_IMPORT_ENTITY_QUOTE_ALIAS_{$field->getId()}");
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
			->setFileName(self::EXAMPLE_FILENAME)
			->setFields($this->getFields())
			->setExampleRows($this->getExampleRows())
			->build();
	}

	private function getExampleRows(): array
	{
		$stages = $this->factory->getStages()->getAll();
		/** @var EO_Status $stage */
		$stage = current($stages);

		$exampleRow = [
			Item::FIELD_NAME_ID => Loc::getMessage('CRM_IMPORT_ENTITY_QUOTE_DEMO_DATA_ID'),
			Item::FIELD_NAME_TITLE => Loc::getMessage('CRM_IMPORT_ENTITY_QUOTE_DEMO_DATA_TITLE'),
			Item::FIELD_NAME_OPENED => Loc::getMessage('MAIN_YES'),
			Item::FIELD_NAME_STAGE_ID => $stage->getName(),
			Item::FIELD_NAME_CLOSED => Loc::getMessage('MAIN_NO'),
		];

		$product = CCrmProduct::GetByOriginID('CRM_DEMO_PRODUCT_BX_CMS');
		if ($product)
		{
			$exampleRow['PRODUCT_ID'] = $product['~NAME'];
			$exampleRow['PRODUCT_QUANTITY'] = '1';
			$exampleRow['PRODUCT_PRICE'] = $exampleRow[Item::FIELD_NAME_OPPORTUNITY] = $product['~PRICE'];
			$exampleRow[Item::FIELD_NAME_CURRENCY_ID] = $product['~CURRENCY_ID'];
		}
		else
		{
			$exampleRow[Item::FIELD_NAME_OPPORTUNITY] = Loc::getMessage('CRM_IMPORT_ENTITY_QUOTE_DEMO_DATA_OPPORTUNITY');
			$exampleRow[Item::FIELD_NAME_CURRENCY_ID] = Loc::getMessage('CRM_IMPORT_ENTITY_QUOTE_DEMO_DATA_CURRENCY_ID');
		}

		return [
			$exampleRow,
		];
	}
}
