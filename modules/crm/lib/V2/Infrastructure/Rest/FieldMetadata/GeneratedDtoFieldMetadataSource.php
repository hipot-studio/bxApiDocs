<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\FieldMetadata;

use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item\ItemDtoGenerator;
use Bitrix\Crm\V2\Internal\Entity\FieldMetadata\FieldMetadata;
use Bitrix\Crm\V2\Internal\Repository\FieldMetadata\FieldMetadataSourceInterface;
use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Rest\V3\Dto\DtoCollection;
use Bitrix\Rest\V3\Dto\DtoField;
use Bitrix\Rest\V3\Structure\Structure;

/**
 * @internal
 */
final class GeneratedDtoFieldMetadataSource implements FieldMetadataSourceInterface
{
	public function __construct(
		private readonly ItemDtoGenerator $itemDtoGenerator,
	)
	{
	}

	public function getAll(EntityType $entityType): array
	{
		$dtoFields = $this->getGeneratedDtoFields($entityType);

		return $this->mapFields($dtoFields);
	}

	public function getByName(EntityType $entityType, string $name): ?FieldMetadata
	{
		$fields = $this->getGeneratedDtoFields($entityType);
		$fieldTypes = $this->getFieldTypes($fields);

		foreach ($fields as $dtoField)
		{
			if ($dtoField->getPropertyName() !== $name)
			{
				continue;
			}

			return $this->mapField($dtoField, $fieldTypes);
		}

		return null;
	}

	/**
	 * @return DtoField[]
	 */
	private function getGeneratedDtoFields(EntityType $entityType): array
	{
		$generatedDto = $this->itemDtoGenerator->generate($entityType->getId());
		$dtoClass = $generatedDto->getFQCN();
		if (class_exists($dtoClass, false))
		{
			Structure::addDto($dtoClass::create());
		}

		return $generatedDto->fields;
	}

	/**
	 * @param DtoField[] $dtoFields
	 * @return FieldMetadata[]
	 */
	private function mapFields(array $dtoFields): array
	{
		$fieldTypes = $this->getFieldTypes($dtoFields);

		return array_map(
			fn(DtoField $dtoField): FieldMetadata => $this->mapField($dtoField, $fieldTypes),
			$dtoFields,
		);
	}

	/**
	 * @param DtoField[] $dtoFields
	 * @return array<string, string>
	 */
	private function getFieldTypes(array $dtoFields): array
	{
		$fieldTypes = [];
		foreach ($dtoFields as $dtoField)
		{
			$fieldTypes[$dtoField->getPropertyName()] = $dtoField->getType();
		}

		return $fieldTypes;
	}

	/**
	 * @param array<string, string> $fieldTypes
	 */
	private function mapField(DtoField $dtoField, array $fieldTypes): FieldMetadata
	{
		$relation = $dtoField->getRelation();
		$sourceUserFieldName = null;
		if (
			$relation !== null
			&& $relation->thisField !== $dtoField->getPropertyName()
			&& ($fieldTypes[$relation->thisField] ?? null) === DtoField::DTO_FIELD_TYPE_USER_FIELD
		)
		{
			$sourceUserFieldName = $relation->thisField;
		}

		return new FieldMetadata(
			name: $dtoField->getPropertyName(),
			type: $dtoField->getPropertyType(),
			elementType: $dtoField->getElementType(),
			multiple: $dtoField->isMultiple() || $dtoField->getPropertyType() === DtoCollection::class,
			title: $dtoField->toArray()['title'] ?? null,
			description: $dtoField->getDescription(),
			validationRules: $dtoField->getValidationRules(),
			requiredGroups: $dtoField->getRequiredGroups(),
			filterable: $dtoField->isFilterable(),
			sortable: $dtoField->isSortable(),
			editableGroups: $dtoField->getEditableGroups(),
			isUserField: $dtoField->getType() === DtoField::DTO_FIELD_TYPE_USER_FIELD,
			sourceUserFieldName: $sourceUserFieldName,
		);
	}
}
