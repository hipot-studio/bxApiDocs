<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Internal\Entity\Marketing;

/**
 * The answer of a condition, a group or a rule. Unknown keeps a fact nobody could read apart from a
 * fact that says no, so the caller decides what an unanswered question means.
 */
enum Verdict: string
{
	case Matched = 'matched';
	case NotMatched = 'not_matched';
	case Unknown = 'unknown';

	/**
	 * Not matched decides the conjunction whatever else is unknown; nothing at all restricts nobody.
	 */
	public static function allOf(self ...$verdicts): self
	{
		return self::combine(self::NotMatched, self::Matched, $verdicts);
	}

	/**
	 * The mirror of allOf: matched decides, and no alternative at all has not matched.
	 */
	public static function anyOf(self ...$verdicts): self
	{
		return self::combine(self::Matched, self::NotMatched, $verdicts);
	}

	/**
	 * @param self[] $verdicts
	 */
	private static function combine(self $decisive, self $otherwise, array $verdicts): self
	{
		if (in_array($decisive, $verdicts, true))
		{
			return $decisive;
		}

		return in_array(self::Unknown, $verdicts, true) ? self::Unknown : $otherwise;
	}
}
