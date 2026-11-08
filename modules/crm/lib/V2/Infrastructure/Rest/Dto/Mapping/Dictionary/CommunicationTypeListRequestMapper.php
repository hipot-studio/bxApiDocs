<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Dictionary;

use Bitrix\Crm\V2\Public\Entity\Dictionary\CommunicationType;
use Bitrix\Main\DB\Order;
use Bitrix\Rest\V3\Exception\InvalidFilterException;
use Bitrix\Rest\V3\Exception\InvalidOrderException;
use Bitrix\Rest\V3\Exception\InvalidPaginationException;
use Bitrix\Rest\V3\Interaction\Request\ListRequest;
use Bitrix\Rest\V3\Structure\Filtering\Condition;
use Bitrix\Rest\V3\Structure\Filtering\Expressions\Expression;
use Bitrix\Rest\V3\Structure\Filtering\FilterStructure;
use Bitrix\Rest\V3\Structure\Filtering\Logic;
use Bitrix\Rest\V3\Structure\Filtering\Operator;

/**
 * Applies the filter, the order and the pagination of a list request to a dictionary kept in memory.
 *
 * Unlike the mappers of the published dictionaries, a group of conditions carrying no leaf condition
 * means "no filter" rather than an error: a route with non-empty queryParams receives the client
 * filter as an element of the route filter list, so a request without a filter arrives as a single
 * empty nested group.
 */
final class CommunicationTypeListRequestMapper
{
	private const FILTER_OPERATORS = [Operator::Equal, Operator::NotEqual, Operator::In];

	/**
	 * @param list<CommunicationType> $items
	 * @return list<CommunicationType>
	 */
	public function map(array $items, ListRequest $request): array
	{
		$items = $this->filter($items, $request->filter);
		$items = $this->sort($items, $request);

		return $this->paginate($items, $request);
	}

	/**
	 * @param list<CommunicationType> $items
	 * @return list<CommunicationType>
	 */
	private function filter(array $items, ?FilterStructure $filter): array
	{
		$effectiveGroups = [];
		if ($filter === null || !$this->collectEffectiveGroups($filter, $effectiveGroups))
		{
			return $items;
		}

		return array_values(array_filter(
			$items,
			fn(CommunicationType $item): bool => $this->matchesFilter($item, $filter, $effectiveGroups),
		));
	}

	/**
	 * Marks every group carrying a leaf condition in a single pass over the filter: the answer does not
	 * depend on the item, while a group is met once per item and once per enclosing level.
	 *
	 * A negated group counts as one such group even when it carries no leaf condition: the route names
	 * its channel and never its negation, so negation is the client's word and is answered the way the
	 * dictionaries whose routes name no channel answer it - with a refusal in matchesFilter().
	 *
	 * @param array<int, true> $effectiveGroups Keyed by the object id of the group.
	 */
	private function collectEffectiveGroups(FilterStructure $filter, array &$effectiveGroups): bool
	{
		$isEffective = $filter->isNegative();
		foreach ($filter->getConditions() as $condition)
		{
			if ($condition instanceof Condition)
			{
				$isEffective = true;

				continue;
			}
			if ($condition instanceof FilterStructure)
			{
				$hasEffectiveNested = $this->collectEffectiveGroups($condition, $effectiveGroups);
				$isEffective = $isEffective || $hasEffectiveNested;
			}
		}

		if ($isEffective)
		{
			$effectiveGroups[spl_object_id($filter)] = true;
		}

		return $isEffective;
	}

	/**
	 * @param array<int, true> $effectiveGroups
	 */
	private function matchesFilter(CommunicationType $item, FilterStructure $filter, array $effectiveGroups): bool
	{
		if ($filter->isNegative())
		{
			throw new InvalidFilterException($filter->getList());
		}

		$matches = [];
		foreach ($filter->getConditions() as $condition)
		{
			if ($condition instanceof FilterStructure)
			{
				if (isset($effectiveGroups[spl_object_id($condition)]))
				{
					$matches[] = $this->matchesFilter($item, $condition, $effectiveGroups);
				}

				continue;
			}

			$matches[] = $this->matchesCondition($item, $condition);
		}

		return $filter->logic() === Logic::And
			? !in_array(false, $matches, true)
			: in_array(true, $matches, true)
		;
	}

	private function matchesCondition(CommunicationType $item, Condition $condition): bool
	{
		$field = $condition->getLeftOperand();
		$value = $condition->getRightOperand();
		$operator = $condition->getOperator();
		if (
			!is_string($field)
			|| !in_array($field, CommunicationTypeDtoMapper::FIELDS, true)
			|| !in_array($operator, self::FILTER_OPERATORS, true)
			|| $this->containsExpression($value)
		)
		{
			throw new InvalidFilterException($condition->getOperands());
		}

		$fieldValue = CommunicationTypeDtoMapper::getFieldValue($item, $field);

		return match ($operator)
		{
			Operator::Equal => $fieldValue === $value,
			Operator::NotEqual => $fieldValue !== $value,
			Operator::In => $this->matchesIn($fieldValue, $value, $condition),
			default => throw new InvalidFilterException($condition->getOperands()),
		};
	}

	private function matchesIn(string|bool|null $fieldValue, mixed $value, Condition $condition): bool
	{
		if (!is_array($value))
		{
			throw new InvalidFilterException($condition->getOperands());
		}

		return in_array($fieldValue, $value, true);
	}

	private function containsExpression(mixed $value): bool
	{
		if ($value instanceof Expression)
		{
			return true;
		}
		if (!is_array($value))
		{
			return false;
		}

		foreach ($value as $item)
		{
			if ($this->containsExpression($item))
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * @param list<CommunicationType> $items
	 * @return list<CommunicationType>
	 */
	private function sort(array $items, ListRequest $request): array
	{
		$order = $this->getOrder($request);
		if ($order === [])
		{
			return $items;
		}

		// usort is stable since PHP 8.0, so entries equal by every key keep the declaration order.
		usort($items, function (CommunicationType $left, CommunicationType $right) use ($order): int {
			foreach ($order as $field => $direction)
			{
				$comparison = $this->compareValues(
					CommunicationTypeDtoMapper::getFieldValue($left, $field),
					CommunicationTypeDtoMapper::getFieldValue($right, $field),
				);
				if ($comparison !== 0)
				{
					return $direction === Order::Asc ? $comparison : -$comparison;
				}
			}

			return 0;
		});

		return $items;
	}

	/**
	 * OrderStructure accepts every sortable property of the dto, so a property the published set does
	 * not carry is refused here instead of reaching the value binding as an unhandled match.
	 *
	 * @return array<string, Order>
	 */
	private function getOrder(ListRequest $request): array
	{
		$order = [];
		foreach ($request->order?->getItems() ?? [] as $orderItem)
		{
			$field = $orderItem->getProperty();
			if (!in_array($field, CommunicationTypeDtoMapper::FIELDS, true))
			{
				throw new InvalidOrderException($field);
			}

			$order[$field] = $orderItem->getOrder();
		}

		return $order;
	}

	/**
	 * Ascending order: an absent caption comes first, false comes before true.
	 */
	private function compareValues(string|bool|null $left, string|bool|null $right): int
	{
		if ($left === $right)
		{
			return 0;
		}
		if ($left === null)
		{
			return -1;
		}
		if ($right === null)
		{
			return 1;
		}
		if (is_bool($left) || is_bool($right))
		{
			return $left === true ? 1 : -1;
		}

		return strcmp($left, $right);
	}

	/**
	 * @param list<CommunicationType> $items
	 * @return list<CommunicationType>
	 */
	private function paginate(array $items, ListRequest $request): array
	{
		if ($request->pagination === null)
		{
			return $items;
		}

		$limit = $request->pagination->getLimit();
		$offset = $request->pagination->getOffset();
		if ($limit <= 0)
		{
			throw new InvalidPaginationException(['limit' => $limit]);
		}
		if ($offset < 0)
		{
			throw new InvalidPaginationException(['offset' => $offset]);
		}

		return array_slice($items, $offset, $limit);
	}
}
