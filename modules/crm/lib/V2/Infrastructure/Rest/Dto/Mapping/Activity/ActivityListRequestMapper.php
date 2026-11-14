<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Activity;

use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Activity\ActivityDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Structure\ActivityListFilterStructure;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Structure\ActivityListPaginationStructure;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Structure\ContextActivityListFilterStructure;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Structure\ItemListFilterStructure;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Structure\ItemListOrderStructure;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Structure\ItemListPaginationStructure;
use Bitrix\Crm\V2\Internal\Entity\Activity\Activity;
use Bitrix\Crm\V2\Internal\Service\Activity\Read\ActivityFilter;
use Bitrix\Crm\V2\Internal\Service\Activity\Read\ActivitySort;
use Bitrix\Crm\V2\Public\Provider\Activity\Param\ActivityListFilter;
use Bitrix\Crm\V2\Public\Provider\Activity\Param\ActivityListSort;
use Bitrix\Main\DB\Order;
use Bitrix\Main\ORM\Query\Filter;
use Bitrix\Main\Provider\Params\Pager;
use Bitrix\Rest\V3\Exception\InvalidFilterException;
use Bitrix\Rest\V3\Exception\InvalidPaginationException;
use Bitrix\Rest\V3\Exception\UnknownDtoPropertyException;
use Bitrix\Rest\V3\Structure\Filtering\Condition;
use Bitrix\Rest\V3\Structure\Filtering\Expressions\Expression;
use Bitrix\Rest\V3\Structure\Filtering\Operator;
use Bitrix\Rest\V3\Structure\Ordering\OrderStructure;
use Bitrix\Rest\V3\Structure\PaginationStructure;

/**
 * Turns the filter, the order and the paging of a list of activities - the global one and the one of a parent -
 * into the public terms of {@see \Bitrix\Crm\V2\Public\Provider\Activity\ActivityProvider}. A field is published
 * under a name of its own only where the model names it otherwise; every other name is the one of the model, and
 * the closed lists of the read scenario decide what is accepted.
 */
final class ActivityListRequestMapper
{
	private const MODEL_NAMES = [
		'assignedById' => Activity::responsibleId,
		'deadlineTime' => Activity::deadline,
	];

	/**
	 * @throws InvalidFilterException
	 * @throws UnknownDtoPropertyException
	 */
	public function mapFilter(?ActivityListFilterStructure $filter): ActivityListFilter
	{
		if ($filter === null)
		{
			return new ActivityListFilter();
		}

		$conditions = $filter->getConditions();

		return new ActivityListFilter(
			$filter->getBindingEntityTypeId(),
			$filter->getBindingEntityId(),
			$conditions !== null ? $this->mapGroup($conditions) : null,
		);
	}

	/**
	 * The conditions of the list of one parent, without a binding: the parent is named to
	 * {@see \Bitrix\Crm\V2\Public\Provider\Activity\ActivityProvider::getListInContext()} by its arguments alone.
	 *
	 * @throws InvalidFilterException
	 * @throws UnknownDtoPropertyException
	 */
	public function mapContextFilter(ContextActivityListFilterStructure $filter): ActivityListFilter
	{
		$conditions = $filter->getConditions();

		return new ActivityListFilter(conditions: $conditions !== null ? $this->mapGroup($conditions) : null);
	}

	/**
	 * The order as the client named it; the provider ends it with the identifier.
	 *
	 * @throws UnknownDtoPropertyException
	 */
	public function mapSort(OrderStructure|ItemListOrderStructure|null $order): ActivityListSort
	{
		$sort = [];
		foreach ($order?->getList() ?? [] as $fieldName => $direction)
		{
			$sort[$this->modelFieldOf((string)$fieldName, ActivitySort::FIELDS)] = Order::from($direction);
		}

		return new ActivityListSort($sort);
	}

	/**
	 * The page arrives normalized by the structure of the transport: a `page` is already the skip of
	 * `(page - 1) * limit` and wins over an `offset`, and the limit is already cut to the maximum of the method. Both
	 * bounds are held here once more, because the scenario reads whatever it is given.
	 *
	 * @throws InvalidPaginationException
	 */
	public function mapPager(
		PaginationStructure|ItemListPaginationStructure|ActivityListPaginationStructure|null $pagination,
	): Pager
	{
		if ($pagination === null)
		{
			return new Pager(PaginationStructure::DEFAULT_LIMIT, 0);
		}

		$limit = $pagination->getLimit();
		$offset = $pagination->getOffset();
		if ($limit <= 0)
		{
			throw new InvalidPaginationException(['limit' => $limit]);
		}
		if ($offset < 0)
		{
			throw new InvalidPaginationException(['offset' => $offset]);
		}

		return new Pager(min($limit, ActivityListPaginationStructure::MAX_LIMIT), $offset);
	}

	/**
	 * @throws InvalidFilterException
	 * @throws UnknownDtoPropertyException
	 */
	private function mapGroup(ItemListFilterStructure $group): Filter\ConditionTree
	{
		$tree = (new Filter\ConditionTree())
			->logic($group->getLogic()->value)
			->negative($group->isNegative())
		;

		foreach ($group->getConditions() as $condition)
		{
			$tree->addCondition(
				$condition instanceof ItemListFilterStructure
					? $this->mapGroup($condition)
					: $this->mapCondition($condition)
			);
		}

		return $tree;
	}

	/**
	 * @throws InvalidFilterException
	 * @throws UnknownDtoPropertyException
	 */
	private function mapCondition(Condition $condition): Filter\Condition
	{
		$fieldName = $condition->getLeftOperand();
		$operator = $condition->getOperator();
		$value = $condition->getRightOperand();
		if (!is_string($fieldName) || $value instanceof Expression)
		{
			throw new InvalidFilterException([$fieldName, $operator->value, $value]);
		}
		// The storage has no statement for an empty enumeration, and dropping it would widen the list.
		if ($operator === Operator::In && (!is_array($value) || $value === []))
		{
			throw new InvalidFilterException([$fieldName, $operator->value, $value]);
		}

		return new Filter\Condition($this->modelFieldOf($fieldName, ActivityFilter::FIELDS), $operator->value, $value);
	}

	/**
	 * @param string[] $acceptedFields
	 * @throws UnknownDtoPropertyException
	 */
	private function modelFieldOf(string $fieldName, array $acceptedFields): string
	{
		$field = self::MODEL_NAMES[$fieldName] ?? $fieldName;
		if (!in_array($field, $acceptedFields, true))
		{
			throw new UnknownDtoPropertyException(ActivityDto::create()->getShortName(), $fieldName);
		}

		return $field;
	}
}
