<?php

declare(strict_types=1);

namespace Bitrix\Catalog\Infrastructure\Rest\Support;

use Bitrix\Main\Error;
use Bitrix\Rest\V3\Structure\Filtering\Condition;
use Bitrix\Rest\V3\Structure\Filtering\Expressions\Expression;
use Bitrix\Rest\V3\Structure\Filtering\FilterStructure;
use Bitrix\Rest\V3\Structure\Filtering\Logic;
use Bitrix\Rest\V3\Structure\Filtering\Operator;

/**
 * Guards against FilterStructure shapes that current catalog converters flatten incorrectly
 * (OR / NOT / duplicate field+operator keys / operand expressions) and recognizes an empty IN list
 * that leaves such a supported filter unsatisfiable.
 */
final class FilterStructureSupport
{
	public static function findUnsupportedSemantics(?FilterStructure $filter): ?Error
	{
		if ($filter === null)
		{
			return null;
		}

		return self::walk($filter);
	}

	/**
	 * Detects an IN condition with an empty list on any nesting level, the same way
	 * OrmRepository recognizes an empty set.
	 *
	 * The answer means "nothing matches" only for a filter that passed findUnsupportedSemantics():
	 * it is one conjunction there, while inside an OR or NOT group an empty IN need not empty the result.
	 */
	public static function hasEmptyInCondition(?FilterStructure $filter): bool
	{
		if ($filter === null)
		{
			return false;
		}

		foreach ($filter->getConditions() as $condition)
		{
			if ($condition instanceof FilterStructure)
			{
				if (self::hasEmptyInCondition($condition))
				{
					return true;
				}

				continue;
			}

			if (
				$condition instanceof Condition
				&& $condition->getOperator() === Operator::In
				&& $condition->getRightOperand() === []
			)
			{
				return true;
			}
		}

		return false;
	}

	private static function walk(FilterStructure $filter): ?Error
	{
		if ($filter->isNegative())
		{
			return new Error('Filter NOT groups are not supported');
		}

		if ($filter->logic() === Logic::Or)
		{
			return new Error('Filter OR groups are not supported');
		}

		$seenKeys = [];
		foreach ($filter->getConditions() as $condition)
		{
			if ($condition instanceof FilterStructure)
			{
				$error = self::walk($condition);
				if ($error !== null)
				{
					return $error;
				}

				continue;
			}

			if (!$condition instanceof Condition)
			{
				continue;
			}

			// converters cast the operands to domain columns and values, an expression object would fail
			foreach ($condition->getOperands() as $operand)
			{
				if ($operand instanceof Expression)
				{
					return new Error('Filter expressions are not supported');
				}
			}

			$key = $condition->getOperator()->value . "\0" . (string)$condition->getLeftOperand();
			if (isset($seenKeys[$key]))
			{
				return new Error('Duplicate filter conditions for the same field and operator are not supported');
			}
			$seenKeys[$key] = true;
		}

		return null;
	}
}
