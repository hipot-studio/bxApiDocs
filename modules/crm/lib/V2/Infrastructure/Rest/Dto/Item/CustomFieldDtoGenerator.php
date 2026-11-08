<?php

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item;

use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item\Common\AddressDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item\Common\CustomFieldDataDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item\Common\ItemIdentifierDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item\Common\MoneyDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Validation\Provider\IBlockElementIdProvider;
use Bitrix\Crm\V2\Infrastructure\Rest\Validation\Provider\IBlockSectionIdProvider;
use Bitrix\Crm\V2\Infrastructure\Rest\Validation\Provider\ItemIdentifierProvider;
use Bitrix\Crm\V2\Infrastructure\Rest\Validation\Provider\ItemProvider;
use Bitrix\Crm\V2\Infrastructure\Rest\Validation\Provider\ObserverUserIdProvider;
use Bitrix\Crm\V2\Infrastructure\Rest\Validation\Provider\StatusIdProvider;
use Bitrix\Crm\V2\Infrastructure\Rest\Validation\Provider\CustomFieldEnumIdProvider;
use Bitrix\Crm\V2\Infrastructure\Rest\Validation\Rule\ElementsInArrayFrom;
use Bitrix\Crm\V2\Infrastructure\Rest\Validation\Rule\ElementsItemIdentifier;
use Bitrix\Crm\V2\Infrastructure\Rest\Validation\Rule\InArrayFrom;
use Bitrix\Crm\V2\Infrastructure\Rest\Validation\Rule\ItemIdentifier;
use Bitrix\Crm\V2\Internal\Integration\Rest\File\RestIntegrationFactory;
use Bitrix\Crm\V2\Internal\Service\Item\CustomFieldDescriptor;
use Bitrix\Crm\V2\Internal\Service\Item\CustomFieldRegistry;
use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Main\Type\Date;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\Validation\Rule\PropertyValidationAttributeInterface;
use Bitrix\Rest\V3\Dto\Dto;
use Bitrix\Rest\V3\Dto\DtoCollection;
use Bitrix\Rest\V3\Dto\DtoField;
use Bitrix\Rest\V3\Dto\DtoFieldRelation;
use Bitrix\Rest\V3\Realisation\Dto\FileDto;

class CustomFieldDtoGenerator
{
	public function __construct(
		protected readonly CustomFieldRegistry $customFieldRegistry,
		private readonly ?RestIntegrationFactory $restIntegrationFactory = null,
		?RestCustomFieldDescriptorProvider $customFieldDescriptorProvider = null,
	)
	{
		$this->customFieldDescriptorProvider = $customFieldDescriptorProvider
			?? new RestCustomFieldDescriptorProvider($this->customFieldRegistry)
		;
	}

	private readonly RestCustomFieldDescriptorProvider $customFieldDescriptorProvider;

	/**
	 * @return DtoField[]
	 */
	public function generateCustomFields(EntityType $entityType): array
	{
		$fields = [];
		$customFieldDescriptorsMapByName = $this->customFieldDescriptorProvider
			->getCustomFieldDescriptorsMapByName($entityType)
		;
		foreach ($customFieldDescriptorsMapByName as $fieldDescriptor)
		{
			$field = $this->getCustomField($fieldDescriptor);
			if ($field !== null)
			{
				$fields[] = $field;
				$fields[] = $this->getCustomDataField($fieldDescriptor);
			}
		}

		return $fields;
	}

	public function generateCustomField(CustomFieldDescriptor $fieldDescriptor): ?DtoField
	{
		return $this->getCustomField($fieldDescriptor);
	}

	protected function getCustomField(CustomFieldDescriptor $fieldDescriptor): ?DtoField
	{
		if ($fieldDescriptor->type === 'crm' && $fieldDescriptor->entityTypesId === [])
		{
			return null;
		}

		$propertyType = $this->getPropertyTypeForCustomFieldType($fieldDescriptor);
		if ($propertyType === null)
		{
			return null;
		}

		$isDtoCollection = $fieldDescriptor->isMultiple && is_subclass_of($propertyType, Dto::class);
		$isFile = $fieldDescriptor->type === 'file';
		$isMultiple = $fieldDescriptor->isMultiple && (!$isDtoCollection || $isFile);
		$field = new DtoField(
			propertyName: $fieldDescriptor->name,
			propertyType: $isDtoCollection ? DtoCollection::class : $propertyType,
			type: DtoField::DTO_FIELD_TYPE_USER_FIELD,
			validationRules: $this->getValidationRulesForCustomFieldType($fieldDescriptor),
			filterable: !$isFile && $fieldDescriptor->isFilterable,
			sortable: !$isFile && $fieldDescriptor->isSortable,
			editableGroups: [],
			multiple: $isMultiple,
			nullable: !$fieldDescriptor->isRequired,
			elementType: $isDtoCollection ? $propertyType : null,
		);

		return $field;
	}

	protected function getCustomDataField(CustomFieldDescriptor $fieldDescriptor): DtoField
	{
		$propertyType = CustomFieldDataDto::class;

		$field = new DtoField(
			propertyName: $fieldDescriptor->name . '_data',
			propertyType: $fieldDescriptor->isMultiple ? DtoCollection::class : $propertyType,
			type: DtoField::DTO_FIELD_TYPE_DYNAMIC_FIELD,
			multiple: false,
			elementType: $fieldDescriptor->isMultiple ? $propertyType : null,
		);
		$relation = new DtoFieldRelation(
			thisField: $fieldDescriptor->name,
			refField: 'value',
			multiple: $fieldDescriptor->isMultiple,
		);
		$field->setRelation($relation);

		return $field;
	}

	protected function getPropertyTypeForCustomFieldType(CustomFieldDescriptor $fieldDescriptor): ?string
	{
		if ($fieldDescriptor->type === 'file')
		{
			$restIntegrationFactory = $this->restIntegrationFactory ?? new RestIntegrationFactory();
			if (!$restIntegrationFactory->isFileContractAvailable())
			{
				return null;
			}

			return FileDto::class;
		}

		return match ($fieldDescriptor->type)
		{
			'integer' => 'int',
			'double' => 'float',
			'string' => 'string',
			'rich_text' => 'string',
			'boolean' => 'bool',
			'url' => 'string',
			'datetime' => DateTime::class,
			'date' => Date::class,
			'money' => MoneyDto::class,
			'address' => AddressDto::class,
			'enumeration' => 'int',
			'crm' => count((array)$fieldDescriptor->entityTypesId) > 1
				? ItemIdentifierDto::class
				: 'int',
			'crm_status' => 'string',
			'employee' => 'int',
			'iblock_section' => 'int',
			'iblock_element' => 'int',
			default => null,
		};
	}

	/**
	 * @return PropertyValidationAttributeInterface[]
	 */
	protected function getValidationRulesForCustomFieldType(CustomFieldDescriptor $fieldDescriptor): array
	{
		return match ($fieldDescriptor->type)
		{
			'enumeration' => $this->getValidationRulesForCustomFieldEnumeration($fieldDescriptor),
			'crm' => $this->getValidationRulesForCustomFieldCrm($fieldDescriptor),
			'crm_status' => $this->getValidationRulesForCustomFieldCrmStatus($fieldDescriptor),
			'employee' => $this->getValidationRulesForCustomFieldEmployee($fieldDescriptor),
			'iblock_section' => $this->getValidationRulesForCustomFieldIBlockSection($fieldDescriptor),
			'iblock_element' => $this->getValidationRulesForCustomFieldIBlockElement($fieldDescriptor),
			default => [],
		};
	}

	/**
	 * @return PropertyValidationAttributeInterface[]
	 */
	protected function getValidationRulesForCustomFieldEnumeration(CustomFieldDescriptor $fieldDescriptor): array
	{
		return [
			$this->getInArrayValidationRule(
				$fieldDescriptor->isMultiple,
				CustomFieldEnumIdProvider::class,
				['customFieldId' => $fieldDescriptor->id],
			),
		];
	}

	/**
	 * @return PropertyValidationAttributeInterface[]
	 */
	protected function getValidationRulesForCustomFieldCrm(CustomFieldDescriptor $fieldDescriptor): array
	{
		if (count((array)$fieldDescriptor->entityTypesId) > 1)
		{
			return [
				$fieldDescriptor->isMultiple
					? new ElementsItemIdentifier(
					$fieldDescriptor->entityTypesId,
					ItemIdentifierProvider::class,
					showTypeValues: true,
				)
					: new ItemIdentifier(
					$fieldDescriptor->entityTypesId,
					ItemIdentifierProvider::class,
					showTypeValues: true,
				),
			];
		}

		return [
			$this->getInArrayValidationRule(
				$fieldDescriptor->isMultiple,
				ItemProvider::class,
				['entityTypeId' => $fieldDescriptor->entityTypesId[0] ?? 0],
			),
		];
	}

	/**
	 * @return PropertyValidationAttributeInterface[]
	 */
	protected function getValidationRulesForCustomFieldCrmStatus(CustomFieldDescriptor $fieldDescriptor): array
	{
		return [
			$this->getInArrayValidationRule(
				$fieldDescriptor->isMultiple,
				StatusIdProvider::class,
				['statusTypeId' => $fieldDescriptor->statusTypeId],
				showValues: true,
			),
		];
	}

	/**
	 * @return PropertyValidationAttributeInterface[]
	 */
	protected function getValidationRulesForCustomFieldEmployee(CustomFieldDescriptor $fieldDescriptor): array
	{
		return [
			$this->getInArrayValidationRule(
				$fieldDescriptor->isMultiple,
				ObserverUserIdProvider::class,
			),
		];
	}

	/**
	 * @return PropertyValidationAttributeInterface[]
	 */
	protected function getValidationRulesForCustomFieldIBlockSection(CustomFieldDescriptor $fieldDescriptor): array
	{
		return [
			$this->getInArrayValidationRule(
				$fieldDescriptor->isMultiple,
				IBlockSectionIdProvider::class,
				['iBlockId' => $fieldDescriptor->iBlockId],
			),
		];
	}

	/**
	 * @return PropertyValidationAttributeInterface[]
	 */
	protected function getValidationRulesForCustomFieldIBlockElement(CustomFieldDescriptor $fieldDescriptor): array
	{
		return [
			$this->getInArrayValidationRule(
				$fieldDescriptor->isMultiple,
				IBlockElementIdProvider::class,
				['iBlockId' => $fieldDescriptor->iBlockId],
			),
		];
	}

	protected function getInArrayValidationRule(
		bool $isMultiple,
		string $provider,
		array $contextArgs = [],
		bool $showValues = false,
	): PropertyValidationAttributeInterface
	{
		return
			$isMultiple
				? new ElementsInArrayFrom($provider, $contextArgs, showValues: $showValues)
				: new InArrayFrom($provider, $contextArgs, showValues: $showValues);
	}
}
