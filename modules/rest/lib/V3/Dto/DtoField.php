<?php

namespace Bitrix\Rest\V3\Dto;

use Bitrix\Main\Localization\LocalizableMessage;
use Bitrix\Main\SystemException;
use Bitrix\Main\Type\Contract\Arrayable;
use Bitrix\Main\Validation\Rule\PropertyValidationAttributeInterface;

class DtoField implements Arrayable
{
	public const DTO_FIELD_TYPE_PROPERTY = 'property';
	public const DTO_FIELD_TYPE_USER_FIELD = 'userField';
	public const DTO_FIELD_TYPE_DYNAMIC_FIELD = 'dynamicField';

	private mixed $value;

	private bool $initialized = false;

	private ?DtoFieldRelation $relation = null;

	public function __construct(
		private string $propertyName,
		private string $propertyType,
		private string $type,
		private LocalizableMessage|string|null $title = null,
		private LocalizableMessage|string|null $description = null,
		private array $validationRules = [],
		private ?array $requiredGroups = null,
		private bool $filterable = false,
		private bool $sortable = false,
		private bool $editable = false,
		private bool $multiple = false,
		private bool $nullable = false,
		private ?string $elementType = null,
		null|array|DtoFieldRelation $relation = null,
	) {
		if (!in_array($type, [self::DTO_FIELD_TYPE_PROPERTY, self::DTO_FIELD_TYPE_USER_FIELD, self::DTO_FIELD_TYPE_DYNAMIC_FIELD], true))
		{
			throw new SystemException('Unsupported type: ' . $propertyType);
		}

		if (is_array($relation))
		{
			$this->relation = DtoFieldRelation::fromArray($relation);
		}

		$this->title = $this->title !== null ? $title : $this->propertyName;
	}

	/**
	 * @return PropertyValidationAttributeInterface[]
	 */
	public function getValidationRules(): array
	{
		return $this->validationRules;
	}

	public function addValidationRule(PropertyValidationAttributeInterface $rule): self
	{
		$this->validationRules[] = $rule;

		return $this;
	}

	public function isMultiple(): bool
	{
		return $this->multiple;
	}

	public function setMultiple(bool $multiple): self
	{
		$this->multiple = $multiple;

		return $this;
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

	public function setTitle(LocalizableMessage|string $title): self
	{
		$this->title = $title;

		return $this;
	}

	public function getDescription(): LocalizableMessage|string|null
	{
		return $this->description;
	}

	public function setDescription(LocalizableMessage|string|null $description): self
	{
		$this->description = $description;

		return $this;
	}

	public function setRequiredGroups(array $groups): self
	{
		$this->requiredGroups = $groups;

		return $this;
	}

	public function isFilterable(): bool
	{
		return $this->filterable;
	}

	public function setFilterable(bool $filterable): self
	{
		$this->filterable = $filterable;

		return $this;
	}

	public function isSortable(): bool
	{
		return $this->sortable;
	}

	public function setSortable(bool $sortable): self
	{
		$this->sortable = $sortable;

		return $this;
	}

	public function isEditable(): bool
	{
		return $this->editable;
	}

	public function setEditable(bool $editable): self
	{
		$this->editable = $editable;

		return $this;
	}

	public function getValue(): mixed
	{
		return $this->value;
	}

	public function setValue(mixed $value): self
	{
		$this->value = $value;
		$this->initialized = true;

		return $this;
	}

	public function unsetValue(): self
	{
		$this->initialized = false;
		unset($this->value);

		return $this;
	}

	public function isInitialized(): bool
	{
		return $this->initialized;
	}

	public function getElementType(): ?string
	{
		return $this->elementType;
	}

	public function setElementType(?string $elementType): self
	{
		$this->elementType = $elementType;

		return $this;
	}

	public function getRelation(): ?DtoFieldRelation
	{
		return $this->relation;
	}

	public function setRelation(?DtoFieldRelation $relation): self
	{
		$this->relation = $relation;

		return $this;
	}

	public function getRequiredGroups(): ?array
	{
		return $this->requiredGroups;
	}

	public function isNullable(): bool
	{
		return $this->nullable;
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
			editable: $data['editable'] ?? false,
			multiple: $data['isMultiple'] ?? false,
			nullable: $data['nullable'] ?? false,
			elementType: $data['elementType'] ?? null,
			relation: $data['relation'] ?? null,
		);
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
			'editable' => $this->editable,
			'isMultiple' => $this->multiple,
			'nullable' => $this->nullable,
			'elementType' => $this->elementType,
			'relation' => $this->relation?->toArray(),
		];
	}
}
