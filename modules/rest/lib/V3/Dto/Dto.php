<?php

namespace Bitrix\Rest\V3\Dto;

use Bitrix\Main\Type\Contract\Arrayable;
use Bitrix\Main\Type\Date;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\Validation\Rule\PropertyValidationAttributeInterface;
use Bitrix\Rest\V3\Attribute\AbstractAttribute;
use Bitrix\Rest\V3\Attribute\Description;
use Bitrix\Rest\V3\Attribute\Editable;
use Bitrix\Rest\V3\Attribute\ElementType;
use Bitrix\Rest\V3\Attribute\Filterable;
use Bitrix\Rest\V3\Attribute\OrmEntity;
use Bitrix\Rest\V3\Attribute\RelationToMany;
use Bitrix\Rest\V3\Attribute\RelationToOne;
use Bitrix\Rest\V3\Attribute\Required;
use Bitrix\Rest\V3\Attribute\Sortable;
use Bitrix\Rest\V3\Attribute\Title;
use Bitrix\Rest\V3\CacheManager;
use Bitrix\Rest\V3\Dto\Attribute\TypeAlias;

abstract class Dto implements Arrayable
{
	public const CONFIGS_CACHE_KEY = 'rest.v3.dto.fields.cache.key';
	protected DtoFieldsCollection $fields;
	private readonly string $shortName;

	/** @var AbstractAttribute[] */
	private array $attributes;

	public function __construct()
	{
		$selfReflection = (new \ReflectionClass($this));
		$attributes = [];
		foreach ($selfReflection->getAttributes() as $attribute)
		{
			$attributes[$attribute->getName()] = $attribute->newInstance();
		}
		$this->attributes = $attributes;
		$this->shortName = $selfReflection->getShortName();
		$this->fields = $this->initFields();
	}

	public static function create(): self
	{
		return new static();
	}

	protected function getCacheKey(): string
	{
		return self::CONFIGS_CACHE_KEY . get_class($this);
	}

	protected function initFields(): DtoFieldsCollection
	{
		$fields = new DtoFieldsCollection();

		$cacheKey = $this->getCacheKey();
		$fieldsArray = CacheManager::get($cacheKey);
		if ($fieldsArray === null)
		{
			$dtoFields = array_merge(
				...array_filter([
					$this->getPropertiesFields(),
					$this->getUserFields(),
					$this->getExtraFields(),
				], fn($array) => !empty($array)),
			);

			foreach ($dtoFields as $field)
			{
				$fields->add($field);
				$fieldsCacheData[] = $field->toArray();
			}

			CacheManager::set($cacheKey, $fieldsCacheData);
		}
		else
		{
			foreach ($fieldsArray as $fieldArray)
			{
				$dtoField = DtoField::fromArray($fieldArray);
				$fields->add($dtoField);
			}
		}

		return $fields;
	}

	public function __set(string $name, $value): void
	{
		if ($this->fields[$name])
		{
			$this->fields[$name]->setValue($value);
		}
	}

	public function __get(string $name)
	{
		if ($this->fields[$name])
		{
			return $this->fields[$name]->getValue();
		}

		return null;
	}

	public function toArray(bool $rawData = false): array
	{
		$values = [];

		/** @var DtoField $field */
		foreach ($this->fields as $field)
		{
			if ($field->getType() === DtoField::DTO_FIELD_TYPE_PROPERTY)
			{
				$property = PropertyHelper::getProperty($this, $field->getPropertyName());
				if ($property === null)
				{
					continue;
				}

				if ($property->isInitialized($this))
				{
					$field->setValue($this->{$field->getPropertyName()});
				}
			}

			if ($field->isInitialized())
			{
				if ($field->isMultiple())
				{
					$fieldValue = [];
					$fieldValues = $field->getValue();
					// Preserve null for multiple fields
					if ($fieldValues === null)
					{
						$values[$field->getPropertyName()] = null;
					}
					else
					{
						if (!is_iterable($fieldValues))
						{
							$fieldValues = [$fieldValues];
						}

						foreach ($fieldValues as $value)
						{
							if (is_subclass_of($value, Dto::class))
							{
								$fieldValue[] = $value->toArray($rawData);
							}
							else
							{
								$fieldValue[] = $value;
							}
						}
					}

					$values[$field->getPropertyName()] = $fieldValue;
				}
				else
				{
					if (is_subclass_of($field->getPropertyType(), Dto::class))
					{
						$values[$field->getPropertyName()] = $field->getValue()?->toArray($rawData);
					}
					else
					{
						$values[$field->getPropertyName()] = $field->getValue();
					}
				}
			}
		}

		if ($rawData)
		{
			return $values;
		}

		foreach ($values as $propertyName => $value)
		{
			if ($value instanceof DateTime)
			{
				$values[$propertyName] = $value->format(DATE_ATOM);
			}
			elseif ($value instanceof Date)
			{
				$values[$propertyName] = $value->format('Y-m-d');
			}
			else
			{
				$values[$propertyName] = $value;
			}
		}

		return $values;
	}

	public function getFields(): DtoFieldsCollection
	{
		return $this->fields;
	}

	public function getShortName(): string
	{
		return $this->shortName;
	}

	/**
	 * @return AbstractAttribute[]
	 */
	public function getAttributes(): array
	{
		return $this->attributes;
	}

	public function getAttributeByName(string $name): ?AbstractAttribute
	{
		return $this->attributes[$name] ?? null;
	}

	/**
	 * @return DtoField[]
	 */
	private function getPropertiesFields(): array
	{
		$fields = [];
		foreach (PropertyHelper::getProperties($this) as $property)
		{
			$fieldPropertyType = $property->getType();
			if ($fieldPropertyType instanceof \ReflectionUnionType)
			{
				$propertyType = 'mixed';
			}
			else
			{
				$propertyType = $fieldPropertyType !== null ? $fieldPropertyType->getName() : 'mixed';
			}

			$field = new DtoField(
				propertyName: $property->getName(),
				propertyType: $propertyType === 'self' ? get_class($this) : $propertyType,
				type: DtoField::DTO_FIELD_TYPE_PROPERTY,
				nullable: $property->getType() && $property->getType()->allowsNull(),
			);

			foreach ($property->getAttributes() as $attribute)
			{
				$attributeInstance = $attribute->newInstance();
				if ($attributeInstance instanceof PropertyValidationAttributeInterface)
				{
					$field->addValidationRule($attributeInstance);
				}

				match ($attribute->getName())
				{
					Title::class => $field->setTitle($attributeInstance->value),
					Description::class => $field->setDescription($attributeInstance->value),
					Sortable::class => $field->setSortable(true),
					Filterable::class => $field->setFilterable(true),
					Editable::class => $field->setEditableGroups($attributeInstance->groups),
					ElementType::class => $field->setElementType($attributeInstance->type),
					RelationToOne::class => call_user_func(function () use ($field, $attributeInstance, $property) {
						$field->setRelation(new DtoFieldRelation(thisField: $attributeInstance->thisField, refField: $attributeInstance->refField));
						$field->setElementType($property->getType()->getName());
					}),
					RelationToMany::class => call_user_func(function () use ($field, $attributeInstance) {
						$field->setRelation(new DtoFieldRelation(thisField: $attributeInstance->thisField, refField: $attributeInstance->refField, sort: $attributeInstance->sort, multiple: true));
						$field->setMultiple(true);
					}),
					Required::class => $field->setRequiredGroups($attributeInstance->groups),
					default => null,
				};
			}

			$fields[] = $field;
		}

		return $fields;
	}

	public function issetUserFields(): bool
	{
		/** @var OrmEntity $ormEntityAttribute */
		$ormEntityAttribute = $this->getAttributeByName(OrmEntity::class);
		return $ormEntityAttribute !== null && $ormEntityAttribute->getUserFieldId() !== null;
	}

	/**
	 * @return DtoField[]
	 */
	private function getUserFields(): array
	{
		$fields = [];
		if (!$this->issetUserFields())
		{
			return $fields;
		}
		/** @var OrmEntity $ormEntityAttribute */
		$ormEntityAttribute = $this->getAttributeByName(OrmEntity::class);

		$ufFields = \Bitrix\Main\UserFieldTable::getList([
			'filter' => [
				'ENTITY_ID' => $ormEntityAttribute->entity::getUfId(),
			],
			'select' => ['FIELD_NAME', 'USER_TYPE_ID', 'IS_SEARCHABLE', 'MULTIPLE'],
			'order' => ['SORT' => 'ASC'],
		]);

		while ($ufField = $ufFields->fetch())
		{
			$fields[] = new DtoField(
				propertyName: $ufField['FIELD_NAME'],
				propertyType: UserFieldTypeFactory::getFromBitrixType($ufField['USER_TYPE_ID']),
				type: DtoField::DTO_FIELD_TYPE_USER_FIELD,
				filterable: $ufField['IS_SEARCHABLE'] === 'Y',
				sortable: $ufField['IS_SEARCHABLE'] === 'Y',
				multiple: $ufField['MULTIPLE'] === 'Y',
				editableGroups: [],
			);
		}

		return $fields;
	}

	/**
	 * @return DtoField[]
	 */
	protected function getExtraFields(): array
	{
		return [];
	}

	public function getTypeName(): string
	{
		$reflection = new \ReflectionClass($this);
		$namespace = $reflection->getNamespaceName();
		$namespaceParts = array_filter(explode('\\', $namespace), static fn(string $part): bool => $part !== '');

		if (empty($namespaceParts))
		{
			return strtolower($this->getShortName());
		}

		$selectedParts = array_slice($namespaceParts, 0, 2);

		if (isset($this->attributes[TypeAlias::class]))
		{
			$selectedParts[] = $this->attributes[TypeAlias::class]->alias;
		}
		else
		{
			$selectedParts[] = $this->getShortName();
		}


		$selectedParts = array_map('strtolower', $selectedParts);

		return implode('.', $selectedParts);
	}
}
