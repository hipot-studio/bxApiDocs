<?php

namespace Bitrix\Crm\Import\ImportEntities;

use Bitrix\Crm\Import\Builder\CsvExampleFileBuilder;
use Bitrix\Crm\Import\Collection\FieldCollection;
use Bitrix\Crm\Import\Contract\ImportEntityFieldInterface;
use Bitrix\Crm\Import\Contract\ImportEntityInterface;
use Bitrix\Crm\Import\Contract\Strategy\FieldBindingMapperInterface;
use Bitrix\Crm\Import\Dto\Entity\AbstractImportSettings;
use Bitrix\Crm\Import\Dto\Entity\ImportSettings\DynamicImportSettings;
use Bitrix\Crm\Import\Factory\ImportEntityFieldFactory;
use Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\AssignedById;
use Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\Begindate;
use Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\CategoryId;
use Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\Closedate;
use Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\CompanyId;
use Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\ContactId;
use Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\CreatedBy;
use Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\CreatedTime;
use Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\CurrencyId;
use Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\Id;
use Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\Opened;
use Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\Opportunity;
use Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\Products;
use Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\SourceDescription;
use Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\SourceId;
use Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\Stage;
use Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\Title;
use Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\UpdatedBy;
use Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\UpdatedTime;
use Bitrix\Crm\Import\Strategy\FieldBindingMapper\ByFieldName;
use Bitrix\Crm\Item;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\Factory;
use Bitrix\Main\Localization\Loc;
use CCrmOwnerType;

class Dynamic implements ImportEntityInterface, ImportEntityInterface\HasExampleFileInterface
{
	protected readonly int $entityTypeId;
	protected ?FieldCollection $fieldCollection = null;
	protected readonly ImportEntityFieldFactory $fieldFactory;
	protected readonly Factory $factory;

	public function __construct(
		private readonly DynamicImportSettings $importSettings,
	)
	{
		$this->entityTypeId = $this->importSettings->getEntityTypeId();
		$this->fieldFactory = new ImportEntityFieldFactory($this->entityTypeId);
		$this->factory = Container::getInstance()->getFactory($this->entityTypeId);
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
			new Id($this->entityTypeId),
			new Title($this->entityTypeId),

			(new AssignedById($this->entityTypeId))
				->configureNameFormat($this->importSettings->getNameFormat()),

			new Opened($this->entityTypeId),
		]);

		if ($this->factory->isBeginCloseDatesEnabled())
		{
			$this->fieldCollection->pushList([
				new Begindate($this->entityTypeId),
				new Closedate($this->entityTypeId),
			]);
		}

		if ($this->factory->isClientEnabled())
		{
			$this->fieldCollection->pushList([
				new CompanyId($this->entityTypeId),
				new ContactId($this->entityTypeId),
			]);
		}

		if ($this->factory->isCategoriesEnabled())
		{
			$this->fieldCollection->pushList([
				(new CategoryId($this->entityTypeId))
					->configureDefaultCategoryId($defaultCategoryId)
				,
			]);
		}

		if ($this->factory->isStagesEnabled())
		{
			$this->fieldCollection->pushList([
				(new Stage\StageId($this->entityTypeId))
					->configureDefaultCategoryId($defaultCategoryId)
				,

				(new Stage\PreviousStageId($this->entityTypeId))
					->configureDefaultCategoryId($defaultCategoryId)
				,
			]);
		}

		if ($this->factory->isSourceEnabled())
		{
			$this->fieldCollection->pushList([
				new SourceId($this->entityTypeId),
				new SourceDescription($this->entityTypeId),
			]);
		}

		if ($this->factory->isLinkWithProductsEnabled())
		{
			$this->fieldCollection->pushList([
				new Opportunity($this->entityTypeId),
				new CurrencyId($this->entityTypeId),
				new Products($this->entityTypeId),
			]);
		}

		if (Container::getInstance()->getUserPermissions()->isAdminForEntity($this->entityTypeId))
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

			if ($this->factory->isStagesEnabled())
			{
				$this->fieldCollection->pushList([
					(new Stage\MovedTime($this->entityTypeId)),
					(new Stage\MovedBy($this->entityTypeId))
						->configureNameFormat($this->importSettings->getNameFormat())
					,
				]);
			}
		}

		return $this->fieldCollection->merge($this->fieldFactory->getUserFields());
	}

	/**
	 * @return DynamicImportSettings
	 */
	public function getSettings(): AbstractImportSettings
	{
		return $this->importSettings;
	}

	public function getFieldBindingMapper(): FieldBindingMapperInterface
	{
		return new ByFieldName($this->getFields(), $this->getAliases(...));
	}

	protected function getAliases(ImportEntityFieldInterface $field): array
	{
		return [];
	}

	final public function getExampleFilePath(): string
	{
		return (new CsvExampleFileBuilder())
			->setFilename($this->getExampleFilename())
			->setFields($this->getFields())
			->setExampleRows($this->getExampleRows())
			->build();
	}

	protected function getExampleFilename(): string
	{
		$slug = Container::getInstance()->getTypeByEntityTypeId($this->entityTypeId)?->getName();
		$default = CCrmOwnerType::DynamicTypePrefixName . $this->entityTypeId;
		$filename = mb_strtolower($slug ?? $default);

		return "{$filename}.csv";
	}

	protected function getExampleRows(): array
	{
		return [
			[
				Item::FIELD_NAME_TITLE => Loc::getMessage('CRM_IMPORT_ENTITY_DYNAMIC_DEMO_DATA_TITLE'),
				Item::FIELD_NAME_COMPANY_ID => Loc::getMessage('CRM_IMPORT_ENTITY_DEMO_DATA_COMPANY_ID'),
				Item::FIELD_NAME_CONTACT_ID => Loc::getMessage('CRM_IMPORT_ENTITY_DEMO_DATA_CONTACT_ID'),
				Item::FIELD_NAME_STAGE_ID => Loc::getMessage('CRM_IMPORT_ENTITY_DEMO_DATA_STAGE_ID'),
				Item::FIELD_NAME_OPPORTUNITY => Loc::getMessage('CRM_IMPORT_ENTITY_DEMO_DATA_OPPORTUNITY'),
				Item::FIELD_NAME_CURRENCY_ID => Loc::getMessage('CRM_IMPORT_ENTITY_DEMO_DATA_CURRENCY_ID'),
			],
		];
	}
}
