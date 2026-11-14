<?php

namespace Bitrix\Rest\V3\Dto;

use Bitrix\Main\Type\Contract\Arrayable;
use Bitrix\Main\Type\Date;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\Validation\Rule\PropertyValidationAttributeInterface;
use Bitrix\Rest\V3\Attribute\AbstractAttribute;
use Bitrix\Rest\V3\Attribute\Description;
use Bitrix\Rest\V3\Attribute\DynamicEnum as DynamicEnumAttribute;
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
use Bitrix\Rest\V3\Dto\DynamicEnum\DynamicEnumRegistry;
use Bitrix\Rest\V3\Dto\Meta\ClassMeta;
use Bitrix\Rest\V3\Dto\Meta\FieldsMeta;
use Bitrix\Rest\V3\Dto\Meta\FieldsMetaRegistry;
use Bitrix\Main\SystemException;

abstract class Dto implements Arrayable
{
	public const CONFIGS_CACHE_KEY = 'rest.v3.dto.fields.cache.key';
	protected DtoFieldsCollection $fields;
	private readonly string $shortName;

	/** @var AbstractAttribute[] */
	private array $attributes;

	public function __construct()
	{
		$classMeta = ClassMeta::of(static::class);
		$this->attributes = $classMeta->attributes;
		$this->shortName = $classMeta->shortName;
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
				$fieldsCacheData[] = $field->toArray();
			}

			CacheManager::set($cacheKey, $fieldsCacheData);

			// the description of a miss or of a disabled cache belongs to this instance only
			return DtoFieldsCollection::fromMeta(FieldsMeta::fromCacheArray($fieldsCacheData ?? []));
		}

		return DtoFieldsCollection::fromMeta(FieldsMetaRegistry::get($cacheKey, $fieldsArray));
	}

	public function __set(string $name, $value): void
	{
		$this->fields->setFieldValue($name, $value);
	}

	public function __get(string $name)
	{
		return $this->fields->getFieldValue($name);
	}

	public function toArray(bool $rawData = false): array
	{
		$values = [];
		$classMeta = ClassMeta::of(static::class);
		$fields = $this->fields;

		foreach ($fields->getMetaList() as $position => $field)
		{
			if ($field->getType() === DtoField::DTO_FIELD_TYPE_PROPERTY)
			{
				$property = $classMeta->getProperty($field->getPropertyName());
				if ($property === null)
				{
					continue;
				}

				if ($property->isInitialized($this))
				{
					$fields->setValueAt($position, $this->{$field->getPropertyName()});
				}
			}

			if ($fields->isValueSetAt($position))
			{
				if ($field->isMultiple())
				{
					$fieldValue = [];
					$fieldValues = $fields->getValueAt($position);
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
							if ($value instanceof Dto)
							{
								$fieldValue[] = $value->toArray($rawData);
							}
							elseif ($value instanceof \BackedEnum)
							{
								$fieldValue[] = $value->value;
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
					if (TypeHelper::isDto($field->getPropertyType()))
					{
						$values[$field->getPropertyName()] = $fields->getValueAt($position)?->toArray($rawData);
					}
					elseif (TypeHelper::isBackedEnum($field->getPropertyType()))
					{
						$values[$field->getPropertyName()] = $fields->getValueAt($position)?->value;
					}
					else
					{
						$values[$field->getPropertyName()] = $fields->getValueAt($position);
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

				if ($attributeInstance instanceof DynamicEnumAttribute)
				{
					if (TypeHelper::isBackedEnum($propertyType))
					{
						throw new SystemException(
							'Property ' . $property->getName() . ' cannot combine BackedEnum type with #[DynamicEnum]'
						);
					}

					$definition = DynamicEnumRegistry::resolve($attributeInstance->provider);
					if (!$definition->type->matchesPhpType($propertyType))
					{
						throw new SystemException(
							'Property ' . $property->getName() . ' PHP type "' . $propertyType
							. '" is incompatible with DynamicEnum type "' . $definition->type->value . '"'
						);
					}

					$field->setDynamicEnumProvider($attributeInstance->provider);
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
		$classMeta = ClassMeta::of(static::class);
		// the short name is read on every call: a subclass may override getShortName()
		if ($classMeta->typeNamePrefix === null)
		{
			return strtolower($this->getShortName());
		}

		return $classMeta->typeNamePrefix . '.' . strtolower($classMeta->typeAlias ?? $this->getShortName());
	}
}
