<?php

namespace Bitrix\Rest\V3\Structure\Aggregation;

use Bitrix\Rest\V3\Attribute\Filterable;
use Bitrix\Rest\V3\Exception\UnknownAggregateFunctionException;
use Bitrix\Rest\V3\Exception\UnknownDtoPropertyException;
use Bitrix\Rest\V3\Exception\Validation\DtoFieldRequiredAttributeException;
use Bitrix\Rest\V3\Exception\Validation\InvalidRequestFieldTypeException;
use Bitrix\Rest\V3\Interaction\Request\Request;
use Bitrix\Rest\V3\Structure\ReferencesDtoFields;
use Bitrix\Rest\V3\Structure\Structure;
use Traversable;

final class AggregationSelectStructure extends Structure implements \IteratorAggregate, ReferencesDtoFields
{
	/** @var SelectItem[] */
	protected array $items;

	protected int $iteratorPosition = 0;

	/**
	 * @throws DtoFieldRequiredAttributeException
	 * @throws UnknownDtoPropertyException
	 * @throws \ReflectionException
	 * @throws UnknownAggregateFunctionException
	 */
	public static function create(mixed $value, string $dtoClass, Request $request): self
	{
		$structure = new self();

		$value = (array)$value;

		$dto = self::getDto($dtoClass);

		$fields = $dto->getFields();

		if (!empty($value))
		{
			foreach ($value as $aggregation => $aggregationFields)
			{
				$aggregationFields = (array)$aggregationFields;

				foreach ($aggregationFields as $aggregationFieldName => $alias)
				{
					if (is_int($aggregationFieldName))
					{
						$aggregationFieldName = $alias;
						$alias = $aggregation . '_' . $aggregationFieldName;
					}

					$field = $fields->getMeta($aggregationFieldName);
					if ($field === null)
					{
						throw new UnknownDtoPropertyException($dto->getShortName(), $aggregationFieldName);
					}

					if (!$field->isFilterable())
					{
						throw new DtoFieldRequiredAttributeException($dto->getShortName(), $aggregationFieldName, Filterable::class);
					}

					$aggregationType = AggregationType::tryFrom($aggregation);
					if ($aggregationType === null)
					{
						throw new UnknownAggregateFunctionException($aggregation);
					}

					$structure->add(new SelectItem(
						$aggregationType,
						$aggregationFieldName,
						$alias,
					));
				}
			}
		}

		return $structure;
	}

	/**
	 * A string key names the field and its value is the alias, an integer key means the value is the field.
	 *
	 * @return list<string>
	 * @throws InvalidRequestFieldTypeException
	 */
	public static function getReferencedFields(mixed $value): array
	{
		$fields = [];
		foreach ((array)$value as $aggregationFields)
		{
			foreach ((array)$aggregationFields as $aggregationFieldName => $alias)
			{
				$fieldName = is_int($aggregationFieldName) ? $alias : $aggregationFieldName;
				if (!is_scalar($fieldName))
				{
					throw new InvalidRequestFieldTypeException('select', 'string');
				}

				$fields[] = (string)$fieldName;
			}
		}

		return array_values(array_unique($fields));
	}

	public function add(SelectItem $item): self
	{
		$this->items[] = $item;

		return $this;
	}

	public function current(): SelectItem
	{
		return $this->items[$this->iteratorPosition];
	}

	public function next(): void
	{
		$this->iteratorPosition++;
	}

	public function key(): int
	{
		return $this->iteratorPosition;
	}

	public function valid(): bool
	{
		return isset($this->items[$this->iteratorPosition]);
	}

	public function rewind(): void
	{
		$this->iteratorPosition = 0;
	}

	public function getIterator(): Traversable
	{
		return new \ArrayIterator($this->items);
	}
}
