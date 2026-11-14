<?php

declare(strict_types=1);

namespace Bitrix\Rest\V3\Interaction\Request;

use Bitrix\Rest\V3\Dto\Dto;
use Bitrix\Rest\V3\Dto\DtoCollection;
use Bitrix\Rest\V3\Dto\Meta\FieldMetaInterface;
use Bitrix\Rest\V3\Dto\TypeHelper;
use Bitrix\Rest\V3\Structure\LosslessScalarCaster;

/**
 * Casts values substituted by batch references to the type of the receiving DTO field.
 * Only positions inside `fields` and right operands of `filter` are cast; any unrecognized shape is left as is,
 * so the strict type check of the request structures still applies to it.
 */
final class BatchReferenceCaster
{
	private const FILTER_NODE_KEYS = ['type', 'logic', 'conditions', 'negative'];

	private ?Dto $dto = null;

	private bool $dtoResolved = false;

	/** @var array<string, Dto> */
	private array $dtos = [];

	public function __construct(private readonly string $dtoClass)
	{
	}

	/**
	 * @param list<list<int|string>> $referencePaths
	 */
	public function cast(array $query, array $referencePaths): array
	{
		foreach ($referencePaths as $path)
		{
			$root = $path[0] ?? null;
			if (($root !== 'fields' && $root !== 'filter') || !$this->hasValue($query, $path))
			{
				continue;
			}

			$dto = $this->getDto();
			if ($dto === null)
			{
				return $query;
			}

			$segments = array_slice($path, 1);
			$value = $this->getValue($query, $path);
			$castValue = $root === 'fields'
				? $this->castFieldsValue($dto, $segments, $value)
				: $this->castFilterValue($dto, $query['filter'], $segments, $value)
			;

			$this->setValue($query, $path, $castValue);
		}

		return $query;
	}

	private function castFieldsValue(Dto $dto, array $segments, mixed $value): mixed
	{
		if ($segments === [])
		{
			return $this->castByDto($dto, $value);
		}

		$field = $this->findField($dto, array_shift($segments));
		if ($field === null)
		{
			return $value;
		}

		if ($segments === [])
		{
			return $this->castByField($field, $value);
		}

		$type = $field->getPropertyType();
		if (TypeHelper::isDto($type))
		{
			return $this->castFieldsValue($this->createDto($type), $segments, $value);
		}

		if (!is_int(array_shift($segments)))
		{
			return $value;
		}

		$elementDtoClass = $this->getCollectionElementDtoClass($field);
		if ($elementDtoClass !== null)
		{
			return $this->castFieldsValue($this->createDto($elementDtoClass), $segments, $value);
		}

		if ($field->isMultiple() && $segments === [])
		{
			return LosslessScalarCaster::cast($type, $value);
		}

		return $value;
	}

	private function castByDto(Dto $dto, mixed $value): mixed
	{
		if (!is_array($value))
		{
			return $value;
		}

		foreach ($value as $name => $item)
		{
			$field = $this->findField($dto, $name);
			if ($field !== null)
			{
				$value[$name] = $this->castByField($field, $item);
			}
		}

		return $value;
	}

	private function castByField(FieldMetaInterface $field, mixed $value): mixed
	{
		$type = $field->getPropertyType();
		if (TypeHelper::isDto($type))
		{
			return $this->castByDto($this->createDto($type), $value);
		}

		$elementDtoClass = $this->getCollectionElementDtoClass($field);
		if ($elementDtoClass !== null)
		{
			return $this->castEachItem(
				$value,
				fn(mixed $item): mixed => $this->castByDto($this->createDto($elementDtoClass), $item),
			);
		}

		if ($field->isMultiple())
		{
			return $this->castScalarItems($type, $value);
		}

		return LosslessScalarCaster::cast($type, $value);
	}

	private function castFilterValue(Dto $dto, mixed $filter, array $segments, mixed $value): mixed
	{
		$target = $this->findFilterTarget($filter, $segments);
		if ($target === null)
		{
			return $value;
		}

		[$fieldName, $valueSegments] = $target;

		$field = $this->findField($dto, $fieldName);
		if ($field === null)
		{
			return $value;
		}

		$type = $field->getPropertyType();
		if ($valueSegments === [] && is_array($value))
		{
			return $this->castScalarItems($type, $value);
		}

		if ($valueSegments === [] || (count($valueSegments) === 1 && is_int($valueSegments[0])))
		{
			return LosslessScalarCaster::cast($type, $value);
		}

		return $value;
	}

	/**
	 * Walks the filter by the same grammar as FilterStructure::fillStructure() down to the right operand.
	 *
	 * @return array{0: mixed, 1: list<int|string>}|null field name and the path rest inside the right operand
	 */
	private function findFilterTarget(mixed $node, array $segments): ?array
	{
		if (!is_array($node) || $segments === [])
		{
			return null;
		}

		if ($this->isFilterNode($node))
		{
			return $this->findFilterNodeTarget($node, $segments);
		}

		$first = $node[0] ?? null;
		if (is_array($first) && !isset($first['expression']))
		{
			return $this->findFilterTarget($node[$segments[0]] ?? null, array_slice($segments, 1));
		}

		$valuePosition = match (count($node))
		{
			2 => 1,
			3 => 2,
			default => null,
		};

		if ($valuePosition === null || $segments[0] !== $valuePosition || count($segments) > 2)
		{
			return null;
		}

		return [$first, array_slice($segments, 1)];
	}

	private function findFilterNodeTarget(array $node, array $segments): ?array
	{
		if ($segments[0] !== 'conditions' || !isset($segments[1]) || !is_array($node['conditions'] ?? null))
		{
			return null;
		}

		$item = $node['conditions'][$segments[1]] ?? null;
		if (!is_array($item))
		{
			return null;
		}

		$rest = array_slice($segments, 2);
		$type = $item['type'] ?? null;

		if (!$this->isFilterNode($item) || $type === 'filter')
		{
			return $this->findFilterTarget($item, $rest);
		}

		if ($type !== 'condition' || ($rest[0] ?? null) !== 'rightOperand' || count($rest) > 2)
		{
			return null;
		}

		return [$item['leftOperand'] ?? null, array_slice($rest, 1)];
	}

	private function isFilterNode(array $node): bool
	{
		foreach (self::FILTER_NODE_KEYS as $key)
		{
			if (isset($node[$key]))
			{
				return true;
			}
		}

		return false;
	}

	private function findField(Dto $dto, mixed $name): ?FieldMetaInterface
	{
		if (!is_string($name))
		{
			return null;
		}

		$field = $dto->getFields()->getMeta($name);

		return $field?->getDynamicEnumProvider() === null ? $field : null;
	}

	private function getCollectionElementDtoClass(FieldMetaInterface $field): ?string
	{
		$elementType = $field->getElementType();

		return $field->getPropertyType() === DtoCollection::class && TypeHelper::isDto($elementType)
			? $elementType
			: null
		;
	}

	private function castScalarItems(string $type, mixed $value): mixed
	{
		return $this->castEachItem($value, static fn(mixed $item): mixed => LosslessScalarCaster::cast($type, $item));
	}

	private function castEachItem(mixed $value, callable $cast): mixed
	{
		if (!is_array($value))
		{
			return $value;
		}

		foreach ($value as $key => $item)
		{
			$value[$key] = $cast($item);
		}

		return $value;
	}

	private function getDto(): ?Dto
	{
		if (!$this->dtoResolved)
		{
			$this->dtoResolved = true;
			$this->dto = TypeHelper::isDto($this->dtoClass) ? $this->createDto($this->dtoClass) : null;
		}

		return $this->dto;
	}

	private function createDto(string $dtoClass): Dto
	{
		return $this->dtos[$dtoClass] ??= $dtoClass::create();
	}

	private function hasValue(array $query, array $path): bool
	{
		$node = $query;
		foreach ($path as $segment)
		{
			$isKey = is_int($segment) || is_string($segment);
			if (!$isKey || !is_array($node) || !array_key_exists($segment, $node))
			{
				return false;
			}
			$node = $node[$segment];
		}

		return true;
	}

	private function getValue(array $query, array $path): mixed
	{
		$node = $query;
		foreach ($path as $segment)
		{
			$node = $node[$segment];
		}

		return $node;
	}

	private function setValue(array &$query, array $path, mixed $value): void
	{
		$node = &$query;
		foreach ($path as $segment)
		{
			$node = &$node[$segment];
		}
		$node = $value;
		unset($node);
	}
}
