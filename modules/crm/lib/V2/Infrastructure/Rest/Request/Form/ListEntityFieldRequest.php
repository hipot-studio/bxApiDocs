<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Request\Form;

use Bitrix\Main\Validation\Rule\NotEmpty;
use Bitrix\Rest\V3\Exception\InvalidFilterException;
use Bitrix\Rest\V3\Interaction\Request\Request;
use Bitrix\Rest\V3\Structure\Filtering\Condition;
use Bitrix\Rest\V3\Structure\Filtering\FilterStructure;
use Bitrix\Rest\V3\Structure\Filtering\Operator;

/**
 * The request of the dictionary of the fields a form may be built of.
 *
 * The dictionary is built entity by entity out of the whole composition of the portal, so a portal
 * of many smart processes pays for every one of them on every call. A caller that already knows the
 * entities it is interested in must name them, and then only those are built.
 *
 * The filter is the one the family reads a list by, in the shape the family already uses for the
 * form of a widget list: one condition, `entityName`, `=` for a single entity and `in` for several.
 * Nothing else of a list is published - the dictionary has no order of its own to change and no page
 * to take, because the tree behind it is built whole before it is flattened.
 */
final class ListEntityFieldRequest extends Request
{
	use PlainFilterTrait;

	private const ENTITY_NAME_FIELD = 'entityName';
	private const ENTITY_NAME_LIMIT = 10;

	#[NotEmpty]
	public ?FilterStructure $filter = null;

	/**
	 * The entities the dictionary is narrowed to.
	 *
	 * An empty list is an answer of its own and not the absence of a filter: a caller that asked for
	 * the fields of no entity is answered with no field, the way an empty `in` is read everywhere in
	 * the family.
	 *
	 * A filter that negates its conditions, joins them by alternative or carries more than the one
	 * condition of the entity is refused: read flat, it would answer about entities the caller did
	 * not ask about.
	 *
	 * @return list<string>
	 * @throws InvalidFilterException
	 */
	public function getEntityNames(): array
	{
		$conditions = $this->filter?->getConditions() ?? [];
		if ($conditions === [])
		{
			throw new InvalidFilterException($this->filter?->getList());
		}

		if (count($conditions) !== 1 || !$this->isPlainGroup($this->filter))
		{
			throw new InvalidFilterException($this->filter->getList());
		}

		return $this->readEntityNames($this->unwrap($conditions[0]));
	}

	/**
	 * @throws InvalidFilterException
	 */
	private function unwrap(Condition|FilterStructure $condition): Condition
	{
		if ($condition instanceof FilterStructure)
		{
			$nested = $condition->getConditions();
			if (count($nested) !== 1 || !$this->isPlainGroup($condition))
			{
				throw new InvalidFilterException($this->filter->getList());
			}

			return $this->unwrap($nested[0]);
		}

		return $condition;
	}

	/**
	 * @return list<string>
	 * @throws InvalidFilterException
	 */
	private function readEntityNames(Condition $condition): array
	{
		if ($condition->getLeftOperand() !== self::ENTITY_NAME_FIELD)
		{
			throw new InvalidFilterException($this->filter->getList());
		}

		$value = $condition->getRightOperand();
		$values = match ($condition->getOperator())
		{
			Operator::Equal => [$value],
			Operator::In => is_array($value) ? $this->readInValues($value) : null,
			default => null,
		};

		if ($values === null)
		{
			throw new InvalidFilterException($this->filter->getList());
		}

		foreach ($values as $entityName)
		{
			if (!is_string($entityName) || $entityName === '')
			{
				throw new InvalidFilterException($this->filter->getList());
			}
		}

		return $values;
	}

	/**
	 * @param array<mixed> $value
	 * @return list<mixed>
	 * @throws InvalidFilterException
	 */
	private function readInValues(array $value): array
	{
		if (count($value) > self::ENTITY_NAME_LIMIT)
		{
			throw new InvalidFilterException($this->filter->getList());
		}

		return array_values($value);
	}
}
