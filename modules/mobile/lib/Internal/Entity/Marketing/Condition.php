<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Internal\Entity\Marketing;

use Bitrix\Main\ArgumentException;

/**
 * A fact, an operator and its parameters. The operator reads "values" (a set or an enumeration) or
 * "from" and "to" (a range, either bound optional); any other key is a parameter of the fact, such as
 * the "since" threshold of a dated action.
 *
 * A condition may say what an unknown fact counts as; without that the unknown stays unknown.
 */
final class Condition
{
	public const PARAM_VALUES = 'values';
	public const PARAM_FROM = 'from';
	public const PARAM_TO = 'to';

	/**
	 * @throws ArgumentException
	 */
	public function __construct(
		public readonly string $factCode,
		public readonly Operator $operator,
		public readonly array $params,
		public readonly ?Verdict $unknownAs = null,
	)
	{
		if ($unknownAs === Verdict::Unknown)
		{
			throw new ArgumentException('An unknown fact may count as matched or not matched only', 'unknownAs');
		}
	}

	/**
	 * Empty parameters do not restrict the audience, and the fact of such a condition is never read.
	 */
	public function isEmpty(): bool
	{
		return match ($this->operator)
		{
			Operator::InSet, Operator::Enumeration => ($this->params[self::PARAM_VALUES] ?? []) === [],
			Operator::Range, Operator::DateRange => ($this->params[self::PARAM_FROM] ?? null) === null
				&& ($this->params[self::PARAM_TO] ?? null) === null,
		};
	}
}
