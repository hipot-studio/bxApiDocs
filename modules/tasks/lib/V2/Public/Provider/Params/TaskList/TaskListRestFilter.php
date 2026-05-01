<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Provider\Params\TaskList;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\ORM\Query\Filter\Condition;
use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Rest\V3\Structure\Filtering\FilterStructure;
use Bitrix\Rest\V3\Structure\Filtering\Condition as RestCondition;

class TaskListRestFilter extends AbstractTaskListFilter
{
	public function __construct(protected readonly ?FilterStructure $filter)
	{
	}

	/**
	 * @throws ArgumentException
	 */
	public function prepareFilter(): ConditionTree
	{
		return $this->prepareFilterFromStructure($this->filter) ?? new ConditionTree();
	}

	/**
	 * @throws ArgumentException
	 */
	protected function prepareFilterFromStructure(?FilterStructure $filter): ?ConditionTree
	{
		if ($filter?->getConditions())
		{
			$query = new ConditionTree();
			$query->logic($filter->logic()->value);
			$query->negative($filter->isNegative());

			foreach ($filter->getConditions() as $condition)
			{
				if ($condition instanceof RestCondition)
				{
					$convertedCondition = $this->prepareFilterCondition($condition);
					if ($convertedCondition !== null)
					{
						$query->where($convertedCondition);
					}
				}
				elseif ($condition instanceof FilterStructure)
				{
					$subFilter = $this->prepareFilterFromStructure($condition);
					if ($subFilter !== null)
					{
						$query->where($subFilter);
					}
				}
			}

			return $query;
		}

		return null;
	}

	protected function prepareFilterCondition(RestCondition $condition): ?Condition
	{
		[$field, $operator, $value] = [
			$condition->getLeftOperand(),
			$condition->getOperator()->value,
			$condition->getRightOperand()
		];

		if (null === $operator || !in_array($field, static::getAllowedFields(), true))
		{
			return null;
		}

		return new Condition($field, $operator, $value);
	}
}
