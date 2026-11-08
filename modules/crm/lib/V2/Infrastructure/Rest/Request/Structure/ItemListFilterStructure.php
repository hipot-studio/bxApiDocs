<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Request\Structure;

use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item\Common\AddressDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item\Common\ItemDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item\Common\ItemIdentifierDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item\Common\MoneyDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item\ItemDtoGenerator;
use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Rest\V3\Attribute\Filterable;
use Bitrix\Rest\V3\Dto\Dto;
use Bitrix\Rest\V3\Dto\DtoCollection;
use Bitrix\Rest\V3\Dto\DtoField;
use Bitrix\Rest\V3\Exception\InvalidFilterException;
use Bitrix\Rest\V3\Exception\UnknownDtoPropertyException;
use Bitrix\Rest\V3\Exception\Validation\DtoFieldRequiredAttributeException;
use Bitrix\Rest\V3\Exception\Validation\InvalidRequestFieldTypeException;
use Bitrix\Rest\V3\Exception\Validation\RequiredFieldInRequestException;
use Bitrix\Rest\V3\Interaction\Request\Request;
use Bitrix\Rest\V3\Structure\FieldsValidator;
use Bitrix\Rest\V3\Structure\Filtering\Condition;
use Bitrix\Rest\V3\Structure\Filtering\FilterStructure;
use Bitrix\Rest\V3\Structure\Filtering\FilterValidator;
use Bitrix\Rest\V3\Structure\Filtering\Logic;
use Bitrix\Rest\V3\Structure\Filtering\Operator;
use Bitrix\Rest\V3\Structure\Structure;

final class ItemListFilterStructure extends Structure
{
	private const DOT_PATH_PARENTS = ['utm', 'lastCommunication', 'phone', 'email', 'web', 'im'];
	private const MULTIFIELD_DOT_PATH_PARENTS = ['phone', 'email', 'web', 'im'];
	private const COLLECTION_IDENTIFIER_FIELDS = ['contactsId', 'observersId'];
	private const COLLECTION_OPERATORS = [Operator::Equal, Operator::NotEqual, Operator::In];
	private const MAX_DEPTH = 8;
	private const MAX_NODES = 100;
	private const MAX_IN_ITEMS = 1000;

	/** @var array<Condition|self> */
	private array $conditions = [];

	private Logic $logic = Logic::And;

	private bool $negative = false;

	private bool $isCompactGroup = false;

	public static function create(mixed $value, string $dtoClass, Request $request): self
	{
		$value = (array)$value;
		if (
			array_is_list($value)
			&& count($value) === 1
			&& is_array($value[0])
			&& self::isGroup($value[0])
		)
		{
			$value = $value[0];
		}
		if (!array_key_exists('type', $value) && self::isGroup($value))
		{
			$value['type'] = 'filter';
		}

		$dto = self::getDto($dtoClass);
		if ($dto === null)
		{
			$dto = $dtoClass::create();
			self::addDto($dto);
		}

		$budget = [
			'nodes' => 0,
			'inItems' => 0,
		];

		return self::normalizeNode($value, $dtoClass, $request, $dto, true, 1, $budget);
	}

	/**
	 * @return array<Condition|self>
	 */
	public function getConditions(): array
	{
		return $this->conditions;
	}

	public function getLogic(): Logic
	{
		return $this->logic;
	}

	public function isNegative(): bool
	{
		return $this->negative;
	}

	public function isCompactGroup(): bool
	{
		return $this->isCompactGroup;
	}

	private static function normalizeNode(
		array $node,
		string $dtoClass,
		Request $request,
		Dto $dto,
		bool $isRoot,
		int $depth,
		array &$budget,
		bool $isCompactChild = false,
	): self {
		$structure = new self();
		$structure->isCompactGroup = $isCompactChild;
		$isGroup = self::isGroup($node);
		if (!$isGroup)
		{
			if (isset($node[0]) && is_array($node[0]) && !isset($node[0]['expression']))
			{
				$structure->isCompactGroup = true;
				self::validateDepth($depth);
				self::consumeNode($budget);
				foreach ($node as $condition)
				{
					if (!is_array($condition))
					{
						throw new InvalidFilterException($condition);
					}

					$structure->conditions[] = self::normalizeNode(
						$condition,
						$dtoClass,
						$request,
						$dto,
						false,
						$depth + 1,
						$budget,
						true,
					);
				}

				return $structure;
			}

			if ($node !== [])
			{
				$structure->conditions[] = self::normalizeCondition($node, $dtoClass, $request, $dto, $budget);
			}

			return $structure;
		}

		self::validateDepth($depth);
		self::consumeNode($budget);

		if (($node['type'] ?? 'filter') !== 'filter')
		{
			throw new InvalidFilterException($node);
		}
		if (!$isRoot && !array_key_exists('type', $node))
		{
			throw new InvalidFilterException($node);
		}

		if (array_key_exists('logic', $node))
		{
			if (!is_string($node['logic']))
			{
				throw new InvalidFilterException(['logic' => $node['logic']]);
			}

			try
			{
				$structure->logic = Logic::from($node['logic']);
			}
			catch (\ValueError)
			{
				throw new InvalidFilterException(['logic' => $node['logic']]);
			}
		}

		if (array_key_exists('negative', $node))
		{
			if (!is_bool($node['negative']))
			{
				throw new InvalidFilterException(['negative' => $node['negative']]);
			}

			$structure->negative = $node['negative'];
		}

		if (!array_key_exists('conditions', $node) || !is_array($node['conditions']))
		{
			throw new InvalidFilterException($node);
		}

		foreach ($node['conditions'] as $child)
		{
			if (!is_array($child))
			{
				throw new InvalidFilterException($child);
			}

			if (($child['type'] ?? null) === 'condition')
			{
				$structure->conditions[] = self::normalizeStaticCondition(
					$child,
					$dtoClass,
					$request,
					$dto,
					$budget,
				);
			}
			elseif (self::isGroup($child))
			{
				$structure->conditions[] = self::normalizeNode(
					$child,
					$dtoClass,
					$request,
					$dto,
					false,
					$depth + 1,
					$budget,
				);
			}
			else
			{
				$structure->conditions[] = self::normalizeCondition(
					$child,
					$dtoClass,
					$request,
					$dto,
					$budget,
				);
			}
		}

		return $structure;
	}

	private static function isGroup(array $node): bool
	{
		return array_key_exists('type', $node)
			|| array_key_exists('logic', $node)
			|| array_key_exists('negative', $node)
			|| array_key_exists('conditions', $node);
	}

	private static function normalizeStaticCondition(
		array $condition,
		string $dtoClass,
		Request $request,
		Dto $dto,
		array &$budget,
	): Condition {
		$requiredKeys = ['type', 'leftOperand', 'operator', 'rightOperand'];
		if (array_diff($requiredKeys, array_keys($condition)) !== [])
		{
			throw new InvalidFilterException($condition);
		}

		return self::normalizeCondition(
			[$condition['leftOperand'], $condition['operator'], $condition['rightOperand']],
			$dtoClass,
			$request,
			$dto,
			$budget,
		);
	}

	private static function normalizeCondition(
		array $condition,
		string $dtoClass,
		Request $request,
		Dto $dto,
		array &$budget,
	): Condition {
		self::consumeNode($budget);
		if (!array_is_list($condition))
		{
			throw new InvalidFilterException($condition);
		}

		[$fieldName, $operator, $value] = match (count($condition))
		{
			2 => [$condition[0], is_array($condition[1]) ? Operator::In : Operator::Equal, $condition[1]],
			3 => [$condition[0], $condition[1], $condition[2]],
			default => throw new InvalidFilterException($condition),
		};
		$operator = $operator instanceof Operator
			? $operator
			: FilterValidator::validateOperator(is_string($operator) ? $operator : null);

		if (!is_string($fieldName))
		{
			throw new InvalidFilterException($condition);
		}
		if ($operator === Operator::In)
		{
			$value = self::normalizeInOperand($fieldName, $value, $budget);
		}

		if (str_contains($fieldName, '.'))
		{
			return self::normalizeDotPathCondition($fieldName, $operator, $value, $request, $dto);
		}

		$field = self::getFilterableField($dto, $fieldName);
		if (in_array($fieldName, self::COLLECTION_IDENTIFIER_FIELDS, true))
		{
			return self::normalizeCollectionIdentifierCondition($fieldName, $operator, $value);
		}
		if (self::isRelatedCrmObjectIdField($dto, $fieldName))
		{
			return self::normalizeRelatedCrmObjectIdCondition($fieldName, $operator, $value);
		}

		if (self::isComplexCustomField($field))
		{
			return self::normalizeComplexCustomFieldCondition($field, $operator, $value);
		}

		$value = self::normalizeFloatOperand($field, $value);
		if (
			$operator === Operator::Between
			&& in_array($field->getPropertyType(), ['int', 'float'], true)
		)
		{
			return self::createNumericBetweenCondition($field, $value);
		}

		$standard = FilterStructure::create([$fieldName, $operator->value, $value], $dtoClass, $request);
		$normalized = $standard->getConditions()[0] ?? null;
		if (!$normalized instanceof Condition)
		{
			throw new InvalidFilterException($condition);
		}

		return $normalized;
	}

	private static function consumeNode(array &$budget): void
	{
		$budget['nodes'] = ($budget['nodes'] ?? 0) + 1;
		if ($budget['nodes'] > self::MAX_NODES)
		{
			throw new InvalidFilterException('Filter node limit exceeded.');
		}
	}

	private static function validateDepth(int $depth): void
	{
		if ($depth > self::MAX_DEPTH)
		{
			throw new InvalidFilterException('Filter depth limit exceeded.');
		}
	}

	private static function normalizeInOperand(string $fieldName, mixed $value, array &$budget): array
	{
		if (!is_array($value))
		{
			throw new InvalidFilterException([$fieldName, Operator::In->value, $value]);
		}

		$uniqueValues = [];
		$seen = [];
		foreach ($value as $item)
		{
			$key = get_debug_type($item) . ':' . serialize($item);
			if (isset($seen[$key]))
			{
				continue;
			}

			$seen[$key] = true;
			$uniqueValues[] = $item;
		}

		$budget['inItems'] = ($budget['inItems'] ?? 0) + count($uniqueValues);
		if ($budget['inItems'] > self::MAX_IN_ITEMS)
		{
			throw new InvalidFilterException('Filter collection operand limit exceeded.');
		}

		return $uniqueValues;
	}

	private static function normalizeFloatOperand(DtoField $field, mixed $value): mixed
	{
		if ($field->getPropertyType() !== 'float')
		{
			return $value;
		}

		if (is_array($value))
		{
			foreach ($value as $index => $item)
			{
				if (is_int($item))
				{
					$value[$index] = (float)$item;
				}
			}

			return $value;
		}

		return is_int($value) ? (float)$value : $value;
	}

	private static function createNumericBetweenCondition(DtoField $field, mixed $value): Condition
	{
		$fieldName = $field->getPropertyName();
		if (!is_array($value) || count($value) !== 2)
		{
			throw new InvalidFilterException([$fieldName, Operator::Between->value, $value]);
		}

		foreach ($value as $boundary)
		{
			if (!FieldsValidator::validateTypeAndValue($field->getPropertyType(), $boundary))
			{
				throw new InvalidRequestFieldTypeException($fieldName, $field->getPropertyType());
			}
		}

		return new Condition($fieldName, Operator::Between, $value);
	}

	private static function normalizeDotPathCondition(
		string $path,
		Operator $operator,
		mixed $value,
		Request $request,
		Dto $rootDto,
	): Condition {
		$parts = explode('.', $path);
		if (count($parts) > 1 && self::isCrmRelationPath($parts, $rootDto))
		{
			return self::normalizeCrmRelationCondition($parts, $operator, $value, $request, $rootDto);
		}

		if (count($parts) !== 2)
		{
			throw new InvalidFilterException($path);
		}

		[$parentName, $childName] = $parts;
		if (!in_array($parentName, self::DOT_PATH_PARENTS, true))
		{
			throw new InvalidFilterException($path);
		}
		if (
			in_array($parentName, self::MULTIFIELD_DOT_PATH_PARENTS, true)
			&& !in_array($operator, [Operator::Equal, Operator::In], true)
		)
		{
			throw new InvalidFilterException([$path, $operator->value, $value]);
		}
		if (!isset($rootDto->getFields()[$parentName]))
		{
			throw new UnknownDtoPropertyException($rootDto->getShortName(), $parentName);
		}

		$parentField = $rootDto->getFields()[$parentName];
		$childDtoClass = $parentField->getElementType() ?? $parentField->getPropertyType();
		if (!is_subclass_of($childDtoClass, Dto::class))
		{
			throw new InvalidFilterException($path);
		}

		$childDto = self::getDto($childDtoClass);
		if ($childDto === null)
		{
			$childDto = $childDtoClass::create();
			self::addDto($childDto);
		}
		$childField = self::getFilterableField($childDto, $childName);

		if ($value === null)
		{
			$isRelationIdentifier = $childField->getPropertyName() === 'id';
			if ((!$childField->isNullable() && !$isRelationIdentifier) || !in_array($operator, [Operator::Equal, Operator::NotEqual], true))
			{
				throw new InvalidFilterException([$path, $operator->value, $value]);
			}

			return new Condition($path, $operator, null);
		}

		$value = self::normalizeFloatOperand($childField, $value);
		$standard = FilterStructure::create(
			[$childName, $operator->value, $value],
			$childDtoClass,
			$request,
		);
		$normalized = $standard->getConditions()[0] ?? null;
		if (!$normalized instanceof Condition)
		{
			throw new InvalidFilterException([$path, $operator->value, $value]);
		}

		return new Condition($path, $normalized->getOperator(), $normalized->getRightOperand());
	}

	/**
	 * @param string[] $parts
	 */
	private static function isCrmRelationPath(array $parts, Dto $rootDto): bool
	{
		$relation = $rootDto->getFields()[$parts[0]] ?? null;
		if (!$relation instanceof DtoField)
		{
			return false;
		}

		$targetDtoClass = $relation->getElementType() ?? $relation->getPropertyType();

		return self::isCrmRelationDto($targetDtoClass);
	}

	private static function isCrmRelationDto(mixed $dtoClass): bool
	{
		if (!is_string($dtoClass))
		{
			return false;
		}

		return (new ItemDtoGenerator())->isGeneratedDtoClass($dtoClass);
	}

	/**
	 * @param string[] $parts
	 */
	private static function normalizeCrmRelationCondition(
		array $parts,
		Operator $operator,
		mixed $value,
		Request $request,
		Dto $rootDto,
	): Condition
	{
		if (count($parts) < 2 || count($parts) > 3)
		{
			throw new InvalidFilterException(implode('.', $parts));
		}

		$parentName = $parts[0];
		$parentField = $rootDto->getFields()[$parentName] ?? null;
		if (!$parentField instanceof DtoField)
		{
			throw new UnknownDtoPropertyException($rootDto->getShortName(), $parentName);
		}

		$childDtoClass = $parentField->getElementType() ?? $parentField->getPropertyType();
		if (!self::isCrmRelationDto($childDtoClass))
		{
			throw new InvalidFilterException(implode('.', $parts));
		}

		$childDto = self::getDto($childDtoClass);
		if ($childDto === null)
		{
			$childDto = $childDtoClass::create();
			self::addDto($childDto);
		}

		$childName = $parts[1];
		$childField = $childDto->getFields()[$childName] ?? null;
		if (!$childField instanceof DtoField)
		{
			throw new UnknownDtoPropertyException($childDto->getShortName(), $childName);
		}

		self::validateCrmRelationScope($request, $parentName, $childName, $rootDto, $childDto);

		if (count($parts) === 2)
		{
			if ($childField->getPropertyType() === DtoCollection::class || is_subclass_of($childField->getPropertyType(), Dto::class))
			{
				throw new InvalidFilterException(implode('.', $parts));
			}

			if (!$childField->isFilterable() && $childName !== 'id')
			{
				throw new DtoFieldRequiredAttributeException($childDto->getShortName(), $childName, Filterable::class);
			}

			return self::normalizeRelationLeafCondition(
				implode('.', $parts),
				$childName,
				$operator,
				$value,
				$request,
				$childDtoClass,
				$childField,
			);
		}

		$nestedName = $parts[2];
		if (!in_array($childName, [...self::MULTIFIELD_DOT_PATH_PARENTS, 'utm', 'lastCommunication'], true))
		{
			throw new InvalidFilterException(implode('.', $parts));
		}

		$nestedDtoClass = $childField->getElementType() ?? $childField->getPropertyType();
		if (!is_subclass_of($nestedDtoClass, Dto::class))
		{
			throw new InvalidFilterException(implode('.', $parts));
		}

		$nestedDto = self::getDto($nestedDtoClass);
		if ($nestedDto === null)
		{
			$nestedDto = $nestedDtoClass::create();
			self::addDto($nestedDto);
		}
		$nestedField = self::getFilterableField($nestedDto, $nestedName);
		if (
			in_array($childName, self::MULTIFIELD_DOT_PATH_PARENTS, true)
			&& (
				!in_array($operator, [Operator::Equal, Operator::In], true)
				|| ($operator === Operator::In && $value === [])
			)
		)
		{
			throw new InvalidFilterException([implode('.', $parts), $operator->value, $value]);
		}

		return self::normalizeRelationLeafCondition(
			implode('.', $parts),
			$nestedName,
			$operator,
			$value,
			$request,
			$nestedDtoClass,
			$nestedField,
		);
	}

	private static function normalizeRelationLeafCondition(
		string $path,
		string $fieldName,
		Operator $operator,
		mixed $value,
		Request $request,
		string $dtoClass,
		DtoField $field,
	): Condition
	{
		if ($value === null)
		{
			$isRelationIdentifier = $field->getPropertyName() === 'id';
			if ((!$field->isNullable() && !$isRelationIdentifier) || !in_array($operator, [Operator::Equal, Operator::NotEqual], true))
			{
				throw new InvalidFilterException([$path, $operator->value, $value]);
			}

			return new Condition($path, $operator, null);
		}

		$value = self::normalizeFloatOperand($field, $value);
		if ($field->getPropertyName() === 'id' && !$field->isFilterable())
		{
			$values = $operator === Operator::In ? $value : [$value];
			foreach ((array)$values as $item)
			{
				if (!is_int($item))
				{
					throw new InvalidRequestFieldTypeException($path, 'int');
				}
			}

			return new Condition($path, $operator, $value);
		}

		$standard = FilterStructure::create(
			[$fieldName, $operator->value, $value],
			$dtoClass,
			$request,
		);
		$normalized = $standard->getConditions()[0] ?? null;
		if (!$normalized instanceof Condition)
		{
			throw new InvalidFilterException([$path, $operator->value, $value]);
		}

		return new Condition($path, $normalized->getOperator(), $normalized->getRightOperand());
	}

	private static function validateCrmRelationScope(
		Request $request,
		string $parentName,
		string $childName,
		Dto $rootDto,
		Dto $childDto,
	): void
	{
		$availableFields = $request->getOptions()['scope']?->fields ?? [];
		if ($availableFields === [])
		{
			return;
		}

		if (!in_array($parentName, $availableFields, true))
		{
			throw new UnknownDtoPropertyException($rootDto->getShortName(), $parentName);
		}

		$baseItemDto = self::getDto(ItemDto::class) ?? ItemDto::create();
		if (!isset($baseItemDto->getFields()[$childName]))
		{
			throw new UnknownDtoPropertyException($childDto->getShortName(), $childName);
		}
	}

	private static function getFilterableField(Dto $dto, string $fieldName): DtoField
	{
		if (!isset($dto->getFields()[$fieldName]))
		{
			throw new UnknownDtoPropertyException($dto->getShortName(), $fieldName);
		}

		$field = $dto->getFields()[$fieldName];
		if (!$field->isFilterable() && !self::isRelatedCrmObjectIdField($dto, $fieldName))
		{
			throw new DtoFieldRequiredAttributeException($dto->getShortName(), $fieldName, Filterable::class);
		}

		return $field;
	}

	private static function isRelatedCrmObjectIdField(Dto $dto, string $fieldName): bool
	{
		foreach ($dto->getFields() as $field)
		{
			$relation = $field->getRelation();
			if (
				$relation !== null
				&& $relation->thisField === $fieldName
				&& self::isCrmRelationDto($field->getElementType() ?? $field->getPropertyType())
			)
			{
				return true;
			}
		}

		return false;
	}

	private static function normalizeRelatedCrmObjectIdCondition(
		string $fieldName,
		Operator $operator,
		mixed $value,
	): Condition
	{
		if ($value === null)
		{
			if (!in_array($operator, [Operator::Equal, Operator::NotEqual], true))
			{
				throw new InvalidFilterException([$fieldName, $operator->value, $value]);
			}

			return new Condition($fieldName, $operator, null);
		}

		if ($operator === Operator::Between && (!is_array($value) || count($value) !== 2))
		{
			throw new InvalidFilterException([$fieldName, $operator->value, $value]);
		}

		$values = in_array($operator, [Operator::In, Operator::Between], true) ? $value : [$value];
		foreach ((array)$values as $item)
		{
			if (!is_int($item))
			{
				throw new InvalidRequestFieldTypeException($fieldName, 'int');
			}
		}

		return new Condition($fieldName, $operator, $value);
	}

	private static function normalizeCollectionIdentifierCondition(
		string $fieldName,
		Operator $operator,
		mixed $value,
	): Condition {
		self::requireCollectionOperator($fieldName, $operator, $value);
		$values = $operator === Operator::In ? $value : [$value];
		if ($operator === Operator::In && !is_array($value))
		{
			throw new InvalidFilterException([$fieldName, $operator->value, $value]);
		}

		foreach ($values as $item)
		{
			if ($item === null)
			{
				throw new InvalidFilterException([$fieldName, $operator->value, $value]);
			}
			if (!is_int($item))
			{
				throw new InvalidRequestFieldTypeException($fieldName, 'int');
			}
		}

		return new Condition($fieldName, $operator, $value);
	}

	private static function normalizeComplexCustomFieldCondition(
		DtoField $field,
		Operator $operator,
		mixed $value,
	): Condition {
		$fieldName = $field->getPropertyName();
		self::requireCollectionOperator($fieldName, $operator, $value);
		if ($operator === Operator::In && !is_array($value))
		{
			throw new InvalidFilterException([$fieldName, $operator->value, $value]);
		}

		if ($operator === Operator::In)
		{
			$converted = [];
			foreach ($value as $index => $item)
			{
				if ($item === null)
				{
					throw new InvalidFilterException([$fieldName, $operator->value, $value]);
				}
				$converted[] = self::convertCustomFieldItem(
					$field,
					$item,
					$fieldName . '.' . $index,
				);
			}
		}
		else
		{
			$converted = self::convertCustomFieldItem($field, $value, $fieldName);
		}

		return new Condition($fieldName, $operator, $converted);
	}

	private static function requireCollectionOperator(string $fieldName, Operator $operator, mixed $value): void
	{
		if (!in_array($operator, self::COLLECTION_OPERATORS, true) || $value === null)
		{
			throw new InvalidFilterException([$fieldName, $operator->value, $value]);
		}
	}

	private static function isComplexCustomField(DtoField $field): bool
	{
		if (!str_starts_with($field->getPropertyName(), 'UF_'))
		{
			return false;
		}

		$propertyType = $field->getElementType() ?? $field->getPropertyType();

		return $field->isMultiple()
			|| is_subclass_of($propertyType, Dto::class)
			|| ($field->getPropertyType() === DtoCollection::class && $field->getElementType() !== null);
	}

	private static function convertCustomFieldItem(
		DtoField $field,
		mixed $value,
		string $fieldPath,
	): mixed
	{
		$effectiveType = $field->getElementType() ?? $field->getPropertyType();
		$effectiveField = new DtoField(
			propertyName: $fieldPath,
			propertyType: $effectiveType,
			type: $field->getType(),
			filterable: true,
		);
		$converted = CustomFieldConverter::convertValueByDtoField($effectiveField, $value);
		$isValid = is_subclass_of($effectiveType, Dto::class)
			? $converted instanceof $effectiveType
			: FieldsValidator::validateTypeAndValue($effectiveType, $converted);
		if (!$isValid)
		{
			throw new InvalidRequestFieldTypeException($fieldPath, $effectiveType);
		}

		if ($converted instanceof Dto)
		{
			self::validateComplexCustomFieldDto($converted, $fieldPath);
		}

		return $converted;
	}

	private static function validateComplexCustomFieldDto(Dto $dto, string $fieldPath): void
	{
		if ($dto instanceof ItemIdentifierDto)
		{
			self::validateItemIdentifierDto($dto, $fieldPath);

			return;
		}
		if ($dto instanceof MoneyDto)
		{
			self::validateMoneyDto($dto, $fieldPath);

			return;
		}
		if ($dto instanceof AddressDto)
		{
			self::validateAddressDto($dto, $fieldPath);
		}
	}

	private static function validateItemIdentifierDto(ItemIdentifierDto $dto, string $fieldPath): void
	{
		$entityTypeIdPath = $fieldPath . '.entityTypeId';
		$entityTypeId = self::requireInitializedProperty($dto, 'entityTypeId', $entityTypeIdPath);
		if ($entityTypeId <= 0 || !EntityType::isValid($entityTypeId))
		{
			throw new InvalidRequestFieldTypeException($entityTypeIdPath, 'supported positive entity type ID');
		}

		$entityIdPath = $fieldPath . '.entityId';
		$entityId = self::requireInitializedProperty($dto, 'entityId', $entityIdPath);
		if ($entityId <= 0)
		{
			throw new InvalidRequestFieldTypeException($entityIdPath, 'positive int');
		}
	}

	private static function validateMoneyDto(MoneyDto $dto, string $fieldPath): void
	{
		$sumPath = $fieldPath . '.sum';
		$sum = self::requireInitializedProperty($dto, 'sum', $sumPath);
		if ($sum < 0)
		{
			throw new InvalidRequestFieldTypeException($sumPath, 'non-negative float');
		}

		$currencyIdPath = $fieldPath . '.currencyId';
		$currencyId = self::requireInitializedProperty($dto, 'currencyId', $currencyIdPath);
		if (trim($currencyId) === '')
		{
			throw new InvalidRequestFieldTypeException($currencyIdPath, 'non-empty string');
		}
	}

	private static function validateAddressDto(AddressDto $dto, string $fieldPath): void
	{
		$addressPath = $fieldPath . '.address';
		$address = self::requireInitializedProperty($dto, 'address', $addressPath);
		if (trim($address) === '')
		{
			throw new InvalidRequestFieldTypeException($addressPath, 'non-empty string');
		}

		self::validateOptionalNumberRange($dto, 'latitude', $fieldPath . '.latitude', -90, 90);
		self::validateOptionalNumberRange($dto, 'longitude', $fieldPath . '.longitude', -180, 180);
	}

	private static function validateOptionalNumberRange(
		Dto $dto,
		string $propertyName,
		string $fieldPath,
		float $min,
		float $max,
	): void
	{
		$property = new \ReflectionProperty($dto, $propertyName);
		if (!$property->isInitialized($dto) || $dto->{$propertyName} === null)
		{
			return;
		}

		$value = $dto->{$propertyName};
		if ($value < $min || $value > $max)
		{
			throw new InvalidRequestFieldTypeException($fieldPath, "float between {$min} and {$max}");
		}
	}

	private static function requireInitializedProperty(
		Dto $dto,
		string $propertyName,
		string $fieldPath,
	): mixed
	{
		$property = new \ReflectionProperty($dto, $propertyName);
		if (!$property->isInitialized($dto))
		{
			throw new RequiredFieldInRequestException($fieldPath);
		}

		return $dto->{$propertyName};
	}
}
