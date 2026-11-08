<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Provider\Mapper\Util;

use Bitrix\Main\Type\DateTime;

class DateFormatter
{
	public const FORMAT_WITH_TIMEZONE = 'c';
	public const FORMAT_WITHOUT_TIMEZONE = 'Y-m-d\TH:i:s';

	public static function formatTimestamp(
		?int $timestamp,
		string $format = self::FORMAT_WITH_TIMEZONE,
		?int $userId = null,
	): ?string
	{
		if ($timestamp === null || $timestamp <= 0)
		{
			return null;
		}

		return self::formatDateTime(DateTime::createFromTimestamp($timestamp), $format, $userId);
	}

	public static function formatDateTime(
		mixed $value,
		string $format = self::FORMAT_WITH_TIMEZONE,
		?int $userId = null,
	): ?string
	{
		if (!$value instanceof DateTime)
		{
			return null;
		}

		if ($userId !== null)
		{
			$value = clone $value;
			$value->setTimeZone(UserTimeZoneResolver::resolve($userId, $value));
		}

		return $value->format($format);
	}

	public static function isSupportedDateTime(string $value): bool
	{
		return (bool)preg_match(
			'/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(:\d{2})?(?:Z|[+\-]\d{2}:?\d{2})?$/',
			$value,
		);
	}

	public static function hasExplicitTimeZone(string $value): bool
	{
		return (bool)preg_match('/(?:Z|[+\-]\d{2}:?\d{2})$/', $value);
	}
}
