<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Requisite;

use Bitrix\Crm\V2\Public\Provider\Requisite\Param\BankRequisiteFilter;
use Bitrix\Crm\V2\Public\Provider\Requisite\Param\BankRequisiteFilterOperator;
use Bitrix\Crm\V2\Public\Provider\Requisite\Param\BankRequisiteSort;
use Bitrix\Main\Provider\Params\Pager;
use Bitrix\Rest\V3\Exception\InvalidFilterException;
use Bitrix\Rest\V3\Exception\InvalidPaginationException;
use Bitrix\Rest\V3\Exception\InvalidSelectException;
use Bitrix\Rest\V3\Interaction\Request\ListRequest;
use Bitrix\Rest\V3\Structure\Filtering\Condition;
use Bitrix\Rest\V3\Structure\Filtering\Expressions\Expression;
use Bitrix\Rest\V3\Structure\Filtering\FilterStructure;
use Bitrix\Rest\V3\Structure\Filtering\Logic;
use Bitrix\Rest\V3\Structure\Filtering\Operator;

final class BankRequisiteListRequestMapper
{
	private const CONTEXT_FIELDS = ['requisiteId', 'contactId', 'companyId'];

	public function extractContextId(FilterStructure $filter, string $contextField): int
	{
		if (!in_array($contextField, self::CONTEXT_FIELDS, true))
		{
			throw new InvalidFilterException($filter->getList());
		}
		$filter = $this->unwrapRootGroup($filter);
		if ($filter->logic() !== Logic::And || $filter->isNegative())
		{
			throw new InvalidFilterException($filter->getList());
		}

		$contextIds = [];
		foreach ($filter->getConditions() as $condition)
		{
			$simple = $this->unwrapSimpleCondition($condition);
			if ($simple?->getLeftOperand() === $contextField)
			{
				if ($simple->getOperator() !== Operator::Equal || !is_int($simple->getRightOperand()))
				{
					throw new InvalidFilterException($simple->getOperands());
				}

				$contextIds[] = $simple->getRightOperand();
				continue;
			}

			if ($this->containsContextField($condition))
			{
				throw new InvalidFilterException($filter->getList());
			}
		}

		if (count($contextIds) !== 1 || $contextIds[0] <= 0)
		{
			throw new InvalidFilterException($filter->getList());
		}

		return $contextIds[0];
	}

	public function mapFilter(?FilterStructure $filter, ?string $contextField = null): ?BankRequisiteFilter
	{
		if ($filter === null)
		{
			return null;
		}
		if ($contextField !== null && !in_array($contextField, self::CONTEXT_FIELDS, true))
		{
			throw new InvalidFilterException($filter->getList());
		}
		$filter = $this->unwrapRootGroup($filter);

		$mapped = $this->mapFilterStructure($filter, $contextField, true);

		return $mapped->getConditions() === [] ? null : $mapped;
	}

	public function mapSort(ListRequest $request): BankRequisiteSort
	{
		return new BankRequisiteSort($request->order?->getList() ?? []);
	}

	public function mapPager(ListRequest $request): Pager
	{
		if ($request->pagination === null)
		{
			return new Pager();
		}

		$limit = $request->pagination->getLimit();
		$offset = $request->pagination->getOffset();
		if ($limit <= 0 || $offset < 0)
		{
			throw new InvalidPaginationException([
				'limit' => $limit,
				'offset' => $offset,
			]);
		}

		return new Pager($limit, $offset);
	}

	public function validateSelect(ListRequest $request): void
	{
		foreach ($request->select?->getList() ?? [] as $field)
		{
			if (in_array($field, ['contactId', 'companyId'], true))
			{
				throw new InvalidSelectException($field);
			}
		}
	}

	private function mapFilterStructure(
		FilterStructure $filter,
		?string $contextField,
		bool $root,
	): BankRequisiteFilter
	{
		$mapped = new BankRequisiteFilter(
			or: $filter->logic() === Logic::Or,
			negative: $filter->isNegative(),
		);
		foreach ($filter->getConditions() as $condition)
		{
			$simple = $root ? $this->unwrapSimpleCondition($condition) : null;
			if ($contextField !== null && $simple?->getLeftOperand() === $contextField)
			{
				continue;
			}
			if ($contextField !== null && $this->containsContextField($condition))
			{
				throw new InvalidFilterException($filter->getList());
			}

			if ($condition instanceof FilterStructure)
			{
				$mapped->add($this->mapFilterStructure($condition, $contextField, false));
				continue;
			}
			if (is_array($condition))
			{
				foreach ($condition as $nested)
				{
					if (!$nested instanceof FilterStructure)
					{
						throw new InvalidFilterException($filter->getList());
					}
					$mapped->add($this->mapFilterStructure($nested, $contextField, false));
				}
				continue;
			}

			$this->mapCondition($mapped, $condition);
		}

		return $mapped;
	}

	private function mapCondition(BankRequisiteFilter $filter, Condition $condition): void
	{
		$field = $condition->getLeftOperand();
		$value = $condition->getRightOperand();
		$operator = $condition->getOperator();
		if (!is_string($field) || $this->containsExpression($value))
		{
			throw new InvalidFilterException($condition->getOperands());
		}
		if ($operator === Operator::Between && $field !== 'id')
		{
			throw new InvalidFilterException($condition->getOperands());
		}
		if ($operator === Operator::Between && (!is_array($value) || count($value) !== 2))
		{
			throw new InvalidFilterException($condition->getOperands());
		}

		$filter->where(
			$field,
			BankRequisiteFilterOperator::from($operator->value),
			$value,
		);
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

	private function unwrapSimpleCondition(mixed $condition): ?Condition
	{
		if ($condition instanceof Condition)
		{
			return $condition;
		}
		if (
			$condition instanceof FilterStructure
			&& !$condition->isNegative()
			&& $condition->logic() === Logic::And
			&& count($condition->getConditions()) === 1
			&& $condition->getConditions()[0] instanceof Condition
		)
		{
			return $condition->getConditions()[0];
		}

		return null;
	}

	private function unwrapRootGroup(FilterStructure $filter): FilterStructure
	{
		$rawFilter = $filter->getList();
		$conditions = $filter->getConditions();
		if (
			array_is_list($rawFilter)
			&& count($rawFilter) === 1
			&& $filter->logic() === Logic::And
			&& !$filter->isNegative()
			&& count($conditions) === 1
			&& $conditions[0] instanceof FilterStructure
		)
		{
			return $conditions[0];
		}

		return $filter;
	}

	private function containsContextField(mixed $condition): bool
	{
		if ($condition instanceof Condition)
		{
			return in_array($condition->getLeftOperand(), self::CONTEXT_FIELDS, true);
		}
		if ($condition instanceof FilterStructure)
		{
			foreach ($condition->getConditions() as $nested)
			{
				if ($this->containsContextField($nested))
				{
					return true;
				}
			}
		}
		if (is_array($condition))
		{
			foreach ($condition as $nested)
			{
				if ($this->containsContextField($nested))
				{
					return true;
				}
			}
		}

		return false;
	}
}
