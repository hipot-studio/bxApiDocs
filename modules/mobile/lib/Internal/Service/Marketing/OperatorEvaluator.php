<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Internal\Service\Marketing;

use Bitrix\Main\Type\Date;
use Bitrix\Main\Type\DateTime;
use Bitrix\Mobile\Internal\Entity\Marketing\Condition;
use Bitrix\Mobile\Internal\Entity\Marketing\FactDefinition;
use Bitrix\Mobile\Internal\Entity\Marketing\FactType;
use Bitrix\Mobile\Internal\Entity\Marketing\Operator;
use Bitrix\Mobile\Internal\Entity\Marketing\Verdict;
use Bitrix\Mobile\Internal\Service\Marketing\Time\PortalCalendar;

/**
 * Compares one value of a fact with the parameters of a condition; reading the value is not its
 * business. A value that is no answer of the fact - null, a value of another type, an enumeration value
 * the fact does not declare - is unknown, and the condition decides what an unknown counts as.
 */
final class OperatorEvaluator
{
	public function __construct(
		private readonly PortalCalendar $calendar = new PortalCalendar(),
	)
	{
	}

	public function apply(Condition $condition, FactDefinition $fact, mixed $value): Verdict
	{
		if ($condition->isEmpty())
		{
			return Verdict::Matched;
		}

		if (!self::isAnswerOf($fact, $value))
		{
			return $condition->unknownAs ?? Verdict::Unknown;
		}

		$matched = match ($condition->operator)
		{
			Operator::InSet => self::isInSet($fact, $value, $condition->params[Condition::PARAM_VALUES] ?? []),
			Operator::Range => self::isInRange(
				$value,
				$condition->params[Condition::PARAM_FROM] ?? null,
				$condition->params[Condition::PARAM_TO] ?? null,
			),
			Operator::DateRange => $this->isInDays(
				$value,
				$condition->params[Condition::PARAM_FROM] ?? null,
				$condition->params[Condition::PARAM_TO] ?? null,
			),
			Operator::Enumeration => in_array($value, $condition->params[Condition::PARAM_VALUES] ?? [], true),
		};

		return $matched ? Verdict::Matched : Verdict::NotMatched;
	}

	private static function isAnswerOf(FactDefinition $fact, mixed $value): bool
	{
		return match ($fact->type)
		{
			FactType::Boolean => is_bool($value),
			FactType::Number => is_int($value) || is_float($value),
			FactType::Date => $value instanceof Date,
			FactType::EnumValue => is_string($value)
				&& ($fact->allowedValues === [] || in_array($value, $fact->allowedValues, true)),
			FactType::StringList => is_array($value) && array_filter($value, 'is_string') === $value,
		};
	}

	private static function isInSet(FactDefinition $fact, mixed $value, array $values): bool
	{
		return match ($fact->type)
		{
			FactType::StringList => array_intersect($value, $values) !== [],
			FactType::Number => in_array((float)$value, array_map('floatval', $values), true),
			default => in_array($value, $values, true),
		};
	}

	private static function isInRange(int|float $value, int|float|null $from, int|float|null $to): bool
	{
		return ($from === null || $value >= $from) && ($to === null || $value <= $to);
	}

	/**
	 * Days of the portal calendar are compared, not seconds: a date bound is a day as it is, and a moment
	 * - the value or a bound - is the day it falls on in the portal.
	 */
	private function isInDays(Date $value, ?Date $from, ?Date $to): bool
	{
		$day = $this->portalDay($value);

		return ($from === null || $day >= $this->portalDay($from)) && ($to === null || $day <= $this->portalDay($to));
	}

	private function portalDay(Date $date): string
	{
		return $date instanceof DateTime ? $this->calendar->day($date) : $date->format('Y-m-d');
	}
}
