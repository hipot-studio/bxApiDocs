<?php

namespace Bitrix\Rest\V3\Dto\Meta;

use Bitrix\Main\Localization\LocalizableMessage;
use Bitrix\Main\SystemException;
use Bitrix\Main\Validation\Rule\PropertyValidationAttributeInterface;
use Bitrix\Rest\V3\Dto\DtoField;
use Bitrix\Rest\V3\Dto\DtoFieldRelation;

/**
 * Immutable description of a DTO field without a value.
 * Built from a field record of the description cache with the same checks as DtoField::fromArray().
 *
 * @internal
 */
final class FieldMeta implements FieldMetaInterface
{
	private const SUPPORTED_TYPES = [
		DtoField::DTO_FIELD_TYPE_PROPERTY,
		DtoField::DTO_FIELD_TYPE_USER_FIELD,
		DtoField::DTO_FIELD_TYPE_DYNAMIC_FIELD,
	];

	private readonly LocalizableMessage|string $title;

	private readonly ?DtoFieldRelation $relation;

	private function __construct(
		private readonly string $propertyName,
		private readonly string $propertyType,
		private readonly string $type,
		LocalizableMessage|string|null $title,
		private readonly LocalizableMessage|string|null $description,
		private readonly array $validationRules,
		private readonly ?array $requiredGroups,
		private readonly bool $filterable,
		private readonly bool $sortable,
		private readonly ?array $editableGroups,
		private readonly bool $multiple,
		private readonly bool $nullable,
		private readonly ?string $elementType,
		null|array|DtoFieldRelation $relation,
		private readonly ?string $dynamicEnumProvider,
	)
	{
		if (!in_array($type, self::SUPPORTED_TYPES, true))
		{
			throw new SystemException('Unsupported type: ' . $propertyType);
		}

		$this->relation = is_array($relation) ? DtoFieldRelation::fromArray($relation) : $relation;
		$this->title = $title ?? $propertyName;
	}

	public static function fromArray(array $data): self
	{
		if (empty($data['propertyName']))
		{
			throw new SystemException('propertyName is required and cannot be empty');
		}

		if (empty($data['propertyType']))
		{
			throw new SystemException('PropertyType is required and cannot be empty');
		}

		if (empty($data['type']))
		{
			throw new SystemException('PropertyType is required and cannot be empty');
		}

		return new self(
			propertyName: $data['propertyName'],
			propertyType: $data['propertyType'],
			type: $data['type'],
			title: $data['title'] ?? null,
			description: $data['description'] ?? null,
			validationRules: $data['validationRules'] ?? null,
			requiredGroups: $data['requiredGroups'] ?? null,
			filterable: $data['filterable'] ?? false,
			sortable: $data['sortable'] ?? false,
			editableGroups: $data['editableGroups'] ?? null,
			multiple: $data['multiple'] ?? false,
			nullable: $data['nullable'] ?? false,
			elementType: $data['elementType'] ?? null,
			relation: $data['relation'] ?? null,
			dynamicEnumProvider: $data['dynamicEnumProvider'] ?? null,
		);
	}

	/**
	 * Returns a new value holder equal to DtoField::fromArray() of the same record.
	 * The relation and the rule and message objects are shared with this description.
	 */
	public function toDtoField(): DtoField
	{
		return new DtoField(
			propertyName: $this->propertyName,
			propertyType: $this->propertyType,
			type: $this->type,
			title: $this->title,
			description: $this->description,
			validationRules: $this->validationRules,
			requiredGroups: $this->requiredGroups,
			filterable: $this->filterable,
			sortable: $this->sortable,
			editableGroups: $this->editableGroups,
			multiple: $this->multiple,
			nullable: $this->nullable,
			elementType: $this->elementType,
			relation: $this->relation,
			dynamicEnumProvider: $this->dynamicEnumProvider,
		);
	}

	public function getPropertyName(): string
	{
		return $this->propertyName;
	}

	public function getPropertyType(): string
	{
		return $this->propertyType;
	}

	public function getType(): string
	{
		return $this->type;
	}

	public function getTitle(): LocalizableMessage|string
	{
		return $this->title;
	}

	public function getDescription(): LocalizableMessage|string|null
	{
		return $this->description;
	}

	/**
	 * @return PropertyValidationAttributeInterface[]
	 */
	public function getValidationRules(): array
	{
		return $this->validationRules;
	}

	public function getRequiredGroups(): ?array
	{
		return $this->requiredGroups;
	}

	public function getEditableGroups(): ?array
	{
		return $this->editableGroups;
	}

	public function isFilterable(): bool
	{
		return $this->filterable;
	}

	public function isSortable(): bool
	{
		return $this->sortable;
	}

	public function isMultiple(): bool
	{
		return $this->multiple;
	}

	public function isNullable(): bool
	{
		return $this->nullable;
	}

	public function getElementType(): ?string
	{
		return $this->elementType;
	}

	public function getRelation(): ?DtoFieldRelation
	{
		return $this->relation;
	}

	public function getDynamicEnumProvider(): ?string
	{
		return $this->dynamicEnumProvider;
	}

	public function toArray(): array
	{
		return [
			'propertyName' => $this->propertyName,
			'propertyType' => $this->propertyType,
			'type' => $this->type,
			'title' => $this->title,
			'description' => $this->description,
			'validationRules' => $this->validationRules,
			'requiredGroups' => $this->requiredGroups,
			'filterable' => $this->filterable,
			'sortable' => $this->sortable,
			'editableGroups' => $this->editableGroups,
			'multiple' => $this->multiple,
			'nullable' => $this->nullable,
			'elementType' => $this->elementType,
			'relation' => $this->relation?->toArray(),
			'dynamicEnumProvider' => $this->dynamicEnumProvider,
		];
	}
}
