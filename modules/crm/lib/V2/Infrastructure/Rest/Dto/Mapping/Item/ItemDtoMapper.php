<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Item;

use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\ParentFieldManager;
use Bitrix\Crm\Settings\ContactSettings;
use Bitrix\Crm\Settings\DealSettings;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item\Common\AddressDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item\Common\CustomFieldDataDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item\Common\CustomFieldData\AddressExtraDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item\Common\CustomFieldData\CrmStatusExtraDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item\Common\CustomFieldData\IblockElementExtraDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item\Common\CustomFieldData\IblockSectionExtraDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item\Common\CustomFieldData\MoneyExtraDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item\Common\Dictionary\CategoryDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item\Common\Dictionary\CompanyTypeDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item\Common\Dictionary\CurrencyDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item\Common\Dictionary\DealTypeDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item\Common\Dictionary\EmployeesDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item\Common\Dictionary\IndustryDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item\Common\Dictionary\HonorificDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item\Common\Dictionary\ContactTypeDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item\Common\Dictionary\PersonTypeDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item\Common\Dictionary\SourceDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item\Common\Dictionary\StageDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item\Common\Dictionary\StageSemanticDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item\Common\Dictionary\WebformDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item\Common\ItemDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item\Common\ItemIdentifierDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item\Common\LastCommunicationDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item\Common\MoneyDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item\Common\MultifieldDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item\Common\MultifieldValueTypeDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item\Common\MultifieldsDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item\Common\UtmDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item\ItemDtoGenerator;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item\ContactCompanyBindingsDto;
use Bitrix\Crm\V2\Infrastructure\Rest\CustomController\SchemaProvider;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Item\GetItemRequest;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Structure\SelectStructure;
use Bitrix\Crm\Multifield\TypeRepository;
use Bitrix\Crm\V2\Internal\Integration\Rest\File\FileUrlBuilder;
use Bitrix\Crm\V2\Internal\Integration\Rest\File\RestIntegrationFactory;
use Bitrix\Crm\V2\Internal\Service\Loader\Value\FileMetadata;
use Bitrix\Crm\V2\Internal\Service\Item\ItemFieldMapper;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\User\EmployeeDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Validation\Provider\PersonTypeIdProvider;
use Bitrix\Crm\StatusTable;
use Bitrix\Crm\V2\Public\Entity\File;
use Bitrix\Crm\V2\Public\Entity\Item\ContactBinding;
use Bitrix\Crm\V2\Public\Entity\Item\ContactBindingCollection;
use Bitrix\Crm\V2\Public\Entity\Item\CompanyBinding;
use Bitrix\Crm\V2\Public\Entity\Item\CompanyBindingCollection;
use Bitrix\Crm\V2\Public\Entity\Item\CustomField\Address as CustomFieldAddress;
use Bitrix\Crm\V2\Public\Entity\Item\CustomField\CrmStatus as CustomFieldCrmStatus;
use Bitrix\Crm\V2\Public\Entity\Item\CustomField\CustomFieldData;
use Bitrix\Crm\V2\Public\Entity\Item\CustomField\IblockElement as CustomFieldIblockElement;
use Bitrix\Crm\V2\Public\Entity\Item\CustomField\IblockSection as CustomFieldIblockSection;
use Bitrix\Crm\V2\Public\Entity\Item\CustomField\Money as CustomFieldMoney;
use Bitrix\Crm\V2\Public\Entity\Item\Deal;
use Bitrix\Crm\V2\Public\Entity\Item\Contact;
use Bitrix\Crm\V2\Public\Entity\Item\Company;
use Bitrix\Crm\V2\Public\Entity\Item\Lead;
use Bitrix\Crm\V2\Public\Entity\Item\Quote;
use Bitrix\Crm\V2\Public\Entity\Item\SmartInvoice;
use Bitrix\Crm\V2\Public\Entity\Item\Category;
use Bitrix\Crm\V2\Public\Entity\Item\Currency;
use Bitrix\Crm\V2\Public\Entity\Item\DealType;
use Bitrix\Crm\V2\Public\Entity\Item\Interface\HasBeginCloseDatesInterface;
use Bitrix\Crm\V2\Public\Entity\Item\Interface\HasCategoriesInterface;
use Bitrix\Crm\V2\Public\Entity\Item\Interface\HasCompanyInterface;
use Bitrix\Crm\V2\Public\Entity\Item\Interface\HasContactBindingsInterface;
use Bitrix\Crm\V2\Public\Entity\Item\Interface\HasMultifieldsInterface;
use Bitrix\Crm\V2\Public\Entity\Item\Interface\HasProductsInterface;
use Bitrix\Crm\V2\Public\Entity\Item\Interface\HasStagesInterface;
use Bitrix\Crm\V2\Public\Entity\Item\Item;
use Bitrix\Crm\V2\Public\Entity\Item\ItemCollection;
use Bitrix\Crm\V2\Public\Entity\Item\LastCommunication;
use Bitrix\Crm\V2\Public\Entity\Item\EmailCollection;
use Bitrix\Crm\V2\Public\Entity\Item\EmailValue;
use Bitrix\Crm\V2\Public\Entity\Item\ImCollection;
use Bitrix\Crm\V2\Public\Entity\Item\ImValue;
use Bitrix\Crm\V2\Public\Entity\Item\PhoneCollection;
use Bitrix\Crm\V2\Public\Entity\Item\PhoneValue;
use Bitrix\Crm\V2\Public\Entity\Item\ItemFactory;
use Bitrix\Crm\V2\Public\Entity\Item\Source;
use Bitrix\Crm\V2\Public\Entity\Item\Stage;
use Bitrix\Crm\V2\Public\Entity\Item\StageSemantic;
use Bitrix\Crm\V2\Public\Entity\Item\Utm;
use Bitrix\Crm\V2\Public\Entity\Item\Webform;
use Bitrix\Crm\V2\Public\Entity\Item\WebCollection;
use Bitrix\Crm\V2\Public\Entity\Item\WebValue;
use Bitrix\Crm\V2\Public\Entity\User\Employee;
use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Crm\V2\Public\EntityTypeSettings;
use Bitrix\Crm\V2\Public\ItemId;
use Bitrix\Crm\V2\Public\OwnerType;
use Bitrix\Crm\V2\Public\Provider\Item\Param\ItemSelect;
use Bitrix\Main\Error;
use Bitrix\Main\FileTable;
use Bitrix\Main\Type\Date;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\Validation\Validator\InArrayValidator;
use Bitrix\Rest\V3\Dto\Dto;
use Bitrix\Rest\V3\Dto\DtoCollection;
use Bitrix\Rest\V3\Dto\DtoField;
use Bitrix\Rest\V3\Dto\DtoFieldsCollection;
use Bitrix\Rest\V3\Dto\Generator;
use Bitrix\Rest\V3\Interaction\Request\Request;
use Bitrix\Rest\V3\Realisation\Dto\FileDto;
use Bitrix\Rest\V3\Structure\Structure;
use Bitrix\Rest\V3\Exception\Validation\DtoValidationException;

class ItemDtoMapper
{
	private const RELATED_CRM_OBJECT_FIELDS = [
		'company',
		'deal',
		'myCompany',
		'lead',
		'quote',
		'contact',
		'contacts',
		'companies',
	];

	private const DICTIONARY_FIELDS = [
		'stage',
		'previousStage',
		'stageSemantic',
		'source',
		'category',
		'currency',
		'webform',
		'type',
		'personType',
		'honorific',
	];

	private static ?\ReflectionProperty $dtoFieldsProperty = null;

	private ?FileUrlBuilder $fileUrlBuilder = null;
	/** @var array<int, FileMetadata|null> */
	private array $fileMetadataById = [];
	/** @var array<int, string|null> */
	private array $personTypeNameById = [];
	/** @var array<string, array{mapper: self, context: array{Dto, array<string|int, mixed>, DtoFieldsCollection, array<string, true>}}> */
	private array $relatedDtoMappingContexts = [];
	private readonly ItemFieldMapper $itemFieldMapper;

	public function __construct(
		protected readonly EntityTypeSettings $entityTypeSettings,
		?CustomFieldValueConverter $customFieldValueConverter = null,
		private readonly ?\CRestServer $restServer = null,
	)
	{
		$this->customFieldValueConverter = $customFieldValueConverter ?? new CustomFieldValueConverter();
		$this->itemFieldMapper = new ItemFieldMapper();
	}

	private readonly CustomFieldValueConverter $customFieldValueConverter;

	public function getItemSelectFromDtoFieldNames(Request $request): ItemSelect
	{
		$select = $this->getRequestSelect($request);
		$relationFields = $select->getRelationFields();
		$structuredList = $select->getStructuredList();
		$fieldNames = array_merge(
			$select->getList(),
			$select->getUserFields(),
			$relationFields,
		);

		$itemFields = array_filter(array_unique(array_map(
			fn (string $fieldName) => $this->mapDtoFieldNameToItem($fieldName),
			$fieldNames,
		)));
		$itemFields = array_values($itemFields);
		if (
			$this->entityTypeSettings->getEntityType()->getId() === OwnerType::CONTACT
			&& in_array('title', $fieldNames, true)
		)
		{
			$titleIndex = array_search('title', $itemFields, true);
			if ($titleIndex !== false)
			{
				array_splice($itemFields, $titleIndex, 1, ['name', 'lastName', 'secondName']);
			}
		}
		if (empty($itemFields))
		{
			$itemFields = ['id'];
		}
		$needsCategoryForStage = $this->entityTypeSettings->hasCategories()
			&& array_intersect(['stage', 'previousStage'], array_keys($structuredList)) !== [];
		$needsCategoryForUrl = $this->entityTypeSettings->isCategoriesSupported()
			&& in_array('url', $fieldNames, true);
		if (($needsCategoryForStage || $needsCategoryForUrl) && !in_array('categoryId', $itemFields, true))
		{
			$itemFields[] = 'categoryId';
		}

		$itemSelect = new ItemSelect(...$itemFields);
		if (array_filter($fieldNames, fn(string $fieldName): bool => $this->isRelatedCrmObjectIdField($fieldName)) !== [])
		{
			$itemSelect->withParents();
		}

		if (in_array(Item::contactBindings, $itemFields, true))
		{
			$itemSelect->withContactBindings();
		}

		if (in_array(Item::observers, $itemFields, true))
		{
			$itemSelect->withObservers();
		}
		$this->addContactFieldRelationsToSelect($itemSelect, $itemFields);
		$this->addMultifieldRelationsToSelect($itemSelect, $itemFields);

		$employeeRelationFields = [
			'createdById',
			'updatedById',
			'lastActivityById',
			'assignedById',
			'movedById',
			'observersId',
		];
		if (array_intersect($relationFields, $employeeRelationFields) !== [])
		{
			$itemSelect->withEmployee();
		}

		if (in_array(Item::utm, $relationFields, true))
		{
			$itemSelect->withUtm();
		}

		if ($this->entityTypeSettings->hasCrmTracking() && in_array(Item::trackingSourceId, $fieldNames, true))
		{
			$itemSelect->withTrackingSource();
		}

		$relatedCrmObjectsSelect = $this->getRelatedCrmObjectsSelect($structuredList, $request->getDtoClass());
		if ($relatedCrmObjectsSelect !== [])
		{
			$itemSelect->withRelatedCrmObjects($relatedCrmObjectsSelect);
		}

		$dictionariesSelect = $this->getDictionariesSelect($structuredList);
		if ($dictionariesSelect !== [])
		{
			$itemSelect->withDictionaries($dictionariesSelect);
		}

		$lastCommunicationSelect = array_key_exists('lastCommunication', $structuredList)
			? (array)$structuredList['lastCommunication']
			: [];
		if ($lastCommunicationSelect !== [] || array_key_exists('lastCommunication', $structuredList))
		{
			$itemSelect->withLastCommunication(array_values(array_unique($lastCommunicationSelect)));
		}

		$customFieldDataSelect = [];
		foreach ($structuredList as $fieldName => $structuredItem)
		{
			if (is_string($fieldName) && $this->isCustomFieldDataField($fieldName))
			{
				$relatedFieldName = $this->getCustomFieldDataRelatedField($fieldName);
				$customFieldDataSelect[$relatedFieldName] = $structuredItem;
			}
		}

		foreach ($fieldNames as $fieldName)
		{
			if ($this->isCustomFieldDataField($fieldName))
			{
				$relatedFieldName = $this->getCustomFieldDataRelatedField($fieldName);
				$customFieldDataSelect[$relatedFieldName] = []; // overrides structuredList like 'all'
			}
		}

		$dtoClass = $request->getDtoClass();
		$dto = Structure::getDto($dtoClass) ?? $dtoClass::create();
		$fileFieldNames = $select->getUserFields();
		foreach ($customFieldDataSelect as $fieldName => $dataSelect)
		{
			if ($dataSelect === [] || in_array('value', $dataSelect, true))
			{
				$fileFieldNames[] = $fieldName;
			}
		}
		foreach (array_unique($fileFieldNames) as $fieldName)
		{
			$field = $dto->getFields()[$fieldName] ?? null;
			if ($field === null || !$this->isFileDtoField($field))
			{
				continue;
			}

			if (!array_key_exists($fieldName, $customFieldDataSelect))
			{
				$customFieldDataSelect[$fieldName] = ['extra'];
			}
			elseif ($customFieldDataSelect[$fieldName] !== [])
			{
				$customFieldDataSelect[$fieldName][] = 'extra';
				$customFieldDataSelect[$fieldName] = array_values(array_unique($customFieldDataSelect[$fieldName]));
			}
		}
		$itemSelect->withCustomFieldData($customFieldDataSelect);

		return $itemSelect;
	}

	/**
	 * @param string[] $itemFields
	 */
	private function addContactFieldRelationsToSelect(ItemSelect $itemSelect, array $itemFields): void
	{
		if ($this->entityTypeSettings->getEntityType()->getId() !== OwnerType::CONTACT)
		{
			return;
		}

		if (in_array(Item::companyBindings, $itemFields, true))
		{
			$itemSelect->withCompanyBindings();
		}
	}

	/**
	 * @param string[] $itemFields
	 */
	private function addMultifieldRelationsToSelect(ItemSelect $itemSelect, array $itemFields): void
	{
		if (!$this->entityTypeSettings->hasMultifields())
		{
			return;
		}

		if (in_array(Item::phones, $itemFields, true))
		{
			$itemSelect->withPhones();
		}
		if (in_array(Item::emails, $itemFields, true))
		{
			$itemSelect->withEmails();
		}
		if (in_array(Item::webs, $itemFields, true))
		{
			$itemSelect->withWebs();
		}
		if (in_array(Item::ims, $itemFields, true))
		{
			$itemSelect->withIms();
		}
	}

	public function mapDtoFieldNameToItem(string $fieldName): ?string
	{
		// Not a column of the item: it is loaded by its own loader and filtered by its own subquery.
		if ($fieldName === Item::trackingSourceId)
		{
			return null;
		}

		return $this->itemFieldMapper->mapDtoFieldNameToItem($fieldName);
	}

	public function mapDtoFieldNameToItemForSort(string $fieldName): ?string
	{
		$itemFieldName = $this->mapDtoFieldNameToItem($fieldName);
		if ($itemFieldName !== null)
		{
			return $itemFieldName;
		}

		if (!$this->isRelatedCrmObjectIdField($fieldName))
		{
			return null;
		}

		$entityCode = substr($fieldName, strlen('related'), -strlen('Id'));
		$entityType = EntityType::fromCode($entityCode);
		if ($entityType === null)
		{
			return null;
		}

		return ParentFieldManager::getParentFieldName($entityType->getId());
	}

	/**
	 * @param string[] $dtoFieldNames
	 */
	public function getDtoFieldNameByItemFieldName(string $itemFieldName, array $dtoFieldNames): string
	{
		foreach ($dtoFieldNames as $dtoFieldName)
		{
			if ($this->mapDtoWriteFieldNameToItem($dtoFieldName) === $itemFieldName)
			{
				return $dtoFieldName;
			}
		}

		return $itemFieldName;
	}

	private function mapDtoWriteFieldNameToItem(string $fieldName): ?string
	{
		if ($fieldName === Item::utm || $fieldName === Item::trackingSourceId)
		{
			return $fieldName;
		}

		if (!$this->isRelatedCrmObjectIdField($fieldName))
		{
			return $this->mapDtoFieldNameToItem($fieldName);
		}

		$requestEntityCode = substr($fieldName, strlen('related'), -strlen('Id'));
		$requestEntityType = EntityType::fromCode($requestEntityCode);
		$parentEntityType = $requestEntityType === null
			? null
			: $this->entityTypeSettings->getParentEntityTypesMap()[$requestEntityType->getId()] ?? null
		;

		return $parentEntityType === null ? null : 'parentId_' . $parentEntityType->getId();
	}

	/**
	 * @param array<string|int, mixed> $structuredList
	 * @return array<string, array{entityTypeId: int|null, allowedCategoryIds?: int[], multiple: bool, dtoClass: string|null, select: string[]}>
	 */
	private function getRelatedCrmObjectsSelect(array $structuredList, string $dtoClass): array
	{
		$dto = $dtoClass::create();
		$result = [];
		foreach ($structuredList as $fieldName => $structuredItem)
		{
			if (!is_string($fieldName) || !$this->isRelatedCrmObjectField($fieldName))
			{
				continue;
			}

			$field = $dto->getFields()[$fieldName] ?? null;
			$targetDtoClass = $field === null
				? null
				: ($field->getElementType() ?? $field->getPropertyType())
			;
			$targetEntityType = $targetDtoClass === null
				? null
				: $this->getEntityTypeByGeneratedDtoClass($targetDtoClass)
			;

			$result[$fieldName] = [
				'entityTypeId' => $targetEntityType?->getId(),
				'multiple' => ($field?->isMultiple() ?? false) || ($field?->getRelation()?->multiple ?? false),
				'dtoClass' => $targetDtoClass,
				'select' => $this->normalizeRelatedCrmObjectSelect((array)$structuredItem),
			];
			$categoryId = $fieldName === 'myCompany' || $targetEntityType === null
				? null
				: SchemaProvider::getItemRouteCategoryId($targetEntityType);
			if ($categoryId !== null)
			{
				$result[$fieldName]['allowedCategoryIds'] = [$categoryId];
			}
		}

		return $result;
	}

	private function getEntityTypeByGeneratedDtoClass(string $dtoClass): ?EntityType
	{
		return (new ItemDtoGenerator())->getEntityTypeByGeneratedDtoClass($dtoClass);
	}

	/** @param array<int|string, mixed> $select */
	private function normalizeRelatedCrmObjectSelect(array $select): array
	{
		$result = [];
		foreach ($select as $fieldName)
		{
			if (is_string($fieldName) && !in_array($fieldName, $result, true))
			{
				$result[] = $fieldName;
			}
		}

		return $result;
	}

	private function isRelatedCrmObjectField(string $fieldName): bool
	{
		return in_array($fieldName, self::RELATED_CRM_OBJECT_FIELDS, true)
			|| (str_starts_with($fieldName, 'related') && !str_ends_with($fieldName, 'Id'));
	}

	private function isRelatedCrmObjectIdField(string $fieldName): bool
	{
		return str_starts_with($fieldName, 'related') && str_ends_with($fieldName, 'Id');
	}

	/**
	 * @param array<string|int, mixed> $structuredList
	 * @return array<string, string[]>
	 */
	private function getDictionariesSelect(array $structuredList): array
	{
		$result = [];
		foreach ($structuredList as $fieldName => $structuredItem)
		{
			if (!is_string($fieldName) || !in_array($fieldName, self::DICTIONARY_FIELDS, true))
			{
				continue;
			}

			$result[$fieldName] = (array)$structuredItem;
		}

		return $result;
	}

	protected function isCustomFieldDataField(string $fieldName): bool
	{
		return str_starts_with($fieldName, 'UF_') && str_ends_with($fieldName, '_data');
	}

	protected function getCustomFieldDataRelatedField(string $fieldName): string
	{
		return substr($fieldName, 0, -strlen('_data'));
	}

	public function getDtoByItemAndRequest(Item $item, Request $request): Dto
	{
		[$dtoTemplate, $selectList, $projectedFields, $fullRelationFields]
			= $this->prepareDtoMappingContext($request);

		return $this->getDtoByItemAndContext(
			$item,
			$dtoTemplate,
			$selectList,
			$projectedFields,
			$fullRelationFields,
		);
	}

	/**
	 * @return array{Dto, array<string|int, mixed>, DtoFieldsCollection, array<string, true>}
	 */
	private function prepareDtoMappingContext(Request $request): array
	{
		$select = $this->getRequestSelect($request);
		$dto = $this->createDto($request);
		$selectList = array_merge(
			$select->getStructuredList(),
			$select->getUserFields(),
		);
		$selectedFieldNames = [];
		foreach ($selectList as $key => $value)
		{
			$selectedFieldNames[] = is_string($key) ? $key : $value;
		}

		$projectedFields = new DtoFieldsCollection();
		foreach ($selectList as $key => $dtoFieldSelect)
		{
			$dtoFieldName = is_string($key) ? $key : $dtoFieldSelect;
			$dtoField = $dto->getFields()[$dtoFieldName] ?? null;
			if ($dtoField !== null)
			{
				$projectedFields->add(clone $dtoField);
			}
		}

		$this->uninitializeUnselectedProperties($dto, $selectedFieldNames);

		$fullRelationFields = [];
		foreach ($select->getRelationFields() as $fieldName)
		{
			if ($select->isFullRelationSelected($fieldName))
			{
				$fullRelationFields[$fieldName] = true;
			}
		}

		return [$dto, $selectList, $projectedFields, $fullRelationFields];
	}

	/**
	 * @param array<string|int, mixed> $selectList
	 */
	private function getDtoByItemAndContext(
		Item $item,
		Dto $dtoTemplate,
		array $selectList,
		DtoFieldsCollection $projectedFields,
		array $fullRelationFields,
	): Dto
	{
		$dto = clone $dtoTemplate;
		$this->replaceDtoFields($dto, $this->cloneDtoFields($projectedFields));

		foreach ($selectList as $key => $dtoFieldSelect)
		{
			$isRelationField = is_string($key);
			$dtoFieldName = $isRelationField ? $key : $dtoFieldSelect;
			$dtoField = $dto->getFields()[$dtoFieldName] ?? null;
			if ($dtoField === null)
			{
				continue;
			}
			if (!$this->isItemFieldLoaded($item, $dtoFieldName))
			{
				unset($dto->getFields()[$dtoFieldName]);

				continue;
			}

			$relatedDtoFieldName = $dtoField->getRelation()?->thisField;
			$relatedDtoField = $dtoTemplate->getFields()[$relatedDtoFieldName] ?? null;
			$isFileField = $this->isFileDtoField($dtoField);

			$fieldValue
				= $isRelationField && !$isFileField
					? $this->getItemRelationValue(
						$item,
						$dtoFieldName,
						$relatedDtoField,
						$dtoFieldSelect,
						isset($fullRelationFields[$dtoFieldName]),
						$dtoField,
					)
					: $this->getItemFieldValue(
						$item,
						$dtoField,
						$relatedDtoField,
						$isFileField && is_array($dtoFieldSelect) ? $dtoFieldSelect : [],
					);
			if ($isFileField && is_array($fieldValue))
			{
				$fileDto = FileDto::create();
				foreach ($fieldValue as $fileFieldName => $fileFieldValue)
				{
					$fileDto->{$fileFieldName} = $fileFieldValue;
				}
				$fieldValue = $fileDto;
			}
			$dto->__set($dtoFieldName, $fieldValue);
		}

		return $dto;
	}

	/**
	 * @param string[] $selectedFieldNames
	 */
	protected function uninitializeUnselectedProperties(
		Dto $dto,
		array $selectedFieldNames,
		?DtoFieldsCollection $fields = null,
	): void
	{
		$selectedFieldsIndex = array_fill_keys($selectedFieldNames, true);
		foreach ($fields ?? $dto->getFields() as $field)
		{
			$fieldName = $field->getPropertyName();
			if (isset($selectedFieldsIndex[$fieldName]) || !property_exists($dto, $fieldName))
			{
				continue;
			}

			$property = new \ReflectionProperty($dto, $fieldName);
			if ($property->isPublic())
			{
				unset($dto->{$fieldName});
			}
		}
	}

	public function getDtoCollectionByItemsAndRequest(ItemCollection $items, Request $request): DtoCollection
	{
		$collection = new DtoCollection($request->getDtoClass());
		$mappingContext = null;
		$fileMetadataPreloaded = false;
		foreach ($items as $item)
		{
			$mappingContext ??= $this->prepareDtoMappingContext($request);
			[$dtoTemplate, $selectList, $projectedFields, $fullRelationFields] = $mappingContext;
			if (!$fileMetadataPreloaded)
			{
				$this->preloadSystemFileMetadata($items, $dtoTemplate, $selectList);
				$this->preloadPersonTypeNames($items, $selectList);
				$this->preloadRelatedItemData($items, $dtoTemplate, $selectList);
				$fileMetadataPreloaded = true;
			}
			$collection->add(
				$this->getDtoByItemAndContext(
					$item,
					$dtoTemplate,
					$selectList,
					$projectedFields,
					$fullRelationFields,
				),
			);
		}

		return $collection;
	}

	private function cloneDtoFields(DtoFieldsCollection $fields): DtoFieldsCollection
	{
		$clonedFields = new DtoFieldsCollection();
		foreach ($fields as $field)
		{
			$clonedFields->add(clone $field);
		}

		return $clonedFields;
	}

	private function replaceDtoFields(Dto $dto, DtoFieldsCollection $fields): void
	{
		self::$dtoFieldsProperty ??= new \ReflectionProperty(Dto::class, 'fields');
		self::$dtoFieldsProperty->setValue($dto, $fields);
	}

	private function isItemFieldLoaded(Item $item, string $dtoFieldName): bool
	{
		if ($this->isCustomFieldDataField($dtoFieldName))
		{
			$relatedFieldName = $this->getCustomFieldDataRelatedField($dtoFieldName);

			return $item->getCustomFieldData($relatedFieldName) !== null;
		}

		if (str_starts_with($dtoFieldName, 'UF_'))
		{
			return array_key_exists($dtoFieldName, $item->getCustomFields());
		}

		return true;
	}

	private function getRequestSelect(Request $request): SelectStructure
	{
		/** @var SelectStructure|null $select */
		$select = $request->select ?? null;
		if ($select !== null)
		{
			return $select;
		}

		$scope = $request->getOptions()['scope'] ?? null;
		$scopeFields = $scope?->fields ?? [];

		return $scopeFields === []
			? SelectStructure::create(['id'], $request->getDtoClass(), $request)
			: SelectStructure::create($scopeFields, $request->getDtoClass(), $request);
	}

	protected function createDto(Request $request): Dto
	{
		/** @var string|Dto $dtoClass */
		$dtoClass = $request->getDtoClass();
		$dto = $dtoClass::create();
		Structure::addDto($dto);

		return $dto;
	}

	protected function getItemFieldValue(
		Item $item,
		DtoField $dtoField,
		?DtoField $relatedDtoField,
		array $select = [],
	): mixed
	{
		$dtoFieldName = $dtoField->getPropertyName();
		if (str_starts_with($dtoFieldName, 'UF_'))
		{
			return $this->getItemCustomFieldValue($item, $dtoField, $relatedDtoField, $select);
		}

		$value = $this->getItemMultifieldFieldValue($item, $dtoFieldName);
		if ($value !== null)
		{
			return $value;
		}

		$value = $this->getItemEntitySpecificFieldValue($item, $dtoFieldName, $select);
		if ($value !== null)
		{
			return $value;
		}

		$value = $this->getItemCategoriesFieldValue($item, $dtoFieldName);
		if ($value !== null)
		{
			return $value;
		}

		$value = $this->getItemContactsFieldValue($item, $dtoFieldName);
		if ($value !== null)
		{
			return $value;
		}

		$value = $this->getItemCompanyFieldValue($item, $dtoFieldName);
		if ($value !== null)
		{
			return $value;
		}

		$value = $this->getItemMyCompanyFieldValue($item, $dtoFieldName);
		if ($value !== null)
		{
			return $value;
		}

		$value = $this->getItemProductsFieldValue($item, $dtoFieldName);
		if ($value !== null)
		{
			return $value;
		}

		$value = $this->getItemStagesFieldValue($item, $dtoFieldName);
		if ($value !== null)
		{
			return $value;
		}

		$value = $this->getItemBeginCloseDatesFieldValue($item, $dtoFieldName);
		if ($value !== null)
		{
			return $value;
		}

		$value = $this->getItemSourcesFieldValue($item, $dtoFieldName);
		if ($value !== null)
		{
			return $value;
		}

		$value = $this->getItemTrackingSourceFieldValue($item, $dtoFieldName);
		if ($value !== null)
		{
			return $value;
		}

		$value = $this->getItemObserversFieldValue($item, $dtoFieldName);
		if ($value !== null)
		{
			return $value;
		}

		$value = $this->getItemRelatedFieldValue($item, $dtoFieldName);
		if ($value !== null)
		{
			return $value;
		}

		return match ($dtoFieldName)
			{
				'id' => $item->getId(),
				'entityTypeId' => $item->getEntityType()->getId(),
				'canRead' => $item->canRead(),
				'url' => $this->getItemUrl($item),
				'createdTime' => $item->getCreatedTime(),
				'createdById' => $item->getCreatedById(),
				'updatedTime' => $item->getUpdatedTime(),
				'updatedById' => $item->getUpdatedById(),
				'lastActivityTime' => $item->getLastActivityTime(),
				'lastActivityById' => $item->getLastActivityById(),
				'title' => $item->getCaption(),
				'xmlId' => $item->getXmlId(),
				'assignedById' => $item->getAssignedById(),
				'opened' => $item->getOpened(),
				'comments' => $item->getComments(),
				'webformId' => $item->getWebformId() ?: null,
				'originatorId' => $item->getOriginatorId(),
				'originId' => $item->getOriginId(),
				'originVersion' => $item->getOriginVersion(),
				default => null,
			};
	}

	protected function getItemRelatedFieldValue(Item $item, string $fieldName): ?int
	{
		if (!str_starts_with($fieldName, 'related') || !str_ends_with($fieldName, 'Id'))
		{
			return null;
		}

		$entityCode = substr($fieldName, strlen('related'), -strlen('Id'));
		$entityType = EntityType::fromCode($entityCode);
		if ($entityType === null)
		{
			return null;
		}

		$parentId = $item->getParentId($entityType);

		return $parentId > 0 ? $parentId : null;
	}

	protected function getItemRelationValue(
		Item $item,
		string $dtoFieldName,
		?DtoField $relatedDtoField,
		array $relationSelect,
		bool $isFullRelationSelected = false,
		?DtoField $relationField = null,
	): mixed
	{
		if ($this->isCustomFieldDataField($dtoFieldName))
		{
			return $this->getItemCustomFieldDataValue($item, $relatedDtoField, $relationSelect);
		}

		$value = $this->getItemMultifieldFieldValue(
			$item,
			$dtoFieldName,
			$relationSelect,
			$isFullRelationSelected,
		);
		if ($value !== null)
		{
			return $value;
		}

		$value = $this->getItemObserversRelationValue($item, $dtoFieldName, $relationSelect);
		if ($value !== null)
		{
			return $value;
		}

		$value = $this->getItemEmployeeRelationValue($item, $dtoFieldName, $relationSelect);
		if ($value !== null)
		{
			return $value;
		}

		$value = $this->getItemUtmRelationValue($item, $dtoFieldName, $relationSelect);
		if ($value !== null)
		{
			return $value;
		}

		$value = $this->getItemDictionaryRelationValue($item, $dtoFieldName, $relationSelect);
		if ($value !== null)
		{
			return $value;
		}

		$value = $this->getItemLastCommunicationRelationValue($item, $dtoFieldName, $relationSelect);
		if ($value !== null)
		{
			return $value;
		}

		$value = $this->getItemRelatedCrmObjectRelationValue($item, $dtoFieldName, $relationSelect, $relationField);
		if ($value !== null)
		{
			return $value;
		}

		return null;
	}

	public function getItemByDto(Dto $dto, bool $validateForAdd = false): Item
	{
		$values = $dto->toArray(true);
		$this->validateContactCollectionSizes($values);
		if ($validateForAdd)
		{
			$this->validateContactValues($values);
		}

		$item = $this->createItem($validateForAdd);
		foreach ($values as $fieldName => $value)
		{
			$dtoField = $dto->getFields()[$fieldName];
			$this->mapFieldToItem($item, $dtoField, $value);
		}

		return $item;
	}

	private function validateContactValues(array $values): void
	{
		if ($this->entityTypeSettings->getEntityType()->getId() !== OwnerType::CONTACT)
		{
			return;
		}

		foreach (['name', 'lastName'] as $fieldName)
		{
			if (is_string($values[$fieldName] ?? null) && trim($values[$fieldName]) !== '')
			{
				return;
			}
		}

		throw new DtoValidationException([
			new Error('At least one of name or lastName is required.'),
		]);
	}

	private function validateContactCollectionSizes(array $values): void
	{
		if ($this->entityTypeSettings->getEntityType()->getId() !== OwnerType::CONTACT)
		{
			return;
		}

		$companiesId = $values['companiesId'] ?? null;
		if (is_array($companiesId) && count($companiesId) > ContactCompanyBindingsDto::MAX_COMPANIES)
		{
			throw new DtoValidationException([
				new Error('Too many company bindings.'),
			]);
		}

		$total = 0;
		foreach (['phone', 'email', 'web', 'im'] as $fieldName)
		{
			$multifields = $values[$fieldName] ?? null;
			if (!$multifields instanceof DtoCollection && !is_array($multifields))
			{
				continue;
			}

			$count = count($multifields);
			if ($count > MultifieldsDto::MAX_VALUES_PER_FIELD)
			{
				throw new DtoValidationException([
					new Error(sprintf('Too many %s multifields.', $fieldName)),
				]);
			}
			$total += $count;
		}
		if ($total > MultifieldsDto::MAX_VALUES_TOTAL)
		{
			throw new DtoValidationException([
				new Error('Too many multifields.'),
			]);
		}
	}

	protected function createItem(bool $validateForAdd = false): Item
	{
		$item = ItemFactory::create($this->entityTypeSettings->getEntityType());
		if ($validateForAdd && in_array(
			$this->entityTypeSettings->getEntityType()->getId(),
			[OwnerType::DEAL, OwnerType::CONTACT],
			true,
		))
		{
			$this->applyDefaultOpenedValue($item);
		}

		return $item;
	}

	private function applyDefaultOpenedValue(Item $item): void
	{
		$opened = match ($this->entityTypeSettings->getEntityType()->getId())
		{
			OwnerType::DEAL => DealSettings::getCurrent()->getOpenedFlag(),
			OwnerType::CONTACT => ContactSettings::getCurrent()->getOpenedFlag(),
			default => null,
		};

		if ($opened !== null)
		{
			$item->internalSet(Item::opened, $opened);
		}
	}

	protected function mapFieldToItem(Item $item, DtoField $field, mixed $value): bool
	{
		$fieldName = $field->getPropertyName();
		if ($this->mapCustomFieldToItem($item, $field, $value))
		{
			return true;
		}
		if ($this->mapEntitySpecificFieldToItem($item, $fieldName, $value))
		{
			return true;
		}
		if ($this->mapMultifieldFieldToItem($item, $fieldName, $value))
		{
			return true;
		}
		if ($this->mapCategoriesFieldToItem($item, $fieldName, $value))
		{
			return true;
		}
		if ($this->mapContactsFieldToItem($item, $fieldName, $value))
		{
			return true;
		}
		if ($this->mapCompanyFieldToItem($item, $fieldName, $value))
		{
			return true;
		}
		if ($this->mapMyCompanyFieldToItem($item, $fieldName, $value))
		{
			return true;
		}
		if ($this->mapProductsFieldToItem($item, $fieldName, $value))
		{
			return true;
		}
		if ($this->mapStagesFieldToItem($item, $fieldName, $value))
		{
			return true;
		}
		if ($this->mapBeginCloseDatesFieldToItem($item, $fieldName, $value))
		{
			return true;
		}
		if ($this->mapSourcesFieldToItem($item, $fieldName, $value))
		{
			return true;
		}
		if ($this->mapObserversFieldToItem($item, $fieldName, $value))
		{
			return true;
		}
		if ($this->mapRelatedFieldToItem($item, $fieldName, $value))
		{
			return true;
		}
		if ($this->mapUtmFieldToItem($item, $fieldName, $value))
		{
			return true;
		}
		if ($this->mapTrackingSourceFieldToItem($item, $fieldName, $value))
		{
			return true;
		}

		return (bool)match ($fieldName)
			{
				'title' => $item->setTitle($value),
				'xmlId' => $item->setXmlId($value),
				'assignedById' => $item->setAssignedById($value),
				'opened' => $item->setOpened($value),
				'comments' => $item->setComments($value),
				'originatorId' => $item->setOriginatorId($value),
				'originId' => $item->setOriginId($value),
				'originVersion' => $item->setOriginVersion($value),
				default => null,
			};
	}

	protected function mapCustomFieldToItem(Item $item, DtoField $field, mixed $value): bool
	{
		if (str_starts_with($field->getPropertyName(), 'UF_'))
		{
			$value = $this->convertCustomFieldValueToItem($field, $value);
			$item->setCustomField($field->getPropertyName(), $value);

			return true;
		}

		return false;
	}

	protected function convertCustomFieldValueToItem(DtoField $field, mixed $value): mixed
	{
		return $this->customFieldValueConverter->convert($field, $value);
	}

	protected function getItemCustomFieldValue(
		Item $item,
		DtoField $field,
		?DtoField $relatedDtoField,
		array $select = [],
	): mixed
	{
		$fieldName = $field->getPropertyName();
		if ($this->isCustomFieldDataField($fieldName) && $relatedDtoField !== null)
		{
			return $this->getItemCustomFieldDataValue($item, $relatedDtoField);
		}

		$rawValue = $item->getCustomField($fieldName);
		if ($this->isFileDtoField($field))
		{
			return $this->convertFileCustomFieldValueFromItem($item, $field, $rawValue, $select);
		}

		return $this->convertCustomFieldValueFromItem($field, $rawValue);
	}

	private function convertFileCustomFieldValueFromItem(
		Item $item,
		DtoField $field,
		mixed $value,
		array $select,
	): FileDto|DtoCollection|null
	{
		$customFieldData = $item->getCustomFieldData($field->getPropertyName());
		if ($field->getElementType() === FileDto::class)
		{
			$result = new DtoCollection(FileDto::class);
			$values = is_iterable($value) ? $value : [];
			$dataItems = is_array($customFieldData) ? array_values($customFieldData) : [];
			foreach ($values as $index => $file)
			{
				$metadata = ($dataItems[$index] ?? null)?->getExtra();
				if (!$file instanceof File || !$metadata instanceof FileMetadata)
				{
					continue;
				}

				$fileDto = $this->convertFileValueFromItem(
					$item,
					$field->getPropertyName(),
					$file,
					$metadata,
					$select,
				);
				if ($fileDto !== null)
				{
					$result->add($fileDto);
				}
			}

			return $result;
		}

		$metadata = $customFieldData instanceof CustomFieldData ? $customFieldData->getExtra() : null;
		if (!$value instanceof File || !$metadata instanceof FileMetadata)
		{
			return null;
		}

		return $this->convertFileValueFromItem(
			$item,
			$field->getPropertyName(),
			$value,
			$metadata,
			$select,
		);
	}

	protected function convertCustomFieldValueFromItem(DtoField $field, mixed $value): mixed
	{
		$propertyType = $field->getElementType() ?? $field->getPropertyType();
		if ($field->getElementType() !== null)
		{
			$result = new DtoCollection($field->getElementType() ?? $propertyType);
			$items = $value === null ? [] : (is_iterable($value) ? $value : [$value]);

			foreach ($items as $item)
			{
				$convertedItem = $this->convertCustomFieldSingleValueFromItem($propertyType, $item);
				if ($convertedItem instanceof Dto)
				{
					$result->add($convertedItem);
				}
			}

			return $result;
		}

		if ($value === null)
		{
			return $field->isMultiple() ? [] : null;
		}

		$propertyType = $field->getPropertyType();
		if (!is_subclass_of($propertyType, Dto::class) && $field->isMultiple() && is_array($value))
		{
			$result = [];
			foreach ($value as $item)
			{
				$result[] = $this->convertCustomFieldSingleValueFromItem($propertyType, $item);
			}

			return $result;
		}

		return $this->convertCustomFieldSingleValueFromItem($propertyType, $value);
	}

	protected function getItemCustomFieldDataValue(
		Item $item,
		?DtoField $relatedDtoField,
		array $select = [],
	): mixed {
		if ($relatedDtoField === null)
		{
			return null;
		}

		$fieldName = $relatedDtoField->getPropertyName();
		$customFieldData = $item->getCustomFieldData($fieldName);
		if ($customFieldData === null)
		{
			return null;
		}

		if ($relatedDtoField->getPropertyType() === DtoCollection::class || $relatedDtoField->isMultiple())
		{
			$result = new DtoCollection(CustomFieldDataDto::class);
			if (is_array($customFieldData) && array_is_list($customFieldData))
			{
				foreach ($customFieldData as $customFieldDataItem)
				{
					$dataDto = $this->getCustomFieldDataDto($item, $relatedDtoField, $customFieldDataItem, $select);
					$result->add($dataDto);
				}
			}

			return $result;
		}

		return $this->getCustomFieldDataDto($item, $relatedDtoField, $customFieldData, $select);
	}

	protected function getCustomFieldDataDto(
		Item $item,
		DtoField $relatedDtoField,
		?CustomFieldData $customFieldData,
		array $select = [],
	): CustomFieldDataDto
	{
		$rawValue = $customFieldData?->getValue();
		$extra = $customFieldData?->getExtra();
		$isFileField = $this->isFileDtoField($relatedDtoField);
		$dataDto = new CustomFieldDataDto();
		if (empty($select) || in_array('rawValue', $select, true))
		{
			$dataDto->rawValue = $this->normalizeCustomFieldRawValue($rawValue);
		}
		if (empty($select) || in_array('value', $select, true))
		{
			$dataDto->value = null;
			if ($rawValue !== null)
			{
				if ($isFileField && $extra instanceof FileMetadata)
				{
					$file = $rawValue instanceof File ? $rawValue : File::fromId((int)$rawValue);
					$dataDto->value = $this->normalizeCustomFieldDataValue(
						$this->convertFileValueFromItem(
							$item,
							$relatedDtoField->getPropertyName(),
							$file,
							$extra,
						),
					);
				}
				elseif (!$isFileField)
				{
					$propertyType = $relatedDtoField->getElementType() ?? $relatedDtoField->getPropertyType();
					$dataDto->value = $this->normalizeCustomFieldDataValue(
						$this->convertCustomFieldSingleValueFromItem($propertyType, $rawValue),
					);
				}
			}
		}
		if (empty($select) || in_array('formattedValue', $select, true))
		{
			$dataDto->formattedValue = $customFieldData?->getFormattedValue();
		}
		if ($select === [] && !$isFileField)
		{
			$dataDto->extra = $this->convertCustomFieldExtraValueFromItem($extra);
		}
		elseif (in_array('extra', $select, true))
		{
			$dataDto->extra = $isFileField ? null : $this->convertCustomFieldExtraValueFromItem($extra);
		}

		return $dataDto;
	}

	private function convertFileValueFromItem(
		Item $item,
		string $fieldName,
		File $file,
		FileMetadata $metadata,
		array $select = [],
	): ?FileDto
	{
		$fileId = $file->getId();
		if ($fileId <= 0 || $item->getId() <= 0 || $this->restServer === null)
		{
			return null;
		}

		$this->fileUrlBuilder ??= (new RestIntegrationFactory())->createFileUrlBuilder($this->restServer);
		$urls = $this->fileUrlBuilder->build(
			$item->getEntityType()->getId(),
			$item->getId(),
			$fieldName,
			$fileId,
			$this->getItemFile($item, $fieldName) !== null,
		);

		/** @var FileDto $dto */
		$dto = FileDto::create();
		if ($select === [] || in_array('id', $select, true))
		{
			$dto->id = (string)$fileId;
		}
		if ($select === [] || in_array('name', $select, true))
		{
			$dto->name = $metadata->getName();
		}
		if ($select === [] || in_array('url', $select, true))
		{
			$dto->url = $urls['url'];
		}
		if ($select === [] || in_array('downloadUrl', $select, true))
		{
			$dto->downloadUrl = $urls['downloadUrl'];
		}

		return $dto;
	}

	private function isFileDtoField(DtoField $field): bool
	{
		return $field->getPropertyType() === FileDto::class || $field->getElementType() === FileDto::class;
	}

	protected function convertCustomFieldExtraValueFromItem(mixed $value): ?array
	{
		if ($value instanceof Employee)
		{
			return EmployeeDto::fromEntity($value)->toArray();
		}

		if ($value instanceof Item)
		{
			return $this->createRelatedItemDto($value, [])->toArray();
		}

		if ($value instanceof CustomFieldMoney)
		{
			$dto = new MoneyExtraDto();
			$dto->amount = $value->getAmount();
			$dto->currencyId = $value->getCurrencyId();
			$dto->currencyFullName = $value->getCurrencyFullName();

			return $dto->toArray();
		}

		if ($value instanceof CustomFieldAddress)
		{
			$dto = new AddressExtraDto();
			$dto->locationId = $value->getLocationId();
			$dto->latitude = $value->getLatitude();
			$dto->longitude = $value->getLongitude();
			$dto->country = $value->getCountry();
			$dto->city = $value->getCity();
			$dto->postalCode = $value->getPostalCode();
			$dto->address1 = $value->getAddress1();
			$dto->address2 = $value->getAddress2();

			return $dto->toArray();
		}

		if ($value instanceof CustomFieldCrmStatus)
		{
			$dto = new CrmStatusExtraDto();
			$dto->id = $value->getId();
			$dto->name = $value->getName();
			$dto->sort = $value->getSort();

			return $dto->toArray();
		}

		if ($value instanceof CustomFieldIblockSection)
		{
			$dto = new IblockSectionExtraDto();
			$dto->id = $value->getId();
			$dto->name = $value->getName();

			return $dto->toArray();
		}

		if ($value instanceof CustomFieldIblockElement)
		{
			$dto = new IblockElementExtraDto();
			$dto->id = $value->getId();
			$dto->name = $value->getName();

			return $dto->toArray();
		}

		return null;
	}

	protected function normalizeCustomFieldDataValue(mixed $value): mixed
	{
		if ($value instanceof Dto || $value instanceof DtoCollection)
		{
			return $value->toArray();
		}

		return $value;
	}

	protected function normalizeCustomFieldRawValue(mixed $value): mixed
	{
		if ($value instanceof File)
		{
			return $value->getId();
		}

		if ($value instanceof ItemId)
		{
			return sprintf(
				'%s_%d',
				\CCrmOwnerTypeAbbr::ResolveByTypeID($value->getEntityType()->getId()),
				$value->getId(),
			);
		}

		if (is_array($value))
		{
			return array_map(fn(mixed $item): mixed => $this->normalizeCustomFieldRawValue($item), $value);
		}

		return $value;
	}

	protected function convertCustomFieldSingleValueFromItem(string $propertyType, mixed $value): mixed
	{
		if ($value instanceof File)
		{
			$value = $value->getId();
		}

		if (is_a($propertyType, MoneyDto::class, true))
		{
			return $this->convertCustomFieldMoneyFromItem($value);
		}

		if (is_a($propertyType, AddressDto::class, true))
		{
			return $this->convertCustomFieldAddressFromItem($value);
		}

		if (is_a($propertyType, ItemIdentifierDto::class, true))
		{
			return $this->convertCustomFieldItemIdentifierFromItem($value);
		}

		if ($value instanceof DateTime)
		{
			return $value->format(DATE_ATOM);
		}
		if ($value instanceof Date)
		{
			return $value->format('Y-m-d');
		}

		return match ($propertyType)
			{
				'int' => $value instanceof ItemId ? $value->getId() : (int)$value,
				'float' => (float)$value,
				default => $value,
			};
	}

	protected function convertCustomFieldMoneyFromItem(mixed $value): MoneyDto
	{
		[$sum, $currencyId] = array_pad(explode('|', (string)$value, 2), 2, '');

		$dto = new MoneyDto();
		$dto->sum = (float)$sum;
		$dto->currencyId = $currencyId;

		return $dto;
	}

	protected function convertCustomFieldAddressFromItem(mixed $value): AddressDto
	{
		[$address, $coordinates] = array_pad(explode('|', (string)$value, 3), 2, '');
		[$latitude, $longitude] = array_pad(explode(';', $coordinates, 2), 2, '');

		$dto = new AddressDto();
		$dto->address = $address;
		$dto->latitude = $latitude !== '' ? (float)$latitude : null;
		$dto->longitude = $longitude !== '' ? (float)$longitude : null;

		return $dto;
	}

	protected function convertCustomFieldItemIdentifierFromItem(mixed $value): ItemIdentifierDto
	{
		if ($value instanceof ItemId)
		{
			$entityTypeId = $value->getEntityType()->getId();
			$entityId = $value->getId();
		}
		else
		{
			[$entityTypeName, $entityId] = array_pad(explode('_', (string)$value, 2), 2, '');
			$entityTypeId = \CCrmOwnerType::ResolveID(\CCrmOwnerTypeAbbr::ResolveName($entityTypeName));
		}

		$dto = new ItemIdentifierDto();
		$dto->entityTypeId = $entityTypeId;
		$dto->entityId = (int)$entityId;

		return $dto;
	}

	protected function mapEntitySpecificFieldToItem(Item $item, string $fieldName, mixed $value): bool
	{
		if ($this->entityTypeSettings->getEntityType()->getId() === OwnerType::DEAL)
		{
			return $this->mapFieldToDeal($item, $fieldName, $value);
		}
		if ($this->entityTypeSettings->getEntityType()->getId() === OwnerType::CONTACT)
		{
			return $this->mapFieldToContact($item, $fieldName, $value);
		}
		if ($this->entityTypeSettings->getEntityType()->getId() === OwnerType::LEAD)
		{
			return $this->mapFieldToLead($item, $fieldName, $value);
		}
		if ($this->entityTypeSettings->getEntityType()->getId() === OwnerType::COMPANY)
		{
			return $this->mapFieldToCompany($item, $fieldName, $value);
		}
		if ($this->entityTypeSettings->getEntityType()->getId() === OwnerType::QUOTE)
		{
			return $this->mapFieldToQuote($item, $fieldName, $value);
		}
		if ($this->entityTypeSettings->getEntityType()->getId() === OwnerType::SMART_INVOICE)
		{
			return $this->mapFieldToSmartInvoice($item, $fieldName, $value);
		}

		return false;
	}

	protected function getItemEntitySpecificFieldValue(Item $item, string $fieldName, array $select = []): mixed
	{
		if ($item instanceof Deal)
		{
			return $this->getDealFieldValue($item, $fieldName);
		}
		if ($item instanceof Contact)
		{
			return $this->getContactFieldValue($item, $fieldName, $select);
		}
		if ($item instanceof Lead)
		{
			return $this->getLeadFieldValue($item, $fieldName);
		}
		if ($item instanceof Company)
		{
			return $this->getCompanyFieldValue($item, $fieldName, $select);
		}
		if ($item instanceof Quote)
		{
			return $this->getQuoteFieldValue($item, $fieldName);
		}
		if ($item instanceof SmartInvoice)
		{
			return $this->getSmartInvoiceFieldValue($item, $fieldName);
		}

		return null;
	}

	protected function mapFieldToLead(Item $item, string $fieldName, mixed $value): bool
	{
		if (!$item instanceof Lead)
		{
			return false;
		}

		return (bool)match ($fieldName)
		{
			'birthdayTime' => $item->setBirthdate($value),
			'honorificId' => $item->setHonorific($value),
			'name' => $item->setName($value),
			'lastName' => $item->setLastName($value),
			'secondName' => $item->setSecondName($value),
			'post' => $item->setPost($value),
			'companyTitle' => $item->setCompanyTitle($value),
			'statusDescription' => $item->setStatusDescription($value),
			default => null,
		};
	}

	protected function getLeadFieldValue(Lead $item, string $fieldName): mixed
	{
		return match ($fieldName)
		{
			'birthdayTime' => $item->getBirthdate(),
			'honorificId' => $item->getHonorific(),
			'name' => $item->getName(),
			'lastName' => $item->getLastName(),
			'secondName' => $item->getSecondName(),
			'post' => $item->getPost(),
			'companyTitle' => $item->getCompanyTitle(),
			'statusDescription' => $item->getStatusDescription(),
			default => null,
		};
	}

	protected function mapFieldToCompany(Item $item, string $fieldName, mixed $value): bool
	{
		if (!$item instanceof Company)
		{
			return false;
		}

		return (bool)match ($fieldName)
		{
			'leadId' => $item->setLeadId($value),
			'industryId' => $item->setIndustry($value),
			'employeesId' => $item->setEmployees($value),
			'revenue' => $item->setRevenue($value),
			'typeId' => $item->setTypeId($value),
			default => null,
		};
	}

	protected function getCompanyFieldValue(Company $item, string $fieldName, array $select = []): mixed
	{
		return match ($fieldName)
		{
			'leadId' => $item->getLeadId(),
			'currencyId' => $item->getCurrencyId(),
			'industryId' => $item->getIndustry(),
			'employeesId' => $item->getEmployees(),
			'revenue' => $item->getRevenue(),
			'logo' => $this->getCompanyLogoValue($item, $select),
			'typeId' => $item->getTypeId(),
			default => null,
		};
	}

	private function getCompanyLogoValue(Company $item, array $select = []): ?array
	{
		$file = $this->getItemFile($item, Company::logo);
		$metadata = $file === null ? null : $this->getFileMetadata($file);
		if ($file === null || $metadata === null)
		{
			return null;
		}

		return $this->convertFileValueFromItem(
			$item,
			Company::logo,
			$file,
			$metadata,
			$select,
		)?->toArray();
	}

	protected function mapFieldToQuote(Item $item, string $fieldName, mixed $value): bool
	{
		if (!$item instanceof Quote)
		{
			return false;
		}

		return (bool)match ($fieldName)
		{
			'content' => $item->setContent($value),
			'terms' => $item->setTerms($value),
			'quoteNumber' => $item->setQuoteNumber($value),
			'dealId' => $item->setDealId($value),
			'leadId' => $item->setLeadId($value),
			'actualTime' => $item->setActualDate($value),
			'locationId' => $item->setLocationId($value),
			default => null,
		};
	}

	protected function getQuoteFieldValue(Quote $item, string $fieldName): mixed
	{
		return match ($fieldName)
		{
			'content' => $item->getContent(),
			'terms' => $item->getTerms(),
			'quoteNumber' => $item->getQuoteNumber(),
			'dealId' => $item->getDealId(),
			'leadId' => $item->getLeadId(),
			'actualTime' => $item->getActualDate(),
			'locationId' => $item->getLocationId(),
			'personTypeId' => PersonTypeIdProvider::getPersonTypeCode($item->getPersonTypeId()),
			default => null,
		};
	}

	protected function mapFieldToSmartInvoice(Item $item, string $fieldName, mixed $value): bool
	{
		if (!$item instanceof SmartInvoice)
		{
			return false;
		}

		return (bool)match ($fieldName)
		{
			'accountNumber' => $item->setAccountNumber($value),
			'locationId' => $item->setLocationId($value),
			default => null,
		};
	}

	protected function getSmartInvoiceFieldValue(SmartInvoice $item, string $fieldName): mixed
	{
		return match ($fieldName)
		{
			'accountNumber' => $item->getAccountNumber(),
			'locationId' => $item->getLocationId(),
			default => null,
		};
	}

	private function getItemMultifieldFieldValue(
		Item $item,
		string $fieldName,
		array $select = [],
		bool $isFullRelationSelected = false,
	): mixed
	{
		if (!$this->entityTypeSettings->hasMultifields() || !$item instanceof HasMultifieldsInterface)
		{
			return null;
		}

		return match ($fieldName)
		{
			'phone' => $this->getMultifieldDtos($item->getPhones(), 'PHONE', $select, $isFullRelationSelected),
			'hasPhone' => !empty($item->getPhones()?->getAll()),
			'email' => $this->getMultifieldDtos($item->getEmails(), 'EMAIL', $select, $isFullRelationSelected),
			'hasEmail' => !empty($item->getEmails()?->getAll()),
			'web' => $this->getMultifieldDtos($item->getWebs(), 'WEB', $select, $isFullRelationSelected),
			'hasWeb' => !empty($item->getWebs()?->getAll()),
			'im' => $this->getMultifieldDtos($item->getIms(), 'IM', $select, $isFullRelationSelected),
			'hasIm' => !empty($item->getIms()?->getAll()),
			default => null,
		};
	}

	protected function mapFieldToDeal(Item $item, string $fieldName, mixed $value): bool
	{
		if (!$item instanceof Deal)
		{
			return false;
		}

		return (bool)match ($fieldName)
			{
				'typeId' => $item->setTypeId($value),
				'quoteId' => $item->setQuoteId($value),
				'locationId' => $item->setLocationId($value),
				default => null,
			};
	}

	protected function getDealFieldValue(Deal $item, string $fieldName): mixed
	{
		return match ($fieldName)
			{
				'typeId' => $item->getTypeId(),
				'quoteId' => $item->getQuoteId(),
				'locationId' => $item->getLocationId(),
				'leadId' => $item->getLeadId(),
				'previousStageId' => $item->getPreviousStageId(),
				'isNew' => $item->getIsNew(),
				'isRepeatedApproach' => $item->getIsRepeatedApproach(),
				'isReturnCustomer' => $item->getIsReturnCustomer(),
				default => null,
			};
	}

	protected function mapFieldToContact(Item $item, string $fieldName, mixed $value): bool
	{
		if (!$item instanceof Contact)
		{
			return false;
		}

		if ($fieldName === 'companiesId')
		{
			return $this->mapContactCompanyBindings($item, $value);
		}
		return (bool)match ($fieldName)
		{
			'leadId' => $item->setLeadId($value),
			'honorificId' => $item->setHonorific($value),
			'name' => $item->setName($value),
			'lastName' => $item->setLastName($value),
			'secondName' => $item->setSecondName($value),
			'post' => $item->setPost($value),
			'birthdayTime' => $item->setBirthdate($value),
			'typeId' => $item->setTypeId($value),
			'export' => $item->setExport($value),
			'opened' => $item->setOpened($value),
			default => null,
		};
	}

	protected function getContactFieldValue(Contact $item, string $fieldName, array $select = []): mixed
	{
		return match ($fieldName)
		{
			'leadId' => $item->getLeadId(),
			'honorificId' => $item->getHonorific(),
			'name' => $item->getName(),
			'lastName' => $item->getLastName(),
			'secondName' => $item->getSecondName(),
			'post' => $item->getPost(),
			'birthdayTime' => $item->getBirthdate(),
			'typeId' => $item->getTypeId(),
			'export' => $item->getExport(),
			'photo' => $this->getContactPhotoValue($item, $select),
			'companiesId' => $this->getContactCompanyIds($item),
			default => null,
		};
	}

	private function getContactPhotoValue(Contact $item, array $select = []): ?array
	{
		$file = $this->getItemFile($item, Contact::photo);
		$metadata = $file === null ? null : $this->getFileMetadata($file);
		if ($file === null || $metadata === null)
		{
			return null;
		}

		return $this->convertFileValueFromItem(
			$item,
			Contact::photo,
			$file,
			$metadata,
			$select,
		)?->toArray();
	}

	private function getItemFile(Item $item, string $fieldName): ?File
	{
		return match (true)
		{
			$item instanceof Contact && $fieldName === Contact::photo => $item->getPhoto(),
			$item instanceof Company && $fieldName === Company::logo => $item->getLogo(),
			default => null,
		};
	}

	/**
	 * @param array<string|int, mixed> $selectList
	 */
	private function preloadSystemFileMetadata(ItemCollection $items, Dto $dtoTemplate, array $selectList): void
	{
		$fileFieldNames = [];
		foreach ($selectList as $key => $value)
		{
			$fieldName = is_string($key) ? $key : $value;
			if (!is_string($fieldName))
			{
				continue;
			}

			$dtoField = $dtoTemplate->getFields()[$fieldName] ?? null;
			if ($dtoField?->getPropertyType() === FileDto::class)
			{
				$fileFieldNames[] = $fieldName;
			}
		}

		$fileIds = [];
		foreach ($items as $item)
		{
			foreach ($fileFieldNames as $fieldName)
			{
				$fileId = $this->getItemFile($item, $fieldName)?->getId();
				if ($fileId !== null && $fileId > 0)
				{
					$fileIds[$fileId] = true;
				}
			}
		}

		$missingFileIds = array_diff_key($fileIds, $this->fileMetadataById);
		foreach (array_chunk(array_keys($missingFileIds), 500) as $fileIdsChunk)
		{
			foreach (FileTable::query()
				->setSelect(['ID', 'ORIGINAL_NAME', 'FILE_NAME'])
				->whereIn('ID', $fileIdsChunk)
				->exec() as $fileData)
			{
				$fileId = (int)($fileData['ID'] ?? 0);
				$name = (string)(($fileData['ORIGINAL_NAME'] ?? '') ?: ($fileData['FILE_NAME'] ?? ''));
				if ($fileId > 0 && $name !== '')
				{
					$this->fileMetadataById[$fileId] = new FileMetadata($name);
				}
			}

			foreach ($fileIdsChunk as $fileId)
			{
				$this->fileMetadataById[(int)$fileId] ??= null;
			}
		}
	}

	/**
	 * @param array<string|int, mixed> $selectList
	 */
	private function preloadPersonTypeNames(ItemCollection $items, array $selectList): void
	{
		$hasPersonType = false;
		foreach ($selectList as $key => $value)
		{
			$fieldName = is_string($key) ? $key : $value;
			if ($fieldName === 'personType')
			{
				$hasPersonType = true;
				break;
			}
		}
		if (!$hasPersonType || !\Bitrix\Main\Loader::includeModule('sale'))
		{
			return;
		}

		$personTypeIds = [];
		foreach ($items as $item)
		{
			if (!$item instanceof Quote)
			{
				continue;
			}

			$personTypeId = $item->getPersonTypeId();
			if ($personTypeId !== null && $personTypeId > 0)
			{
				$personTypeIds[$personTypeId] = true;
			}
		}

		$missingPersonTypeIds = array_diff_key($personTypeIds, $this->personTypeNameById);
		foreach (array_chunk(array_keys($missingPersonTypeIds), 500) as $personTypeIdsChunk)
		{
			foreach (\Bitrix\Sale\Internals\PersonTypeTable::query()
				->setSelect(['ID', 'NAME'])
				->whereIn('ID', $personTypeIdsChunk)
				->exec() as $personType)
			{
				$personTypeId = (int)($personType['ID'] ?? 0);
				if ($personTypeId > 0)
				{
					$this->personTypeNameById[$personTypeId] = (string)($personType['NAME'] ?? '');
				}
			}

			foreach ($personTypeIdsChunk as $personTypeId)
			{
				$this->personTypeNameById[(int)$personTypeId] ??= null;
			}
		}
	}

	/**
	 * @param array<string|int, mixed> $selectList
	 */
	private function preloadRelatedItemData(ItemCollection $items, Dto $dtoTemplate, array $selectList): void
	{
		foreach ($selectList as $fieldName => $relationSelect)
		{
			if (!is_string($fieldName) || !is_array($relationSelect))
			{
				continue;
			}

			$relationField = $dtoTemplate->getFields()[$fieldName] ?? null;
			if ($relationField === null)
			{
				continue;
			}

			$relatedItems = new ItemCollection();
			foreach ($items as $item)
			{
				foreach ($this->getLoadedRelatedItems($item, $fieldName) as $relatedItem)
				{
					$relatedItems->add($relatedItem);
				}
			}
			if (count($relatedItems) === 0)
			{
				continue;
			}

			$relatedItemsByMappingContext = [];
			foreach ($relatedItems as $item)
			{
				$mappingContext = $this->getRelatedDtoMappingContext(
					$item,
					$relationSelect,
					$relationField,
					$fieldName === 'contacts' || $fieldName === 'companies',
				);
				$mappingContextKey = (string)spl_object_id($mappingContext['mapper']);
				if (!isset($relatedItemsByMappingContext[$mappingContextKey]))
				{
					$relatedItemsByMappingContext[$mappingContextKey] = [
						'mapper' => $mappingContext['mapper'],
						'context' => $mappingContext['context'],
						'items' => new ItemCollection(),
					];
				}
				$relatedItemsByMappingContext[$mappingContextKey]['items']->add($item);
			}

			foreach ($relatedItemsByMappingContext as $mappingContext)
			{
				[$relatedDtoTemplate, $relatedSelectList] = $mappingContext['context'];
				$mappingContext['mapper']->preloadSystemFileMetadata(
					$mappingContext['items'],
					$relatedDtoTemplate,
					$relatedSelectList,
				);
				$mappingContext['mapper']->preloadPersonTypeNames(
					$mappingContext['items'],
					$relatedSelectList,
				);
			}
		}
	}

	protected function getFileMetadata(File $file): ?FileMetadata
	{
		$fileId = $file->getId();
		if ($fileId === null || $fileId <= 0)
		{
			return null;
		}

		if (array_key_exists($fileId, $this->fileMetadataById))
		{
			return $this->fileMetadataById[$fileId];
		}

		$fileData = FileTable::query()
			->setSelect(['ORIGINAL_NAME', 'FILE_NAME'])
			->where('ID', $fileId)
			->fetch()
		;
		$name = (string)(($fileData['ORIGINAL_NAME'] ?? '') ?: ($fileData['FILE_NAME'] ?? ''));

		return $this->fileMetadataById[$fileId] = $name !== '' ? new FileMetadata($name) : null;
	}

	private function mapContactCompanyBindings(Contact $item, mixed $value): bool
	{
		if (!is_array($value))
		{
			return false;
		}

		$bindings = new CompanyBindingCollection();
		foreach (array_values(array_unique($value)) as $index => $companyId)
		{
			$sort = ($index + 1) * 10;
			$bindings->add(new CompanyBinding((int)$companyId, $sort, $index === 0));
		}

		$item->setCompanyBindings($bindings);

		return true;
	}

	private function mapMultifieldFieldToItem(Item $item, string $fieldName, mixed $value): bool
	{
		if (
			!$this->entityTypeSettings->hasMultifields()
			|| !$item instanceof HasMultifieldsInterface
			|| !in_array($fieldName, ['phone', 'email', 'web', 'im'], true)
		)
		{
			return false;
		}

		$values = $value instanceof DtoCollection ? iterator_to_array($value) : $value;
		if (!is_array($values))
		{
			return false;
		}

		$collection = match ($fieldName)
		{
			'phone' => new PhoneCollection(),
			'email' => new EmailCollection(),
			'web' => new WebCollection(),
			'im' => new ImCollection(),
		};
		$validValueTypes = TypeRepository::getValueTypes(strtoupper($fieldName));
		foreach ($values as $multifield)
		{
			if ($multifield instanceof MultifieldDto)
			{
				$multifield = $multifield->toArray(true);
			}
			if (!is_array($multifield))
			{
				continue;
			}

			$valueTypeId = $multifield['valueTypeId'] ?? null;
			$multifieldValue = $multifield['value'] ?? null;
			if (
				!is_string($valueTypeId)
				|| !is_string($multifieldValue)
				|| trim($valueTypeId) === ''
				|| trim($multifieldValue) === ''
			)
			{
				throw new DtoValidationException([
					new Error(sprintf('The %s multifield requires valueTypeId and value.', $fieldName)),
				]);
			}

			$valueTypeResult = (new InArrayValidator($validValueTypes, strict: true))->validate($valueTypeId);
			if (!$valueTypeResult->isSuccess())
			{
				throw new DtoValidationException($valueTypeResult->getErrors());
			}

			$collection->add(match ($fieldName)
			{
				'phone' => new PhoneValue((string)$valueTypeId, (string)$multifieldValue),
				'email' => new EmailValue((string)$valueTypeId, (string)$multifieldValue),
				'web' => new WebValue((string)$valueTypeId, (string)$multifieldValue),
				'im' => new ImValue((string)$valueTypeId, (string)$multifieldValue),
			});
		}

		match ($fieldName)
		{
			'phone' => $item->setPhones($collection),
			'email' => $item->setEmails($collection),
			'web' => $item->setWebs($collection),
			'im' => $item->setIms($collection),
		};

		return true;
	}

	private function getContactCompanyIds(Contact $item): ?array
	{
		$bindings = $item->getCompanyBindings();
		if ($bindings === null)
		{
			return null;
		}

		return array_map(
			static fn (CompanyBinding $binding): int => $binding->getCompanyId(),
			$bindings->getAll(),
		);
	}

	private function getMultifieldDtos(
		?iterable $values,
		string $typeId,
		array $select = [],
		bool $isFullRelationSelected = false,
	): ?DtoCollection
	{
		if ($values === null)
		{
			return null;
		}

		$collection = new DtoCollection(MultifieldDto::class);
		foreach ($values as $value)
		{
			$dto = new MultifieldDto();
			if ($select === [] || in_array('id', $select, true))
			{
				$dto->id = $value->getId();
			}
			if ($select === [] || in_array('valueTypeId', $select, true))
			{
				$dto->valueTypeId = $value->getValueType();
			}
			if (
				$isFullRelationSelected
				|| in_array('valueType', $select, true)
			)
			{
				$dto->valueType = $this->getMultifieldValueTypeDto($typeId, $value->getValueType());
			}
			if ($select === [] || in_array('value', $select, true))
			{
				$dto->value = $value->getValue();
			}
			$collection->add($dto);
		}

		return $collection;
	}

	private function getMultifieldValueTypeDto(string $typeId, string $valueTypeId): MultifieldValueTypeDto
	{
		$dto = new MultifieldValueTypeDto();
		$dto->id = $valueTypeId;
		$dto->name = \CCrmFieldMulti::GetEntityNameByComplex($typeId . '_' . $valueTypeId, true) ?: null;
		$dto->nameShort = \CCrmFieldMulti::GetEntityNameByComplex($typeId . '_' . $valueTypeId, false) ?: null;

		return $dto;
	}

	protected function mapCategoriesFieldToItem(Item $item, string $fieldName, mixed $value): bool
	{
		if (!$this->entityTypeSettings->hasCategories() || !$item instanceof HasCategoriesInterface)
		{
			return false;
		}

		return (bool)match ($fieldName)
		{
			'categoryId' => $item->setCategoryId($value),
			default => null,
		};
	}

	protected function getItemCategoriesFieldValue(Item $item, string $fieldName): mixed
	{
		if (!$this->entityTypeSettings->hasCategories() || !$item instanceof HasCategoriesInterface)
		{
			return null;
		}

		return match ($fieldName)
			{
				'categoryId' => $item->getCategoryId(),
				default => null,
			};
	}

	protected function mapContactsFieldToItem(Item $item, string $fieldName, mixed $value): bool
	{
		if (!$this->entityTypeSettings->hasContactBindings() || !$item instanceof HasContactBindingsInterface)
		{
			return false;
		}

		if ($fieldName === 'contactsId' && is_array($value))
		{
			$contactBindingsCollection = new ContactBindingCollection();
			foreach ($value as $index => $contactId)
			{
				$contactBinding = new ContactBinding((int)$contactId, isPrimary: $index === 0);
				$contactBindingsCollection->add($contactBinding);
			}
			$item->setContactBindings($contactBindingsCollection);

			return true;
		}

		return false;
	}

	protected function getItemContactsFieldValue(Item $item, string $fieldName): mixed
	{
		if (!$this->entityTypeSettings->hasContactBindings() || !$item instanceof HasContactBindingsInterface)
		{
			return null;
		}

		if ($fieldName !== 'contactsId' && $fieldName !== 'contactId')
		{
			return null;
		}

		$contactBindings = $item->getContactBindings();
		if ($contactBindings === null)
		{
			return null;
		}

		if ($fieldName === 'contactId')
		{
			return $contactBindings->getPrimary()?->getContactId();
		}

		return array_map(
			static fn (ContactBinding $contactBinding) => $contactBinding->getContactId(),
			$contactBindings->getAll(),
		);
	}

	protected function mapCompanyFieldToItem(Item $item, string $fieldName, mixed $value): bool
	{
		if (!$this->entityTypeSettings->hasCompany() || !$item instanceof HasCompanyInterface)
		{
			return false;
		}

		if ($fieldName === 'companyId')
		{
			$item->setCompanyId($value);

			return true;
		}

		return false;
	}

	protected function getItemCompanyFieldValue(Item $item, string $fieldName): mixed
	{
		if (!$this->entityTypeSettings->hasCompany() || !$item instanceof HasCompanyInterface)
		{
			return null;
		}

		if ($fieldName !== 'companyId')
		{
			return null;
		}

		$companyId = $item->getCompanyId();

		return $companyId > 0 ? $companyId : null;
	}

	protected function mapMyCompanyFieldToItem(Item $item, string $fieldName, mixed $value): bool
	{
		if (!$this->entityTypeSettings->hasMyCompany() || !method_exists($item, 'setMyCompanyId'))
		{
			return false;
		}

		if ($fieldName === 'myCompanyId')
		{
			$item->setMyCompanyId($value);

			return true;
		}

		return false;
	}

	protected function getItemMyCompanyFieldValue(Item $item, string $fieldName): mixed
	{
		if (!$this->entityTypeSettings->hasMyCompany() || !method_exists($item, 'getMyCompanyId'))
		{
			return null;
		}

		if ($fieldName !== 'myCompanyId')
		{
			return null;
		}

		$myCompanyId = $item->getMyCompanyId();

		return $myCompanyId > 0 ? $myCompanyId : null;
	}

	protected function mapProductsFieldToItem(Item $item, string $fieldName, mixed $value): bool
	{
		if (!$item instanceof HasProductsInterface || (!$this->entityTypeSettings->hasProducts() && !($item instanceof Company && $fieldName === 'currencyId')))
		{
			return false;
		}

		return (bool)match ($fieldName)
		{
			'opportunity' => $item->setOpportunity($value),
			'isManualOpportunity' => $item->setIsManualOpportunity($value),
			'currencyId' => $item->setCurrencyId($value),
			default => null,
		};
	}

	protected function getItemProductsFieldValue(Item $item, string $fieldName): mixed
	{
		if (!$item instanceof HasProductsInterface || (!$this->entityTypeSettings->hasProducts() && !($item instanceof Company && $fieldName === 'currencyId')))
		{
			return null;
		}

		return match ($fieldName)
			{
				'opportunity' => $item->getOpportunity(),
				'isManualOpportunity' => $item->getIsManualOpportunity(),
				'taxValue' => $item->getTaxValue(),
				'currencyId' => $item->getCurrencyId(),
				default => null,
			};
	}

	protected function mapStagesFieldToItem(Item $item, string $fieldName, mixed $value): bool
	{
		if (!$this->entityTypeSettings->hasStages() || !$item instanceof HasStagesInterface)
		{
			return false;
		}

		return (bool)match ($fieldName)
			{
				'stageId' => $item->setStageId($value),
				default => null,
			};
	}

	protected function getItemStagesFieldValue(Item $item, string $fieldName): mixed
	{
		if (!$this->entityTypeSettings->hasStages() || !$item instanceof HasStagesInterface)
		{
			return null;
		}

		return match ($fieldName)
			{
				'stageId' => $item->getStageId(),
				'stageSemanticId' => $item->getStageSemanticId(),
				'movedTime' => $item->getMovedTime(),
				'movedById' => $item->getMovedById(),
				'closed' => $item->getClosed(),
				default => null,
			};
	}

	protected function mapBeginCloseDatesFieldToItem(Item $item, string $fieldName, mixed $value): bool
	{
		if (!$this->entityTypeSettings->hasBeginCloseDates() || !$item instanceof HasBeginCloseDatesInterface)
		{
			return false;
		}

		return (bool)match ($fieldName)
			{
				'beginTime' => $item->setBeginDate($value),
				'closeTime' => $item->setCloseDate($value),
				default => null,
			};
	}

	protected function getItemBeginCloseDatesFieldValue(Item $item, string $fieldName): mixed
	{
		if (!$this->entityTypeSettings->hasBeginCloseDates() || !$item instanceof HasBeginCloseDatesInterface)
		{
			return null;
		}

		return match ($fieldName)
			{
				'beginTime' => $item->getBeginDate(),
				'closeTime' => $item->getCloseDate(),
				default => null,
			};
	}

	protected function mapSourcesFieldToItem(Item $item, string $fieldName, mixed $value): bool
	{
		if (!$this->entityTypeSettings->hasSource())
		{
			return false;
		}

		return (bool)match ($fieldName)
			{
				'sourceId' => $item->setSourceId($value),
				'sourceDescription' => $item->setSourceDescription($value),
				default => null,
			};
	}

	protected function getItemSourcesFieldValue(Item $item, string $fieldName): mixed
	{
		if (!$this->entityTypeSettings->hasSource())
		{
			return null;
		}

		return match ($fieldName)
			{
				'sourceId' => $item->getSourceId(),
				'sourceDescription' => $item->getSourceDescription(),
				default => null,
			};
	}

	protected function mapObserversFieldToItem(Item $item, string $fieldName, mixed $value): bool
	{
		if (!$this->entityTypeSettings->hasObservers())
		{
			return false;
		}

		return (bool)match ($fieldName)
			{
				'observersId' => $item->setObservers($value),
				default => null,
			};
	}

	protected function getItemObserversFieldValue(Item $item, string $fieldName): mixed
	{
		if (!$this->entityTypeSettings->hasObservers() || $fieldName !== 'observersId')
		{
			return null;
		}

		return $item->getObservers();
	}

	protected function getItemObserversRelationValue(Item $item, string $fieldName, array $select): ?DtoCollection
	{
		if (!$this->entityTypeSettings->hasObservers() || $fieldName !== 'observers')
		{
			return null;
		}

		$observerEmployees = $item->getObserversEmployees();
		if ($observerEmployees === null)
		{
			return null;
		}

		$collection = new DtoCollection(EmployeeDto::class);
		foreach ($observerEmployees as $observerEmployee)
		{
			$dto = EmployeeDto::fromEntity($observerEmployee, $select);
			if ($dto !== null)
			{
				$collection->add($dto);
			}
		}

		return $collection;
	}

	protected function getItemEmployeeRelationValue(Item $item, string $fieldName, array $select): ?EmployeeDto
	{
		if ($item instanceof HasStagesInterface && $fieldName === Item::movedBy)
		{
			return EmployeeDto::fromEntity($item->getMovedBy(), $select);
		}

		return match ($fieldName)
			{
				Item::createdBy => EmployeeDto::fromEntity($item->getCreatedBy(), $select),
				Item::updatedBy => EmployeeDto::fromEntity($item->getUpdatedBy(), $select),
				Item::lastActivityBy => EmployeeDto::fromEntity($item->getLastActivityBy(), $select),
				Item::assignedBy => EmployeeDto::fromEntity($item->getAssignedBy(), $select),
				default => null,
			};
	}

	protected function getItemUtmRelationValue(Item $item, string $fieldName, array $select): ?UtmDto
	{
		if (!$this->entityTypeSettings->hasCrmTracking() || $fieldName !== Item::utm)
		{
			return null;
		}

		$utm = $item->getUtm();
		if ($utm === null)
		{
			return null;
		}

		return UtmDto::fromEntity($utm, $select);
	}

	protected function getItemDictionaryRelationValue(Item $item, string $fieldName, array $select): mixed
	{
		$statusRelation = match (true)
		{
			$item instanceof Company && $fieldName === 'industry' => [IndustryDto::class, $item->getIndustry(), StatusTable::ENTITY_ID_INDUSTRY],
			$item instanceof Company && $fieldName === 'employees' => [EmployeesDto::class, $item->getEmployees(), StatusTable::ENTITY_ID_EMPLOYEES],
			$item instanceof Company && $fieldName === 'type' => [CompanyTypeDto::class, $item->getTypeId(), StatusTable::ENTITY_ID_COMPANY_TYPE],
			$item instanceof Contact && $fieldName === 'type' => [ContactTypeDto::class, $item->getTypeId(), StatusTable::ENTITY_ID_CONTACT_TYPE],
			($item instanceof Contact || $item instanceof Lead) && $fieldName === 'honorific' => [HonorificDto::class, $item->getHonorific(), StatusTable::ENTITY_ID_HONORIFIC],
			default => null,
		};
		if ($statusRelation !== null)
		{
			return $this->createStatusDto($statusRelation[0], $statusRelation[1], $statusRelation[2], $select);
		}

		if ($item instanceof Quote && $fieldName === 'personType')
		{
			$personTypeCode = PersonTypeIdProvider::getPersonTypeCode($item->getPersonTypeId());
			if ($personTypeCode === null)
			{
				return null;
			}

			$dto = new PersonTypeDto();
			if ($this->shouldSelectField($select, 'id'))
			{
				$dto->id = $personTypeCode;
			}
			if ($this->shouldSelectField($select, 'name'))
			{
				$dto->name = $this->getPersonTypeName($item->getPersonTypeId());
			}

			return $dto;
		}

		$loadedRelation = match ($fieldName)
		{
			'stage' => $item instanceof HasStagesInterface ? $item->getStage() : null,
			'previousStage' => $item instanceof Deal ? $item->getPreviousStage() : null,
			'stageSemantic' => $item instanceof HasStagesInterface ? $item->getStageSemantic() : null,
			'source' => $item->getSource(),
			'category' => $item instanceof HasCategoriesInterface ? $item->getCategory() : null,
			'currency' => method_exists($item, 'getCurrency') ? $item->getCurrency() : null,
			'webform' => $item->getWebform(),
			'type' => $item instanceof Deal ? $item->getType() : null,
			default => null,
		};
		if ($loadedRelation === null)
		{
			return null;
		}

		return match (true)
		{
			$loadedRelation instanceof Stage => $this->createStageDto($loadedRelation, $select),
			$loadedRelation instanceof StageSemantic => $this->createStageSemanticDto($loadedRelation, $select),
			$loadedRelation instanceof Source => $this->createSourceDto($loadedRelation, $select),
			$loadedRelation instanceof Category => $this->createCategoryDto($loadedRelation, $select),
			$loadedRelation instanceof Currency => $this->createCurrencyDto($loadedRelation, $select),
			$loadedRelation instanceof Webform => $this->createWebformDto($loadedRelation, $select),
			$loadedRelation instanceof DealType => $this->createDealTypeDto($loadedRelation, $select),
			default => null,
		};
	}

	/** @return array<string, array{name: string, sort: int}> */
	protected function getStatusEntries(string $statusTypeId): array
	{
		static $cache = [];
		if (isset($cache[$statusTypeId]))
		{
			return $cache[$statusTypeId];
		}

		$entries = [];
		$result = StatusTable::getList([
			'select' => ['STATUS_ID', 'NAME', 'SORT'],
			'filter' => ['=ENTITY_ID' => $statusTypeId],
		]);
		while ($row = $result->fetch())
		{
			$entries[(string)$row['STATUS_ID']] = [
				'name' => (string)$row['NAME'],
				'sort' => (int)$row['SORT'],
			];
		}

		return $cache[$statusTypeId] = $entries;
	}

	private function createStatusDto(string $dtoClass, ?string $id, string $statusTypeId, array $select): ?Dto
	{
		if ($id === null)
		{
			return null;
		}

		$entry = $this->getStatusEntries($statusTypeId)[$id] ?? null;
		if ($entry === null)
		{
			return null;
		}

		$dto = new $dtoClass();
		if ($this->shouldSelectField($select, 'id'))
		{
			$dto->id = $id;
		}
		if ($this->shouldSelectField($select, 'name'))
		{
			$dto->name = $entry['name'];
		}
		if ($this->shouldSelectField($select, 'sort') && property_exists($dto, 'sort'))
		{
			$dto->sort = $entry['sort'];
		}

		return $dto;
	}

	private function getPersonTypeName(?int $personTypeId): ?string
	{
		if ($personTypeId === null || !\Bitrix\Main\Loader::includeModule('sale'))
		{
			return null;
		}
		if (array_key_exists($personTypeId, $this->personTypeNameById))
		{
			return $this->personTypeNameById[$personTypeId];
		}

		$personType = \Bitrix\Sale\Internals\PersonTypeTable::getById($personTypeId)->fetch();

		return $this->personTypeNameById[$personTypeId] = $personType['NAME'] ?? null;
	}

	protected function getItemRelatedCrmObjectRelationValue(
		Item $item,
		string $fieldName,
		array $select,
		?DtoField $relationField,
	): mixed
	{
		$loadedRelation = $this->getLoadedRelatedItemRelation($item, $fieldName);
		if ($loadedRelation === null)
		{
			return null;
		}

		if ($fieldName === 'contacts' || $fieldName === 'companies')
		{
			$collection = null;
			foreach ($loadedRelation as $relatedItem)
			{
				if (!$relatedItem instanceof Item)
				{
					continue;
				}
				$relatedDto = $this->createRelatedItemDto($relatedItem, $select, $relationField, true);
				$collection ??= new DtoCollection($relatedDto::class);
				$collection->add($relatedDto);
			}

			return $collection ?? new DtoCollection(ItemDto::class);
		}

		if (!$loadedRelation instanceof Item)
		{
			return null;
		}
		return $this->createRelatedItemDto($loadedRelation, $select, $relationField);
	}

	private function getLoadedRelatedItemRelation(Item $item, string $fieldName): mixed
	{
		return match ($fieldName)
		{
			'company' => $item instanceof HasCompanyInterface ? $item->getCompany() : null,
			'companies' => $item instanceof Contact ? $item->getCompanies() : null,
			'myCompany' => method_exists($item, 'getMyCompany') ? $item->getMyCompany() : null,
			'deal' => method_exists($item, 'getDeal') ? $item->getDeal() : null,
			'lead' => method_exists($item, 'getLead') ? $item->getLead() : null,
			'quote' => $item instanceof Deal ? $item->getQuote() : null,
			'contact' => $item instanceof HasContactBindingsInterface ? $item->getContact() : null,
			'contacts' => $item instanceof HasContactBindingsInterface ? $item->getContacts() : null,
			default => $this->getRelatedParentItem($item, $fieldName),
		};
	}

	/** @return Item[] */
	private function getLoadedRelatedItems(Item $item, string $fieldName): array
	{
		$loadedRelation = $this->getLoadedRelatedItemRelation($item, $fieldName);
		if ($loadedRelation instanceof Item)
		{
			return [$loadedRelation];
		}
		if ($fieldName !== 'contacts' && $fieldName !== 'companies')
		{
			return [];
		}

		$relatedItems = [];
		foreach ($loadedRelation ?? [] as $relatedItem)
		{
			if ($relatedItem instanceof Item)
			{
				$relatedItems[] = $relatedItem;
			}
		}

		return $relatedItems;
	}

	private function getRelatedParentItem(Item $item, string $fieldName): ?Item
	{
		if (!str_starts_with($fieldName, 'related'))
		{
			return null;
		}

		$entityType = EntityType::fromCode(substr($fieldName, strlen('related')));

		return $entityType === null ? null : $item->getRelatedItem($entityType);
	}

	protected function getItemLastCommunicationRelationValue(Item $item, string $fieldName, array $select): ?LastCommunicationDto
	{
		if ($fieldName !== 'lastCommunication')
		{
			return null;
		}

		$lastCommunication = $item->getLastCommunication();
		if (!$lastCommunication instanceof LastCommunication)
		{
			return null;
		}

		$dto = new LastCommunicationDto();
		if (empty($select) || in_array('communicationTime', $select, true))
		{
			$dto->communicationTime = $lastCommunication->getCommunicationTime();
		}
		if (empty($select) || in_array('callTime', $select, true))
		{
			$dto->callTime = $lastCommunication->getCallTime();
		}
		if (empty($select) || in_array('emailTime', $select, true))
		{
			$dto->emailTime = $lastCommunication->getEmailTime();
		}
		if (empty($select) || in_array('imolTime', $select, true))
		{
			$dto->imolTime = $lastCommunication->getImolTime();
		}
		if (empty($select) || in_array('webformTime', $select, true))
		{
			$dto->webformTime = $lastCommunication->getWebformTime();
		}

		return $dto;
	}

	private function createRelatedItemDto(
		Item $item,
		array $select,
		?DtoField $relationField = null,
		bool $preserveRequestedDtoClass = false,
	): Dto
	{
		$mappingContext = $this->getRelatedDtoMappingContext(
			$item,
			$select,
			$relationField,
			$preserveRequestedDtoClass,
		);

		return $mappingContext['mapper']->getDtoByItemAndContext($item, ...$mappingContext['context']);
	}

	/**
	 * @return array{mapper: self, context: array{Dto, array<string|int, mixed>, DtoFieldsCollection, array<string, true>}}
	 */
	private function getRelatedDtoMappingContext(
		Item $item,
		array $select,
		?DtoField $relationField = null,
		bool $preserveRequestedDtoClass = false,
	): array
	{
		$targetDtoClass = $relationField?->getElementType() ?? $relationField?->getPropertyType();
		$entityTypeId = $item->getEntityType()->getId();
		$generatedDto = (new ItemDtoGenerator())->generate($entityTypeId);
		$commonFields = ['id', 'entityTypeId', 'canRead', 'title', 'url'];
		$requestedFields = $select === [] ? $commonFields : $select;
		$selectedFields = $requestedFields;
		if (!$item->canRead())
		{
			$selectedFields = array_values(array_intersect($selectedFields, $commonFields));
		}
		$dtoClassFields = $preserveRequestedDtoClass ? $requestedFields : $selectedFields;
		$dtoClass = array_diff($dtoClassFields, $commonFields) === []
			? ItemDto::class
			: (is_string($targetDtoClass) && is_subclass_of($targetDtoClass, Dto::class)
			? $targetDtoClass
			: $generatedDto->getFQCN());
		if (!class_exists($dtoClass))
		{
			Generator::generateByDto($generatedDto);
		}

		$cacheKey = $dtoClass . ':' . serialize($selectedFields);
		if (!isset($this->relatedDtoMappingContexts[$cacheKey]))
		{
			$request = new GetItemRequest($dtoClass);
			$request->select = SelectStructure::create($selectedFields, $dtoClass, $request);
			$mapper = new self(EntityTypeSettings::of($item->getEntityType()), restServer: $this->restServer);
			$this->relatedDtoMappingContexts[$cacheKey] = [
				'mapper' => $mapper,
				'context' => $mapper->prepareDtoMappingContext($request),
			];
		}

		return $this->relatedDtoMappingContexts[$cacheKey];
	}

	private function getItemUrl(Item $item): ?string
	{
		$entityTypeId = $item->getEntityType()->getId();
		$categoryId = $item instanceof HasCategoriesInterface ? $item->getCategoryId() : null;
		$url = Container::getInstance()->getRouter()->getItemDetailUrl($entityTypeId, (int)$item->getId(), $categoryId);

		return $url?->getUri();
	}

	private function createStageDto(Stage $stage, array $select): StageDto
	{
		$dto = new StageDto();
		if ($this->shouldSelectField($select, 'id'))
		{
			$dto->id = $stage->getId();
		}
		if ($this->shouldSelectField($select, 'name'))
		{
			$dto->name = $stage->getName();
		}
		if ($this->shouldSelectField($select, 'sort'))
		{
			$dto->sort = $stage->getSort();
		}
		if ($this->shouldSelectField($select, 'color'))
		{
			$dto->color = $stage->getColor();
		}
		if ($this->shouldSelectField($select, 'semanticId'))
		{
			$dto->semanticId = $stage->getSemanticId();
		}

		return $dto;
	}

	private function createStageSemanticDto(StageSemantic $semantic, array $select): StageSemanticDto
	{
		$dto = new StageSemanticDto();
		if ($this->shouldSelectField($select, 'id'))
		{
			$dto->id = $semantic->getId();
		}
		if ($this->shouldSelectField($select, 'name'))
		{
			$dto->name = $semantic->getName();
		}

		return $dto;
	}

	private function createSourceDto(Source $source, array $select): SourceDto
	{
		$dto = new SourceDto();
		if ($this->shouldSelectField($select, 'id'))
		{
			$dto->id = $source->getId();
		}
		if ($this->shouldSelectField($select, 'name'))
		{
			$dto->name = $source->getName();
		}
		if ($this->shouldSelectField($select, 'sort'))
		{
			$dto->sort = $source->getSort();
		}

		return $dto;
	}

	private function createCategoryDto(Category $category, array $select): CategoryDto
	{
		$dto = new CategoryDto();
		if ($this->shouldSelectField($select, 'id'))
		{
			$dto->id = $category->getId();
		}
		if ($this->shouldSelectField($select, 'name'))
		{
			$dto->name = $category->getName();
		}
		if ($this->shouldSelectField($select, 'sort'))
		{
			$dto->sort = $category->getSort();
		}
		if ($this->shouldSelectField($select, 'isDefault'))
		{
			$dto->isDefault = $category->getIsDefault();
		}

		return $dto;
	}

	private function createCurrencyDto(Currency $currency, array $select): CurrencyDto
	{
		$dto = new CurrencyDto();
		if ($this->shouldSelectField($select, 'id'))
		{
			$dto->id = $currency->getId();
		}
		if ($this->shouldSelectField($select, 'fullName'))
		{
			$dto->fullName = $currency->getFullName();
		}
		if ($this->shouldSelectField($select, 'formatString'))
		{
			$dto->formatString = $currency->getFormatString();
		}
		if ($this->shouldSelectField($select, 'decPoint'))
		{
			$dto->decPoint = $currency->getDecPoint();
		}
		if ($this->shouldSelectField($select, 'thousandsSep'))
		{
			$dto->thousandsSep = $currency->getThousandsSep();
		}
		if ($this->shouldSelectField($select, 'decimals'))
		{
			$dto->decimals = $currency->getDecimals();
		}
		if ($this->shouldSelectField($select, 'hideZero'))
		{
			$dto->hideZero = $currency->getHideZero();
		}
		if ($this->shouldSelectField($select, 'amount'))
		{
			$dto->amount = $currency->getAmount();
		}
		if ($this->shouldSelectField($select, 'amountCount'))
		{
			$dto->amountCount = $currency->getAmountCount();
		}
		if ($this->shouldSelectField($select, 'base'))
		{
			$dto->base = $currency->isBase();
		}
		if ($this->shouldSelectField($select, 'sort'))
		{
			$dto->sort = $currency->getSort();
		}
		if ($this->shouldSelectField($select, 'numericCode'))
		{
			$dto->numericCode = $currency->getNumericCode();
		}
		if ($this->shouldSelectField($select, 'languageId'))
		{
			$dto->languageId = $currency->getLanguageId();
		}
		if ($this->shouldSelectField($select, 'createdTime'))
		{
			$dto->createdTime = $currency->getCreatedTime();
		}
		if ($this->shouldSelectField($select, 'updatedTime'))
		{
			$dto->updatedTime = $currency->getUpdatedTime();
		}
		if ($this->shouldSelectField($select, 'createdById'))
		{
			$dto->createdById = $currency->getCreatedById();
		}
		if ($this->shouldSelectField($select, 'updatedById'))
		{
			$dto->updatedById = $currency->getUpdatedById();
		}

		return $dto;
	}

	private function createWebformDto(Webform $webform, array $select): WebformDto
	{
		$dto = new WebformDto();
		if ($this->shouldSelectField($select, 'id'))
		{
			$dto->id = $webform->getId();
		}
		if ($this->shouldSelectField($select, 'name'))
		{
			$dto->name = $webform->getName();
		}

		return $dto;
	}

	private function createDealTypeDto(DealType $type, array $select): DealTypeDto
	{
		$dto = new DealTypeDto();
		if ($this->shouldSelectField($select, 'id'))
		{
			$dto->id = $type->getId();
		}
		if ($this->shouldSelectField($select, 'name'))
		{
			$dto->name = $type->getName();
		}
		if ($this->shouldSelectField($select, 'sort'))
		{
			$dto->sort = $type->getSort();
		}
		if ($this->shouldSelectField($select, 'isSystem'))
		{
			$dto->isSystem = $type->getIsSystem();
		}

		return $dto;
	}

	private function shouldSelectField(array $select, string $fieldName): bool
	{
		return empty($select) || in_array($fieldName, $select, true);
	}

	protected function mapRelatedFieldToItem(Item $item, string $fieldName, mixed $value): bool
	{
		if (!str_starts_with($fieldName, 'related') || $this->entityTypeSettings->getParentEntityTypesMap() === [])
		{
			return false;
		}

		$requestEntityCode = substr($fieldName, strlen('related'), -strlen('Id'));
		$requestEntityType = EntityType::fromCode($requestEntityCode);
		if ($requestEntityType === null)
		{
			return false;
		}
		$requestEntityTypeId = $requestEntityType->getId();

		$parentEntityTypesMap = $this->entityTypeSettings->getParentEntityTypesMap();
		if (isset($parentEntityTypesMap[$requestEntityTypeId]))
		{
			$item->setParentId($parentEntityTypesMap[$requestEntityTypeId], (int)$value);

			return true;
		}

		return false;
	}

	protected function mapUtmFieldToItem(Item $item, string $fieldName, mixed $value): bool
	{
		if (!$this->entityTypeSettings->hasCrmTracking())
		{
			return false;
		}

		return (bool)match ($fieldName)
			{
				'utm' => $item->setUtm($this->mapUtmDataToEntity($value)),
				default => null,
			};
	}

	protected function mapTrackingSourceFieldToItem(Item $item, string $fieldName, mixed $value): bool
	{
		if (!$this->entityTypeSettings->hasCrmTracking() || $fieldName !== Item::trackingSourceId)
		{
			return false;
		}

		$item->setTrackingSourceId($value === null ? null : (int)$value);

		return true;
	}

	protected function getItemTrackingSourceFieldValue(Item $item, string $fieldName): ?int
	{
		if (!$this->entityTypeSettings->hasCrmTracking() || $fieldName !== Item::trackingSourceId)
		{
			return null;
		}

		return $item->getTrackingSourceId();
	}

	protected function mapUtmDataToEntity(mixed $utmData): ?Utm
	{
		if (!is_array($utmData))
		{
			return null;
		}

		$utm = new Utm();
		foreach ($utmData as $fieldName => $value)
		{
			match ($fieldName)
			{
				'source' => $utm->setSource($value),
				'medium' => $utm->setMedium($value),
				'campaign' => $utm->setCampaign($value),
				'content' => $utm->setContent($value),
				'term' => $utm->setTerm($value),
				default => null,
			};
		}

		return $utm;
	}
}
