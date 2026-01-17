<?php

namespace Bitrix\Rest\V3\Structures\Aggregation;

use Bitrix\Rest\V3\Attributes\Filterable;
use Bitrix\Rest\V3\Exceptions\UnknownAggregateFunctionException;
use Bitrix\Rest\V3\Exceptions\UnknownDtoPropertyException;
use Bitrix\Rest\V3\Exceptions\Validation\DtoFieldRequiredAttributeException;
use Bitrix\Rest\V3\Interaction\Request\Request;
use Bitrix\Rest\V3\Structures\Structure;
use Traversable;

final class AggregationSelectStructure extends Structure implements \IteratorAggregate
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

					if (!isset($fields[$aggregationFieldName]))
					{
						throw new UnknownDtoPropertyException($dto->getShortName(), $aggregationFieldName);
					}

					if (!$fields[$aggregationFieldName]->isFilterable())
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
