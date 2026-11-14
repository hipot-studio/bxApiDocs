<?php

namespace Bitrix\ImOpenLines\Integrations\Report\Statistics;

use Bitrix\Main;
use Bitrix\Main\Type\Date;
use Bitrix\Main\Type\DateTime;

final class QueuePayload
{
	public const DATE_FORMAT = 'Y-m-d H:i:s';

	private const DATE_KEY = 'DATE';
	private const LEGACY_CLASS_NAMES = [
		'Bitrix\Main\Type\DateTime',
		'Bitrix\Main\Type\Date',
	];
	private const LEGACY_VALUE_PROPERTY = "\0*\0value";
	private const INCOMPLETE_CLASS_NAME_KEY = '__PHP_Incomplete_Class_Name';

	public static function encode(array $params): array
	{
		if (isset($params[self::DATE_KEY]) && $params[self::DATE_KEY] instanceof Date)
		{
			$params[self::DATE_KEY] = $params[self::DATE_KEY]->format(self::DATE_FORMAT);
		}

		return $params;
	}

	/**
	 * @throws Main\ArgumentException
	 */
	public static function decode(array $params): array
	{
		$params[self::DATE_KEY] = self::decodeDate($params[self::DATE_KEY] ?? null);

		return $params;
	}

	/**
	 * @throws Main\ArgumentException
	 */
	private static function decodeDate(mixed $value): DateTime
	{
		if (is_string($value))
		{
			return self::parseString($value);
		}

		if ($value instanceof DateTime)
		{
			return $value;
		}

		if ($value instanceof Date)
		{
			return self::createDateTime($value->format(self::DATE_FORMAT), $value->format('e'));
		}

		if ($value instanceof \__PHP_Incomplete_Class)
		{
			return self::restoreLegacy($value);
		}

		throw self::createException('Queue item date is missing or has an unsupported type');
	}

	/**
	 * @throws Main\ArgumentException
	 */
	private static function parseString(string $value): DateTime
	{
		// Main\Type\DateTime treats an empty string as "now"
		if (trim($value) === '')
		{
			throw self::createException('Queue item date is empty');
		}

		try
		{
			return new DateTime($value, self::DATE_FORMAT);
		}
		catch (Main\ObjectException $exception)
		{
			throw self::createException('Queue item date cannot be parsed', $exception);
		}
	}

	/**
	 * Legacy rows hold a serialized Main\Type\Date that the ORM restores as an incomplete class,
	 * so only the scalar fields of the inner \DateTime are read, no object is revived.
	 *
	 * @throws Main\ArgumentException
	 */
	private static function restoreLegacy(\__PHP_Incomplete_Class $value): DateTime
	{
		$properties = (array)$value;
		$className = $properties[self::INCOMPLETE_CLASS_NAME_KEY] ?? null;
		if (!in_array($className, self::LEGACY_CLASS_NAMES, true))
		{
			throw self::createException('Queue item date has an unsupported legacy class');
		}

		$inner = $properties[self::LEGACY_VALUE_PROPERTY] ?? null;
		if (!$inner instanceof \__PHP_Incomplete_Class)
		{
			throw self::createException('Queue item legacy date has no value');
		}

		$innerProperties = (array)$inner;
		$date = $innerProperties['date'] ?? null;
		$timezone = $innerProperties['timezone'] ?? null;
		if (
			($innerProperties[self::INCOMPLETE_CLASS_NAME_KEY] ?? null) !== 'DateTime'
			|| !is_string($date)
			|| !is_string($timezone)
		)
		{
			throw self::createException('Queue item legacy date has an unsupported structure');
		}

		return self::createDateTime($date, $timezone);
	}

	/**
	 * @throws Main\ArgumentException
	 */
	private static function createDateTime(string $date, string $timezone): DateTime
	{
		try
		{
			return DateTime::createFromPhp(new \DateTime($date, new \DateTimeZone($timezone)));
		}
		catch (\Exception $exception)
		{
			throw self::createException('Queue item date cannot be restored', $exception);
		}
	}

	private static function createException(string $message, ?\Throwable $previous = null): Main\ArgumentException
	{
		return new Main\ArgumentException($message, self::DATE_KEY, $previous);
	}
}
