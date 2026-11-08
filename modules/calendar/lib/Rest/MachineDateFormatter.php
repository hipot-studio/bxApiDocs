<?php

declare(strict_types=1);

namespace Bitrix\Calendar\Rest;

use Bitrix\Main;

/**
 * Formats a record mark of a calendar event as an ISO-8601 string with an explicit offset.
 *
 * The offset is always the one of the portal: both consumers of this class, TIMESTAMP_X and
 * DATE_CREATE, are marks of the record itself and are written in the wall time of the portal.
 *
 * A value that arrives as a string has to be wrapped into a date object before it reaches the
 * converter. The string branch of CRestUtil::ConvertDateTime() subtracts the offset of the caller,
 * which was never added to the value, so the moment moves while the format stays valid.
 *
 * The result of a string is memoized: one response of a reading method repeats a single mark over
 * every instance of a series, and the same string always means the same moment. Both consumers are
 * read the same way, so one keyspace serves them; the timezone of the process is a part of the key
 * because the cast follows it, and the calendar moves it while it reads.
 */
final class MachineDateFormatter
{
	// a response has no limit of its own, so the memo needs one to stay bounded on a huge one
	private const CACHE_LIMIT = 5000;

	/** @var array<string, string|null> */
	private static array $cache = [];

	private static ?string $cultureFormat = null;

	/**
	 * Returns the value in ISO-8601, or null when it is empty or cannot be parsed.
	 *
	 * @param string|Main\Type\DateTime|null $value Record mark in the culture format of the portal.
	 * @return string|null
	 */
	public static function format(string|Main\Type\DateTime|null $value): ?string
	{
		if ($value === null || $value === '')
		{
			return null;
		}

		if ($value instanceof Main\Type\DateTime)
		{
			// disableUserTime() mutates the object it is called on, so the argument itself is left alone
			$dateTime = clone $value;
			$dateTime->disableUserTime();

			return \CRestUtil::ConvertDateTime($dateTime);
		}

		$key = date_default_timezone_get() . ' ' . $value;
		if (!array_key_exists($key, self::$cache))
		{
			if (count(self::$cache) >= self::CACHE_LIMIT)
			{
				self::$cache = [];
			}

			self::$cache[$key] = self::castMark($value);
		}

		return self::$cache[$key];
	}

	/**
	 * Reads a mark written in the culture format of the portal and returns it in ISO-8601 with the
	 * offset of the portal, or null when the mark cannot be parsed.
	 */
	private static function castMark(string $value): ?string
	{
		try
		{
			// no timezone is passed: the mark is written in the one of the portal process
			// the culture format is resolved once: the core pins the culture behind it on the first call anyway
			$dateTime = new Main\Type\DateTime($value, self::$cultureFormat ??= Main\Type\DateTime::getFormat());
		}
		catch (Main\ObjectException)
		{
			return null;
		}

		$dateTime->disableUserTime();

		return \CRestUtil::ConvertDateTime($dateTime);
	}
}
