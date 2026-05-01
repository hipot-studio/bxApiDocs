<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Provider\Params\Result;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\ORM\Query\Filter\Condition;
use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Main\Provider\Params\FilterInterface;
use Bitrix\Tasks\V2\Internal\Entity\Result\Status;
use Bitrix\Tasks\V2\Public\Provider\Map\Result\TaskResultFieldToColumnMap;
use ValueError;

class TaskResultFilter implements FilterInterface
{
	private const ALLOWED_FILTER_OPERATORS = ['=', '>', '<', '<=', '>=', '!=', 'in', 'between'];

	public function __construct(
		private readonly ConditionTree $conditionTree,
	)
	{
	}

	/**
	 * @throws ArgumentException
	 */
	public function prepareFilter(): ConditionTree
	{
		return $this->mapConditionTree($this->conditionTree);
	}

	/**
	 * @throws ArgumentException
	 */
	private function mapConditionTree(ConditionTree $conditionTree): ?ConditionTree
	{
		if (empty($conditionTree->getConditions()))
		{
			return null;
		}

		$result = clone $conditionTree;
		$conditions = $conditionTree->getConditions();

		$result->removeAllConditions();

		foreach ($conditions as $condition)
		{
			$mappedCondition = match(true)
			{
				$condition instanceof ConditionTree => $this->mapConditionTree($condition),
				$condition instanceof Condition => $this->mapCondition($condition),
				default => null,
			};

			if ($mappedCondition !== null)
			{
				$result->where($mappedCondition);
			}
		}

		return $result;
	}

	private function mapCondition(Condition $condition): ?Condition
	{
		$column = $this->mapColumn($condition);
		if ($column === null)
		{
			return null;
		}

		$operator = $this->mapOperator($condition);
		if ($operator === null)
		{
			return null;
		}

		$value = $this->mapValue($condition);
		if ($value === null)
		{
			return null;
		}

		return new Condition($column, $operator, $value);
	}

	private function mapColumn(Condition $condition): ?string
	{
		$field = (string)$condition->getColumn();

		$column = TaskResultFieldToColumnMap::RELATIONS[$field] ?? null;
		if ($column === null)
		{
			return null;
		}

		if (is_array($column))
		{
			$column = reset($column);
		}

		return $column;
	}

	private function mapOperator(Condition $condition): ?string
	{
		$operator = $condition->getOperator();

		if (!in_array($operator, self::ALLOWED_FILTER_OPERATORS, true))
		{
			return null;
		}

		return $operator;
	}

	private function mapValue(Condition $condition): mixed
	{
		$field = (string)$condition->getColumn();
		$value = $condition->getValue();

		if ($field === 'status')
		{
			if (!is_string($value) && !is_array($value))
			{
				return null;
			}

			if (is_string($value))
			{
				return $this->mapStatus($value);
			}

			foreach ($value as &$item)
			{
				$item = $this->mapStatus((string)$item);
				if ($item === null)
				{
					return null;
				}
			}
		}

		return $value;
	}

	private function mapStatus(string $value): ?int
	{
		$preparedValue = strtolower($value);

		try
		{
			$mappedValue = Status::from($preparedValue)->getRaw();
		}
		catch (ValueError)
		{
			$mappedValue = null;
		}

		return $mappedValue;
	}
}
