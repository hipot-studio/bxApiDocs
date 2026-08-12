<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\DateTime;

use Bitrix\Main\SystemException;
use Bitrix\Main\Type\DateTime;

/**
 * Parses date/time arguments coming from AI tools and the REST surface.
 *
 * Contract: ISO 8601. A bare date ("YYYY-MM-DD") is normalized to a day boundary —
 * lower bound to 00:00:00, upper bound to 23:59:59, so that an inclusive date range
 * covers the whole last day. A full ISO 8601 date-time is taken as provided.
 *
 * Invalid input throws (the caller surfaces it to the agent / REST client) instead of
 * being silently dropped, which would otherwise turn a "for this period" request into a
 * lifetime one.
 */
final class DateTimeParser
{
	public const DATE_FORMAT = 'Y-m-d';

	private const DATE_TIME_FORMATS = [
		'Y-m-d\TH:i:sP',
		'Y-m-d\TH:i:s.vP',
		'Y-m-d\TH:i:s.uP',
	];

	/**
	 * Start of the period. A bare date is taken as the start of that day.
	 *
	 * @throws SystemException
	 */
	public static function getNullableLowerBound(array $props, string $key): ?DateTime
	{
		return self::getNullableBoundary($props, $key, false);
	}

	/**
	 * End of the period, inclusive. A bare date is taken as the end of that day.
	 *
	 * @throws SystemException
	 */
	public static function getNullableUpperBound(array $props, string $key): ?DateTime
	{
		return self::getNullableBoundary($props, $key, true);
	}

	/**
	 * @throws SystemException
	 */
	public static function validateRange(
		?DateTime $from,
		?DateTime $to,
		string $fromName = 'dateFrom',
		string $toName = 'dateTo'
	): void
	{
		if ($from !== null && $to !== null && $from->getTimestamp() > $to->getTimestamp())
		{
			throw new SystemException($fromName . ' must be earlier than or equal to ' . $toName . '.');
		}
	}

	/**
	 * @throws SystemException
	 */
	private static function getNullableBoundary(array $props, string $key, bool $endOfDay): ?DateTime
	{
		if (!isset($props[$key]) || !is_string($props[$key]) || trim($props[$key]) === '')
		{
			return null;
		}

		return self::parse(trim($props[$key]), $key, $endOfDay);
	}

	/**
	 * @throws SystemException
	 */
	private static function parse(string $value, string $field, bool $endOfDay): DateTime
	{
		$date = self::tryCreate(self::DATE_FORMAT, $value);
		if ($date !== null)
		{
			$date->setTime($endOfDay ? 23 : 0, $endOfDay ? 59 : 0, $endOfDay ? 59 : 0);

			return DateTime::createFromTimestamp($date->getTimestamp());
		}

		$dateTime = self::tryCreateDateTime($value);
		if ($dateTime !== null)
		{
			return DateTime::createFromTimestamp($dateTime->getTimestamp());
		}

		throw new SystemException(
			'Invalid ' . $field . ". Use an ISO 8601 date like 'YYYY-MM-DD' or a full date-time."
		);
	}

	private static function tryCreateDateTime(string $value): ?\DateTime
	{
		$value = str_ends_with($value, 'Z') ? substr($value, 0, -1) . '+00:00' : $value;

		foreach (self::DATE_TIME_FORMATS as $format)
		{
			$dateTime = self::tryCreate($format, $value);
			if ($dateTime !== null)
			{
				return $dateTime;
			}
		}

		return null;
	}

	/**
	 * Strict parse: the value must round-trip through the format exactly, so rolled-over
	 * dates ("2026-13-01") and trailing garbage are rejected rather than silently coerced.
	 */
	private static function tryCreate(string $format, string $value): ?\DateTime
	{
		$dateTime = \DateTime::createFromFormat('!' . $format, $value);
		if (!($dateTime instanceof \DateTime) || self::hasParseErrors())
		{
			return null;
		}

		if ($dateTime->format($format) !== $value)
		{
			return null;
		}

		return $dateTime;
	}

	private static function hasParseErrors(): bool
	{
		$errors = \DateTime::getLastErrors();
		if (!is_array($errors))
		{
			return false;
		}

		return ($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0;
	}
}
