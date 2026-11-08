<?php

namespace Bitrix\BIConnector\Internal\Operation\Builder\Dashboard;

use Bitrix\BIConnector\Internal\Operation\Builder\FailureCode;
use Bitrix\BIConnector\Internal\Operation\Builder\Refusal;
use Bitrix\Main\Result;

/**
 * The shape of `appliedFilters` the REST entries demand: a list of objects, each with
 * a string `name` and an object or array `value`, nothing else. The filter helpers rely on it and skip
 * what does not fit, so a malformed filter would quietly answer for other conditions.
 *
 * The entries hold different lines: the MCP tool schema does not check the type of `value`, and the filter
 * helpers wrap a scalar, so only an entry without the schema, the REST one, checks this shape.
 */
final class AppliedFiltersShape
{
	public static function check(array $appliedFilters): Result
	{
		if (!array_is_list($appliedFilters))
		{
			return self::refuse();
		}

		foreach ($appliedFilters as $filter)
		{
			if (
				!is_array($filter)
				|| array_diff(array_keys($filter), ['name', 'value']) !== []
				|| !is_string($filter['name'] ?? null)
				|| !is_array($filter['value'] ?? null)
			)
			{
				return self::refuse();
			}
		}

		return new Result();
	}

	private static function refuse(): Result
	{
		return Refusal::error(
			FailureCode::InvalidArgument,
			'Invalid appliedFilters: expected a list of {"name": string, "value": object or array}.',
		);
	}
}
