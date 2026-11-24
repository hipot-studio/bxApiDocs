<?php

declare(strict_types=1);

namespace Bitrix\Sale\Infrastructure\Rest\Support;

use Bitrix\Main\Error;
use Bitrix\Rest\V3\Structure\Filtering\Condition;
use Bitrix\Rest\V3\Structure\Filtering\Expressions\Expression;
use Bitrix\Rest\V3\Structure\Filtering\FilterStructure;
use Bitrix\Rest\V3\Structure\Filtering\Logic;
use Bitrix\Rest\V3\Structure\Filtering\Operator;

/**
 * Guards against FilterStructure shapes the classic ORM array filter cannot carry faithfully:
 * disjunction, negation, an expression on either side of a condition and a duplicated
 * field+operator predicate.
 *
 * V1 silently dropped what it could not translate and answered with a wider selection than asked
 * for. Here such a filter is refused instead.
 *
 * Recognizes as well an empty `in` list, which leaves a filter that passed the guard unsatisfiable:
 * the classic ORM cannot carry it ({@see hasEmptyInCondition()}).
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
	 * Whether an `in` condition with an empty list stands on any level of the filter, the way the
	 * platform recognizes an empty set (`OrmRepository`).
	 *
	 * The answer means "nothing matches" only for a filter that passed findUnsupportedSemantics(): it
	 * is one conjunction there, while inside an OR or NOT group an empty list need not empty the
	 * result. The classic ORM has no way to say "nothing": it builds `IN ()` on a number and fails on
	 * a date, and on a string it matches the empty value instead.
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

			$leftOperand = $condition->getLeftOperand();
			if (
				(!is_string($leftOperand) && !is_int($leftOperand))
				|| self::carriesExpression($condition->getRightOperand())
			)
			{
				// The platform lets an expression object stand in for a field name and for a value;
				// the classic array filter has nowhere to carry it on either side, and the old ORM
				// answers an object with a raw error. Refused here rather than dropped: a dropped
				// condition answers with a wider selection than was asked for.
				return new Error('Filter expressions are not supported');
			}

			$key = $condition->getOperator()->value . "\0" . (string)$leftOperand;
			if (isset($seenKeys[$key]))
			{
				return new Error('Duplicate filter conditions for the same field and operator are not supported');
			}
			$seenKeys[$key] = true;
		}

		return null;
	}

	/**
	 * Whether the value side of a condition is an expression, or a list of values holding one.
	 */
	public static function carriesExpression(mixed $rightOperand): bool
	{
		if ($rightOperand instanceof Expression)
		{
			return true;
		}

		if (is_array($rightOperand))
		{
			foreach ($rightOperand as $value)
			{
				if ($value instanceof Expression)
				{
					return true;
				}
			}
		}

		return false;
	}
}
