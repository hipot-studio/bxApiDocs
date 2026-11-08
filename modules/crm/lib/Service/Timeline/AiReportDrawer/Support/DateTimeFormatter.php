<?php

namespace Bitrix\Crm\Service\Timeline\AiReportDrawer\Support;

use Bitrix\Main\Application;
use Bitrix\Main\Type\DateTime;

final class DateTimeFormatter
{
	public static function format(mixed $value, int $currentUserId): ?string
	{
		$dateTime = self::normalizeDateTime($value);
		if ($dateTime === null)
		{
			return null;
		}

		$dateTime = $currentUserId > 0
			? \CCrmDateTimeHelper::getUserTime($dateTime, $currentUserId)
			: $dateTime->toUserTime()
		;

		$timestamp = $dateTime->getTimestamp();
		$culture = Application::getInstance()->getContext()->getCulture();
		$longDateFormat = $culture?->getLongDateFormat() ?? 'j F';
		$shortTimeFormat = $culture?->getShortTimeFormat() ?? 'H:i';

		return sprintf(
			'%s, %s',
			FormatDate($longDateFormat, $timestamp),
			FormatDate($shortTimeFormat, $timestamp),
		);
	}

	private static function normalizeDateTime(mixed $value): ?DateTime
	{
		if ($value instanceof DateTime)
		{
			return $value;
		}

		if (is_int($value) && $value > 0)
		{
			return DateTime::createFromTimestamp($value);
		}

		if (!is_string($value) || $value === '')
		{
			return null;
		}

		try
		{
			return new DateTime($value, DateTime::getFormat());
		}
		catch (\Throwable)
		{
			return null;
		}
	}
}
