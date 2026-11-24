<?php

declare(strict_types=1);

namespace Bitrix\Sale\Infrastructure\Rest\Support;

use Bitrix\Main\Engine\Response\Converter;
use Bitrix\Rest\V3\Structure\Filtering\Condition;
use Bitrix\Rest\V3\Structure\Filtering\FilterStructure;
use Bitrix\Rest\V3\Structure\Ordering\OrderStructure;

/**
 * Bridge from V3 request structures to the classic ORM array query.
 *
 * Conjunction only. Disjunction and negation are refused earlier, by
 * {@see FilterStructureSupport::findUnsupportedSemantics()}, so the conversion never meets them.
 * Values come from the parsed tree rather than the raw request, so Date/DateTime objects produced
 * by the transport survive it.
 *
 * Column names are resolved through an explicit map: blind camelCase-to-UPPER_SNAKE breaks on
 * names such as `id1c`.
 */
final class LegacyQueryConverter
{
	/**
	 * `in` goes as an equality with a list: the old ORM compiles that on every type of field, while
	 * its own `@` fails on a fractional and a date one. An empty list is converted as well, into an
	 * equality with an empty list, but that filter never reaches a query: the caller answers it with
	 * nothing before querying ({@see FilterStructureSupport::hasEmptyInCondition()}).
	 */
	private const OPERATOR_PREFIX = [
		'=' => '=',
		'!=' => '!=',
		'>' => '>',
		'>=' => '>=',
		'<' => '<',
		'<=' => '<=',
		'in' => '=',
		'between' => '><',
	];

	/**
	 * @param array<string, string> $columnMap DTO property => domain column
	 * @return array<int|string, mixed>
	 */
	public static function filterToLegacy(?FilterStructure $filter, array $columnMap = []): array
	{
		if ($filter === null)
		{
			return [];
		}

		return self::convertGroup($filter, $columnMap);
	}

	/**
	 * @param array<string, string> $columnMap DTO property => domain column
	 * @return array<string, string>
	 */
	public static function orderToLegacy(?OrderStructure $order, array $columnMap = []): array
	{
		$result = [];
		foreach ($order?->getList() ?? [] as $property => $direction)
		{
			$result[self::resolveColumn((string)$property, $columnMap)] = (string)$direction;
		}

		return $result;
	}

	/**
	 * The domain keeps flags as Y/N chars while the transport contract is boolean.
	 *
	 * @param array<int|string, mixed> $filter
	 * @param list<string> $columns domain column names, without an operator prefix
	 * @return array<int|string, mixed>
	 */
	public static function convertBoolFilterValuesToYn(array $filter, array $columns): array
	{
		if ($filter === [] || $columns === [])
		{
			return $filter;
		}

		$columnMap = array_fill_keys($columns, true);
		$result = [];

		foreach ($filter as $key => $value)
		{
			if (is_int($key))
			{
				$result[$key] = is_array($value)
					? self::convertBoolFilterValuesToYn($value, $columns)
					: $value;

				continue;
			}

			if ($key === 'LOGIC')
			{
				$result[$key] = $value;

				continue;
			}

			$result[$key] = isset($columnMap[self::stripOperatorPrefix($key)])
				? self::boolToYn($value)
				: $value;
		}

		return $result;
	}

	/**
	 * @param array<string, string> $columnMap
	 * @return array<int|string, mixed>
	 */
	private static function convertGroup(FilterStructure $filter, array $columnMap): array
	{
		$parts = [];
		foreach ($filter->getConditions() as $condition)
		{
			if ($condition instanceof FilterStructure)
			{
				$part = self::convertGroup($condition, $columnMap);
			}
			elseif ($condition instanceof Condition)
			{
				$part = self::convertCondition($condition, $columnMap);
			}
			else
			{
				continue;
			}

			if ($part !== [])
			{
				$parts[] = $part;
			}
		}

		return $parts === [] ? [] : self::asAndGroup($parts);
	}

	/**
	 * @param array<string, string> $columnMap
	 * @return array<string, mixed>
	 */
	private static function convertCondition(Condition $condition, array $columnMap): array
	{
		$leftOperand = $condition->getLeftOperand();
		if (
			(!is_string($leftOperand) && !is_int($leftOperand))
			|| FilterStructureSupport::carriesExpression($condition->getRightOperand())
		)
		{
			// Unreachable through the surface: an expression in place of a field name or a value is
			// refused by FilterStructureSupport before the conversion runs. Kept loud on purpose —
			// dropping the condition instead would answer with a wider selection than was asked
			// for, which is the V1 behaviour this class exists to end, and passing the object on
			// hands the old ORM something it cannot compile.
			throw new \LogicException('A filter expression reached the legacy query converter');
		}

		$prefix = self::OPERATOR_PREFIX[$condition->getOperator()->value] ?? '=';

		return [$prefix . self::resolveColumn((string)$leftOperand, $columnMap) => $condition->getRightOperand()];
	}

	/**
	 * AND is the ORM default, so a group of single-field conditions flattens into a plain map.
	 * Conditions that would collide on the same key stay separate members under LOGIC = AND.
	 *
	 * @param list<array<int|string, mixed>> $parts
	 * @return array<int|string, mixed>
	 */
	private static function asAndGroup(array $parts): array
	{
		$flat = [];
		$groups = [];

		foreach ($parts as $part)
		{
			if (isset($part['LOGIC']) || array_intersect_key($flat, $part) !== [])
			{
				$groups[] = $part;

				continue;
			}

			$flat += $part;
		}

		if ($groups === [])
		{
			return $flat;
		}

		return self::asAndLogicGroup($flat === [] ? $groups : array_merge([$flat], $groups));
	}

	/**
	 * @param list<array<int|string, mixed>> $parts
	 * @return array<int|string, mixed>
	 */
	private static function asAndLogicGroup(array $parts): array
	{
		$group = ['LOGIC' => 'AND'];
		foreach ($parts as $part)
		{
			$group[] = $part;
		}

		return $group;
	}

	/**
	 * @param array<string, string> $columnMap
	 */
	private static function resolveColumn(string $property, array $columnMap): string
	{
		return $columnMap[$property]
			?? (new Converter(Converter::TO_UPPER | Converter::TO_SNAKE))->process($property);
	}

	private static function stripOperatorPrefix(string $key): string
	{
		return ltrim($key, '=!<>@%*');
	}

	private static function boolToYn(mixed $value): mixed
	{
		if (is_bool($value))
		{
			return $value ? 'Y' : 'N';
		}

		if (is_array($value))
		{
			return array_map(static fn(mixed $item): mixed => self::boolToYn($item), $value);
		}

		return $value;
	}
}
