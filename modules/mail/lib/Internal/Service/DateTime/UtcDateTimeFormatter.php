<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\DateTime;

use Bitrix\Main\Type\DateTime;

final class UtcDateTimeFormatter
{
	public static function format(DateTime $dateTime): string
	{
		$utcDateTime = clone $dateTime;
		$utcDateTime->setTimeZone(new \DateTimeZone('UTC'));

		return $utcDateTime->format(DATE_ATOM);
	}
}
