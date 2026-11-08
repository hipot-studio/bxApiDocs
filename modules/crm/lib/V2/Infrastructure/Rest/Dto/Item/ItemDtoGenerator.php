<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item;

use Bitrix\Crm\V2\Infrastructure\Rest\CustomController\DtoGeneratorInterface;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item\Common\BeginCloseDatesDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item\Common\CategoriesDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item\Common\CompanyDto as CommonCompanyDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item\Common\ContactsDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item\Common\ItemDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item\Attribute\CrmRelation;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item\Common\MultifieldsDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item\Common\ObserversDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item\Common\ProductsDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item\Common\SourcesDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item\Common\StagesDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item\Common\CrmTrackingDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item\Common\CrmTrackingSourceDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item\Common\Dictionary\ContactTypeDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Validation\Provider\ItemProvider;
use Bitrix\Crm\V2\Infrastructure\Rest\Validation\Provider\MyCompanyProvider;
use Bitrix\Crm\V2\Infrastructure\Rest\Validation\Rule\InArrayFrom;
use Bitrix\Crm\V2\Infrastructure\Rest\Validation\Rule\Length;
use Bitrix\Crm\V2\Internal\Entity\FieldMetadata\RelatedEntityTitle;
use Bitrix\Crm\V2\Internal\Entity\FieldMetadata\SystemFieldTitle;
use Bitrix\Crm\V2\Internal\Integration\Rest\File\RestIntegrationFactory;
use Bitrix\Crm\V2\Internal\Repository\FieldMetadata\SystemFieldTitleProvider;
use Bitrix\Crm\V2\Internal\Service\Item\CustomFieldRegistry;
use Bitrix\Crm\V2\Internal\Service\Item\FieldRegistry;
use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Crm\V2\Public\EntityTypeSettings;
use Bitrix\Crm\V2\Public\OwnerType;
use Bitrix\Rest\V3\Dto\Dto;
use Bitrix\Rest\V3\Dto\DtoField;
use Bitrix\Rest\V3\Dto\DtoFieldRelation;
use Bitrix\Rest\V3\Dto\DtoFieldsCollection;
use Bitrix\Rest\V3\Dto\PropertyHelper;
use Bitrix\Rest\V3\Realisation\Dto\FileDto;
use Bitrix\Rest\V3\Schema\GeneratedDto;

class ItemDtoGenerator implements DtoGeneratorInterface
{
	private const GENERATED_CLASS_PREFIX = 'EntityType';
	private const GENERATED_CLASS_SUFFIX = 'Dto';
	private const MY_COMPANY_CODE = 'MyCompany';
	private const LEGACY_DOCUMENT_LANGUAGE_SOURCE_FILE = 'classes/general/crm_document.php';
	private const TRACKING_SOURCE_TITLE_CODE = 'CRM_DOCUMENT_FIELD_TRACKING_SOURCE_ID';

	private ?RestIntegrationFactory $restIntegrationFactory = null;
	private ?SystemFieldTitleProvider $systemFieldTitleProvider = null;
	/** @var array<string, EntityType|null> */
	private static array $entityTypeByGeneratedDtoClass = [];

	public function __construct(
		?RestIntegrationFactory $restIntegrationFactory = null,
		?SystemFieldTitleProvider $systemFieldTitleProvider = null,
	)
	{
		$this->restIntegrationFactory = $restIntegrationFactory ?? new RestIntegrationFactory();
		$this->systemFieldTitleProvider = $systemFieldTitleProvider ?? new SystemFieldTitleProvider();
	}

	/**
	 * @param bool $isMyCompany An own company gets a contract of its own: the company contract without the
	 *                          fields an own company does not have. Ignored for any other entity type.
	 */
	public function generate(int $entityTypeId = 0, bool $isMyCompany = false): GeneratedDto
	{
		/** @var array<string, GeneratedDto> $dtoByEntityType */
		static $dtoByEntityType = [];

		$entityType = EntityType::fromId($entityTypeId);
		$isMyCompany = $isMyCompany && $entityType->getId() === OwnerType::COMPANY;
		$isFileContractAvailable = $this->getRestIntegrationFactory()->isFileContractAvailable();
		$cacheKey = $this->getCacheKey($entityType, $isFileContractAvailable)
			. ($isMyCompany ? ':' . self::MY_COMPANY_CODE : '');
		if (!isset($dtoByEntityType[$cacheKey]))
		{
			$entityTypeSettings = $this->getEntityTypeSettings($entityType);
			$fields = $this->filterFieldsByRegisteredItemFields(
				$entityType,
				$this->getEntitySpecificFields($entityType),
			);
			if ($entityType->isSmartProcess())
			{
				$fields = array_merge($fields, $this->getSmartProcessFields($entityType));
			}
			$fields = array_merge($fields, $this->getCommonReadFields($entityType));
			$fields = array_merge($fields, $this->getCustomFields($entityType));
			if ($entityTypeSettings->hasStages())
			{
				$fields = array_merge($fields, $this->getStagesFields());
			}
			if ($entityTypeSettings->hasBeginCloseDates())
			{
				$fields = array_merge($fields, $this->getBeginCloseDatesFields());
			}
			if (
				$entityTypeSettings->hasCategories()
				&& $entityType->getId() !== OwnerType::CONTACT
				&& $entityType->getId() !== OwnerType::COMPANY
			)
			{
				$fields = array_merge($fields, $this->getCategoriesFields());
			}
			if ($entityTypeSettings->hasObservers())
			{
				$fields = array_merge($fields, $this->getObserversFields());
			}
			if ($entityTypeSettings->hasContactBindings())
			{
				$fields = array_merge($fields, $this->getContactsFields());
			}
			if ($entityType->getId() === OwnerType::CONTACT && $entityTypeSettings->hasCompanyBindings())
			{
				$fields = array_merge($fields, $this->getContactCompanyBindingsFields());
			}
			elseif ($entityTypeSettings->hasCompany())
			{
				$fields = array_merge($fields, $this->getCompanyFields());
			}
			if ($entityTypeSettings->hasMyCompany())
			{
				$fields = array_merge($fields, $this->getMyCompanyFields());
			}
			if ($entityTypeSettings->hasSource())
			{
				$fields = array_merge($fields, $this->getSourcesFields());
			}
			if ($entityTypeSettings->hasProducts())
			{
				$fields = array_merge($fields, $this->getProductsFields());
			}
			if ($entityTypeSettings->hasMultifields())
			{
				$fields = array_merge($fields, $this->getMultifieldsFields());
			}
			if ($entityTypeSettings->hasCrmTracking())
			{
				$fields = array_merge($fields, $this->getCrmTrackingFields());
				if (!$isMyCompany)
				{
					$fields = array_merge($fields, $this->getCrmTrackingSourceFields());
				}
			}
			$parentEntityTypesMap = $entityTypeSettings->getParentEntityTypesMap();
			if ($parentEntityTypesMap !== [])
			{
				$fields = array_merge($fields, $this->getRelatedFields($parentEntityTypesMap));
			}
			if (!$isFileContractAvailable)
			{
				$fields = array_values(array_filter(
					$fields,
					static fn(DtoField $field): bool =>
						$field->getPropertyType() !== FileDto::class
						&& $field->getElementType() !== FileDto::class,
				));
			}

			foreach ($fields as $field)
			{
				if (
					$field->getType() !== DtoField::DTO_FIELD_TYPE_USER_FIELD
					&& !str_ends_with($field->getPropertyName(), '_data')
					&& $field->getTitle() === $field->getPropertyName()
				)
				{
					$field->setTitle($this->getSystemFieldTitleProvider()->getTitle($entityType, $field->getPropertyName()));
				}
				$this->addEntityTypeContextArg($field, $entityType);
				$this->addRelationToDtoTypeField($field);
			}

			$className = $this->getGeneratedClassName($entityType, $isMyCompany);
			$namespace = $this->getGeneratedNamespace();

			$dtoByEntityType[$cacheKey] = new GeneratedDto($className, $namespace, $fields);
		}

		return $dtoByEntityType[$cacheKey];
	}

	protected function getCacheKey(EntityType $entityType, bool $isFileContractAvailable): string
	{
		return static::class . ':' . $entityType->getId()
			. ($isFileContractAvailable ? '' : ':file-unavailable');
	}

	public function getGeneratedNamespace(): string
	{
		return __NAMESPACE__;
	}

	public function getGeneratedClassName(EntityType $entityType, bool $isMyCompany = false): string
	{
		$code = $isMyCompany && $entityType->getId() === OwnerType::COMPANY
			? self::MY_COMPANY_CODE
			: $entityType->getCode()
		;

		return self::GENERATED_CLASS_PREFIX . $code . self::GENERATED_CLASS_SUFFIX;
	}

	/**
	 * Whether the class is the contract of an own company; its entity type is the company one.
	 */
	public function isMyCompanyGeneratedDtoClass(string $dtoClass): bool
	{
		return $dtoClass === $this->getGeneratedNamespace() . '\\'
			. $this->getGeneratedClassName(EntityType::company(), true);
	}

	public function getEntityTypeByGeneratedDtoClass(string $dtoClass): ?EntityType
	{
		$cacheKey = static::class . ':' . $dtoClass;
		if (array_key_exists($cacheKey, self::$entityTypeByGeneratedDtoClass))
		{
			return self::$entityTypeByGeneratedDtoClass[$cacheKey];
		}

		$prefix = $this->getGeneratedNamespace() . '\\' . self::GENERATED_CLASS_PREFIX;
		if (!str_starts_with($dtoClass, $prefix) || !str_ends_with($dtoClass, self::GENERATED_CLASS_SUFFIX))
		{
			return self::$entityTypeByGeneratedDtoClass[$cacheKey] = null;
		}

		$code = substr($dtoClass, strlen($prefix), -strlen(self::GENERATED_CLASS_SUFFIX));
		if ($code === self::MY_COMPANY_CODE)
		{
			return self::$entityTypeByGeneratedDtoClass[$cacheKey] = EntityType::company();
		}

		return self::$entityTypeByGeneratedDtoClass[$cacheKey] = EntityType::fromCode($code);
	}

	public function isGeneratedDtoClass(string $dtoClass): bool
	{
		return $this->getEntityTypeByGeneratedDtoClass($dtoClass) !== null;
	}

	protected function getEntityTypeSettings(EntityType $entityType): EntityTypeSettings
	{
		return EntityTypeSettings::of($entityType);
	}

	/**
	 * @return DtoField[]
	 */
	protected function getEntitySpecificFields(EntityType $entityType): array
	{
		if ($entityType->getId() === OwnerType::LEAD)
		{
			return $this->dtoFieldsCollectionToArray((new LeadDto($entityType))->getFields(), LeadDto::class);
		}
		if ($entityType->getId() === OwnerType::DEAL)
		{
			return $this->dtoFieldsCollectionToArray((new DealDto($entityType))->getFields(), DealDto::class);
		}
		if ($entityType->getId() === OwnerType::CONTACT)
		{
			return array_merge(
				$this->dtoFieldsCollectionToArray((new ContactDto($entityType))->getFields(), ContactDto::class),
				[
					new DtoField(
						propertyName: 'type',
						propertyType: ContactTypeDto::class,
						type: DtoField::DTO_FIELD_TYPE_DYNAMIC_FIELD,
						nullable: true,
						relation: new DtoFieldRelation('typeId', 'id'),
					),
				],
			);
		}
		if ($entityType->getId() === OwnerType::COMPANY)
		{
			return $this->dtoFieldsCollectionToArray((new CompanyDto($entityType))->getFields(), CompanyDto::class);
		}
		if ($entityType->getId() === OwnerType::QUOTE)
		{
			return $this->dtoFieldsCollectionToArray((new QuoteDto($entityType))->getFields(), QuoteDto::class);
		}
		if ($entityType->getId() === OwnerType::SMART_INVOICE)
		{
			return $this->dtoFieldsCollectionToArray((new SmartInvoiceDto($entityType))->getFields());
		}
		if ($entityType->isSmartProcess())
		{
			return $this->getTitleFields();
		}

		return [
			new DtoField(
				propertyName: 'id',
				propertyType: 'int',
				type: DtoField::DTO_FIELD_TYPE_PROPERTY,
			),
		];
	}

	/** @return DtoField[] */
	protected function getCommonReadFields(EntityType $entityType): array
	{
		$fields = [
			new DtoField(propertyName: 'entityTypeId', propertyType: 'int', type: DtoField::DTO_FIELD_TYPE_DYNAMIC_FIELD, nullable: true),
			new DtoField(propertyName: 'canRead', propertyType: 'bool', type: DtoField::DTO_FIELD_TYPE_DYNAMIC_FIELD),
			new DtoField(propertyName: 'url', propertyType: 'string', type: DtoField::DTO_FIELD_TYPE_DYNAMIC_FIELD, nullable: true),
		];
		if ($entityType->getId() === OwnerType::CONTACT)
		{
			$fields[] = new DtoField(propertyName: 'title', propertyType: 'string', type: DtoField::DTO_FIELD_TYPE_DYNAMIC_FIELD, nullable: true);
		}

		return $fields;
	}

	/**
	 * @return DtoField[]
	 */
	protected function getTitleFields(): array
	{
		return [
			new DtoField(
				propertyName: 'title',
				propertyType: 'string',
				type: DtoField::DTO_FIELD_TYPE_DYNAMIC_FIELD,
				validationRules: [
					new Length(max: 255),
				],
				editableGroups: [],
				filterable: true,
				sortable: true,
			),
		];
	}

	/** @return DtoField[] */
	protected function getSmartProcessFields(EntityType $entityType): array
	{
		$registeredItemFields = $this->getRegisteredItemFields($entityType);
		$commonFields = array_filter(
			$this->dtoFieldsCollectionToArray((new class extends AbstractItemDto {})->getFields()),
			static fn(DtoField $field): bool =>
				isset($registeredItemFields[$field->getPropertyName()])
				|| (
					$field->getRelation() !== null
					&& (
						$field->getRelation()->thisField === $field->getPropertyName()
						|| isset($registeredItemFields[$field->getRelation()->thisField])
					)
				),
		);

		return array_merge(
			[
				new DtoField(
					propertyName: 'xmlId',
					propertyType: 'string',
					type: DtoField::DTO_FIELD_TYPE_DYNAMIC_FIELD,
					validationRules: [new Length(max: 255)],
					requiredGroups: null,
					editableGroups: [],
					filterable: true,
					sortable: true,
					nullable: true,
				),
			],
			$commonFields,
		);
	}

	/** @return array<string, mixed> */
	protected function getRegisteredItemFields(EntityType $entityType): array
	{
		return FieldRegistry::getInstance($entityType)->getFields();
	}

	/**
	 * @param DtoField[] $fields
	 * @return DtoField[]
	 */
	private function filterFieldsByRegisteredItemFields(EntityType $entityType, array $fields): array
	{
		$registeredItemFields = $this->getRegisteredItemFields($entityType);
		$commonFieldNames = [];
		foreach ((new class extends AbstractItemDto {})->getFields() as $field)
		{
			$commonFieldNames[$field->getPropertyName()] = true;
		}

		return array_filter(
			$fields,
			static function (DtoField $field) use ($commonFieldNames, $registeredItemFields): bool
			{
				if (!isset($commonFieldNames[$field->getPropertyName()]))
				{
					return true;
				}

				if (isset($registeredItemFields[$field->getPropertyName()]))
				{
					return true;
				}

				$relation = $field->getRelation();
				if ($relation === null)
				{
					return false;
				}

				return $relation->thisField === $field->getPropertyName()
					|| isset($registeredItemFields[$relation->thisField]);
			},
		);
	}

	/**
	 * @return DtoField[]
	 */
	protected function getCustomFields(EntityType $entityType): array
	{
		$customFieldRegistry = CustomFieldRegistry::getInstance();
		$customFieldGenerator = new CustomFieldDtoGenerator(
			$customFieldRegistry,
			$this->getRestIntegrationFactory(),
			new RestCustomFieldDescriptorProvider($customFieldRegistry),
		);

		return $customFieldGenerator->generateCustomFields($entityType);
	}

	private function getRestIntegrationFactory(): RestIntegrationFactory
	{
		return $this->restIntegrationFactory ??= new RestIntegrationFactory();
	}

	private function getSystemFieldTitleProvider(): SystemFieldTitleProvider
	{
		return $this->systemFieldTitleProvider ??= new SystemFieldTitleProvider();
	}

	/**
	 * @return DtoField[]
	 */
	protected function getStagesFields(): array
	{
		return $this->dtoFieldsCollectionToArray((new StagesDto())->getFields());
	}

	/**
	 * @return DtoField[]
	 */
	protected function getBeginCloseDatesFields(): array
	{
		return $this->dtoFieldsCollectionToArray((new BeginCloseDatesDto())->getFields());
	}

	/**
	 * @return DtoField[]
	 */
	protected function getCategoriesFields(): array
	{
		return $this->dtoFieldsCollectionToArray((new CategoriesDto())->getFields());
	}

	/**
	 * @return DtoField[]
	 */
	protected function getObserversFields(): array
	{
		return $this->dtoFieldsCollectionToArray((new ObserversDto())->getFields());
	}

	/**
	 * @return DtoField[]
	 */
	protected function getContactsFields(): array
	{
		return $this->dtoFieldsCollectionToArray((new ContactsDto())->getFields(), ContactsDto::class);
	}

	/**
	 * @return DtoField[]
	 */
	protected function getCompanyFields(): array
	{
		return $this->dtoFieldsCollectionToArray((new CommonCompanyDto())->getFields(), CommonCompanyDto::class);
	}

	/**
	 * @return DtoField[]
	 */
	protected function getContactCompanyBindingsFields(): array
	{
		return $this->dtoFieldsCollectionToArray((new ContactCompanyBindingsDto())->getFields());
	}

	/**
	 * @return DtoField[]
	 */
	protected function getMyCompanyFields(): array
	{
		$myCompanyField = $this->setCrmRelationTarget(
			new DtoField(
				propertyName: 'myCompany',
				propertyType: ItemDto::class,
				type: DtoField::DTO_FIELD_TYPE_DYNAMIC_FIELD,
				nullable: true,
				relation: new DtoFieldRelation(
					thisField: 'myCompanyId',
					refField: 'id',
				),
			),
			OwnerType::COMPANY,
		);

		return [
			new DtoField(
				propertyName: 'myCompanyId',
				propertyType: 'int',
				type: DtoField::DTO_FIELD_TYPE_DYNAMIC_FIELD,
				validationRules: [new InArrayFrom(MyCompanyProvider::class)],
				editableGroups: [],
				nullable: true,
				filterable: true,
				sortable: true,
			),
			$myCompanyField,
		];
	}

	/**
	 * @return DtoField[]
	 */
	protected function getSourcesFields(): array
	{
		return $this->dtoFieldsCollectionToArray((new SourcesDto())->getFields());
	}

	/**
	 * @return DtoField[]
	 */
	protected function getProductsFields(): array
	{
		return $this->dtoFieldsCollectionToArray((new ProductsDto())->getFields());
	}

	/**
	 * @return DtoField[]
	 */
	protected function getMultifieldsFields(): array
	{
		return $this->dtoFieldsCollectionToArray((new MultifieldsDto())->getFields());
	}

	/**
	 * @return DtoField[]
	 */
	protected function getCrmTrackingFields(): array
	{
		return $this->dtoFieldsCollectionToArray((new CrmTrackingDto())->getFields());
	}

	/**
	 * @return DtoField[]
	 */
	protected function getCrmTrackingSourceFields(): array
	{
		$fields = $this->dtoFieldsCollectionToArray((new CrmTrackingSourceDto())->getFields());
		foreach ($fields as $field)
		{
			// The phrase the legacy documents give the same field; a generated DTO keeps only
			// titles that survive its export, hence not a plain LocalizableMessage.
			$field->setTitle(new SystemFieldTitle(
				[[self::LEGACY_DOCUMENT_LANGUAGE_SOURCE_FILE, self::TRACKING_SOURCE_TITLE_CODE]],
				$field->getPropertyName(),
			));
		}

		return $fields;
	}

	/**
	 * @param EntityType[] $parentEntityTypes
	 * @return DtoField[]
	 */
	protected function getRelatedFields(array $parentEntityTypes): array
	{
		$result = [];
		foreach ($parentEntityTypes as $parentEntityType)
		{
			$idFieldName = 'related' . $parentEntityType->getCode() . 'Id';
			$objectFieldName = 'related' . $parentEntityType->getCode();
			$result[] = new DtoField(
				propertyName: $idFieldName,
				propertyType: 'int',
				type: DtoField::DTO_FIELD_TYPE_DYNAMIC_FIELD,
				title: new RelatedEntityTitle($parentEntityType->getId(), $idFieldName),
				validationRules: [
					new InArrayFrom(ItemProvider::class, ['entityTypeId' => $parentEntityType->getId()]),
				],
				editableGroups: [],
				filterable: true,
				nullable: true,
			);
			$result[] = $this->setCrmRelationTarget(new DtoField(
				propertyName: $objectFieldName,
				propertyType: ItemDto::class,
				type: DtoField::DTO_FIELD_TYPE_DYNAMIC_FIELD,
				title: new RelatedEntityTitle($parentEntityType->getId(), $objectFieldName),
				nullable: true,
				relation: new DtoFieldRelation(
					thisField: $idFieldName,
					refField: 'id',
				),
			), $parentEntityType->getId());
		}

		return $result;
	}

	protected function dtoFieldsCollectionToArray(DtoFieldsCollection $fields, ?string $dtoClass = null): array
	{
		$result = [];
		/** @var DtoField $field */
		foreach ($fields as $field)
		{
			$fieldData = $field->toArray();
			$fieldData['type'] = DtoField::DTO_FIELD_TYPE_DYNAMIC_FIELD;
			$fieldData['validationRules'] = array_map(
				static fn(object $rule): object => clone $rule,
				$fieldData['validationRules'] ?? [],
			);
			$field = DtoField::fromArray($fieldData);
			if ($dtoClass !== null)
			{
				$field = $this->applyCrmRelationTarget($field, $dtoClass);
			}
			$result[] = $field;
		}

		return $result;
	}

	private function applyCrmRelationTarget(DtoField $field, string $dtoClass): DtoField
	{
		$reflectionClass = new \ReflectionClass($dtoClass);
		if (!$reflectionClass->hasProperty($field->getPropertyName()))
		{
			return $field;
		}

		$attributes = $reflectionClass
			->getProperty($field->getPropertyName())
			->getAttributes(CrmRelation::class);
		if ($attributes === [])
		{
			return $field;
		}

		return $this->setCrmRelationTarget($field, $attributes[0]->newInstance()->targetEntityTypeId);
	}

	private function setCrmRelationTarget(DtoField $field, int $targetEntityTypeId): DtoField
	{
		$targetEntityType = EntityType::fromId($targetEntityTypeId);
		$targetDtoClass = $this->getGeneratedNamespace() . '\\' . $this->getGeneratedClassName($targetEntityType);
		$fieldData = $field->toArray();
		if ($field->getPropertyType() === \Bitrix\Rest\V3\Dto\DtoCollection::class || $field->isMultiple())
		{
			$fieldData['elementType'] = $targetDtoClass;
		}
		else
		{
			$fieldData['propertyType'] = $targetDtoClass;
			$fieldData['elementType'] = null;
		}

		return DtoField::fromArray($fieldData);
	}

	protected function addEntityTypeContextArg(DtoField $field, EntityType $entityType): void
	{
		foreach ($field->getValidationRules() as $validationRule)
		{
			if ($validationRule instanceof InArrayFrom && !$validationRule->hasContextArg('entityTypeId'))
			{
				$validationRule->addContextArg('entityTypeId', $entityType->getId());
			}
		}
	}

	protected function addRelationToDtoTypeField(DtoField $field): void
	{
		$propertyType = $field->getElementType() ?? $field->getPropertyType();
		if (
			$propertyType === FileDto::class
			|| !is_subclass_of($propertyType, Dto::class)
			|| $field->getRelation() !== null
		)
		{
			return;
		}

		$refProperties = PropertyHelper::getProperties($propertyType);
		$firstProperty = reset($refProperties);
		if (!$firstProperty)
		{
			return;
		}

		$selfRelation = new DtoFieldRelation(
			thisField: $field->getPropertyName(),
			refField: $firstProperty->getName(),
		);
		$field->setRelation($selfRelation);
	}
}
