<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Provider\Mapper\Util;

use Bitrix\Main\Type\DateTime;

class DateFormatter
{
	private const ISO_8601_FORMAT = 'c';

	public static function formatTimestamp(?int $timestamp): ?string
	{
		if ($timestamp === null || $timestamp <= 0)
		{
			return null;
		}

		return DateTime::createFromTimestamp($timestamp)->format(self::ISO_8601_FORMAT);
	}

	public static function formatDateTime(mixed $value): ?string
	{
		if (!$value instanceof DateTime)
		{
			return null;
		}

		return $value->format(self::ISO_8601_FORMAT);
	}
}
