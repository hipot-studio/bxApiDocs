<?php

namespace Bitrix\BIConnector\DataSourceConnector\Aggregation;

/**
 * Everything a dialect needs to emit one aggregate column (design §6.4).
 *
 * AVG never reaches a dialect: the builder expands it into the sum/count pair of design §3.2 and
 * asks the dialect for the two parts separately.
 */
final class AggregateExpressionSpec
{
	public const COUNT_STAR = 'COUNT_STAR';
	public const COUNT = 'COUNT';
	public const SUM = 'SUM';
	public const MIN = 'MIN';
	public const MAX = 'MAX';

	public const FUNCTIONS = [self::COUNT_STAR, self::COUNT, self::SUM, self::MIN, self::MAX];

	public function __construct(
		public readonly string $function,
		public readonly bool $distinct,
		public readonly ?TypeSpec $inputType,
		public readonly TypeSpec $outputType,
		public readonly TypeSpec $wireType,
		public readonly ?string $expr,
	)
	{
		if (!in_array($function, self::FUNCTIONS, true))
		{
			throw AggregationException::badRequest("unknown aggregate function {$function}");
		}

		if ($function === self::COUNT_STAR)
		{
			if ($expr !== null)
			{
				throw AggregationException::badRequest('COUNT_STAR takes no expression');
			}
		}
		elseif ($expr === null || $expr === '')
		{
			throw AggregationException::badRequest("{$function} requires an expression");
		}
	}
}
