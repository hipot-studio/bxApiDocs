<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Dictionary;

use Bitrix\Crm\V2\Internal\Entity\Dictionary\DictionaryField;
use Bitrix\Crm\V2\Internal\Entity\Dictionary\DictionaryFilter;
use Bitrix\Crm\V2\Internal\Entity\Dictionary\DictionaryFilterOperator;
use Bitrix\Crm\V2\Internal\Entity\Dictionary\DictionarySort;
use Bitrix\Main\Provider\Params\Pager;
use Bitrix\Rest\V3\Exception\InvalidFilterException;
use Bitrix\Rest\V3\Exception\InvalidOrderException;
use Bitrix\Rest\V3\Structure\Filtering\Condition;
use Bitrix\Rest\V3\Structure\Filtering\FilterStructure;
use Bitrix\Rest\V3\Structure\Filtering\Logic;
use Bitrix\Rest\V3\Structure\Ordering\OrderStructure;
use Bitrix\Rest\V3\Structure\PaginationStructure;

/**
 * Turns the filter, the order and the page of a standard list request into the types the dictionary
 * domain accepts. Nothing is selected, ordered or cut here - that is the work of the query the
 * repository of the domain builds.
 *
 * Unlike the mappers of the dictionaries kept in memory, this one translates rather than applies, and it
 * translates a tree rather than a list: the common filter admits groups and negation, and a flat list
 * would drop them in silence.
 *
 * WHY EVERY REFUSAL IS ANSWERED HERE FIRST. The types of the domain refuse a caller with an
 * {@see \Bitrix\Main\ArgumentException} - an operator no field supports, a value of the wrong shape, a
 * field that cannot be ordered by. Such an exception is a mistake of this class and no answer for a
 * client, so what the domain would refuse is refused here as the refusal of a filter or of an order REST
 * v3 has for it. The rules themselves are not repeated: which operator a field takes and which values it
 * holds are the questions {@see DictionaryField} answers.
 */
final class DictionaryValueListRequestMapper
{
	/** Bounds a range is made of, the shape `between` asks its value in. */
	private const RANGE_BOUNDS_COUNT = 2;

	/**
	 * The filter of the domain, or `null` for a request that selects nothing.
	 *
	 * A group carrying no condition of its own and no negation means "no filter" rather than an error: the
	 * body of a request of a route declaring query parameters is rebuilt, and the filter of the client
	 * arrives as an element of the filter of the route - so a request naming no filter at all arrives as a
	 * single empty nested group.
	 */
	public function mapFilter(?FilterStructure $filter): ?DictionaryFilter
	{
		if ($filter === null)
		{
			return null;
		}

		$mapped = $this->mapGroup($filter);

		return self::carriesNothing($mapped) ? null : $mapped;
	}

	/**
	 * The order of the domain, or `null` for a request naming none - the order the dictionary is kept in is
	 * then the answer of the domain rather than a choice of the transport. The last key of the order is not
	 * added here either: the repository of the domain appends it.
	 */
	public function mapSort(?OrderStructure $order): ?DictionarySort
	{
		$asked = [];
		foreach ($order?->getItems() ?? [] as $item)
		{
			$asked[self::sortableField($item->getProperty())->value] = $item->getOrder()->value;
		}

		return $asked === [] ? null : new DictionarySort($asked);
	}

	/**
	 * The page a list is asked for. A request naming no page gets the default page of the platform - 50
	 * values - rather than the whole dictionary: an unbounded external read is not on offer. The limit and
	 * the offset are the platform ones, and this group narrows neither.
	 *
	 * What a page may be is settled by {@see PaginationStructure} alone: a limit below one and a negative
	 * offset are refused there, so a page that reaches here is one {@see Pager} accepts.
	 */
	public function mapPager(?PaginationStructure $pagination): Pager
	{
		if ($pagination === null)
		{
			return new Pager();
		}

		return new Pager($pagination->getLimit(), $pagination->getOffset());
	}

	private function mapGroup(FilterStructure $group): DictionaryFilter
	{
		$mapped = new DictionaryFilter($group->logic() === Logic::Or, $group->isNegative());

		foreach ($group->getConditions() as $condition)
		{
			if ($condition instanceof FilterStructure)
			{
				$nested = $this->mapGroup($condition);
				if (!self::carriesNothing($nested))
				{
					$mapped->add($nested);
				}

				continue;
			}

			self::addCondition($mapped, $condition);
		}

		return $mapped;
	}

	private static function carriesNothing(DictionaryFilter $filter): bool
	{
		return $filter->getConditions() === [] && !$filter->isNegative();
	}

	/**
	 * One comparison of the common filter as a condition of the domain.
	 *
	 * An expression in either operand is refused here and nowhere else: it is no value of a field, and the
	 * common filter lets it through having checked only that the property it names exists and is
	 * filterable ({@see \Bitrix\Rest\V3\Structure\Filtering\FilterValidator::validateOperands()} skips the
	 * check of the value altogether when the right operand is one).
	 */
	private static function addCondition(DictionaryFilter $group, Condition $condition): void
	{
		$leftOperand = $condition->getLeftOperand();
		$field = is_string($leftOperand) ? DictionaryField::tryFrom($leftOperand) : null;
		$operator = DictionaryFilterOperator::tryFrom($condition->getOperator()->value);
		$value = $condition->getRightOperand();

		if (
			$field === null
			|| $operator === null
			|| !$field->supportsOperator($operator)
			|| !self::fitsOperator($field, $operator, $value)
		)
		{
			throw new InvalidFilterException($condition->getOperands());
		}

		$group->where($field, $operator, $value);
	}

	/**
	 * Whether $value is a value the pair of $field and $operator accepts. Which values a field holds is the
	 * answer of the field itself; what is spelled out here is only the shape each kind of operator asks
	 * its value in.
	 */
	private static function fitsOperator(
		DictionaryField $field,
		DictionaryFilterOperator $operator,
		mixed $value,
	): bool
	{
		if (!$operator->expectsList() && !$operator->expectsRange())
		{
			return !is_array($value) && $field->acceptsValue($value);
		}

		if (!is_array($value) || !array_is_list($value))
		{
			return false;
		}

		if ($operator->expectsRange() && count($value) !== self::RANGE_BOUNDS_COUNT)
		{
			return false;
		}

		foreach ($value as $item)
		{
			if (!$field->acceptsValue($item))
			{
				return false;
			}
		}

		return true;
	}

	/**
	 * {@see OrderStructure} accepts every sortable property of the value of the contract, so this guard
	 * answers for a contract that grows a property the domain has no field for - the case the domain itself
	 * would refuse with an exception about the caller rather than about the request.
	 */
	private static function sortableField(string $property): DictionaryField
	{
		$field = DictionaryField::tryFrom($property);
		if ($field === null || !$field->isSortable())
		{
			throw new InvalidOrderException($property);
		}

		return $field;
	}
}
