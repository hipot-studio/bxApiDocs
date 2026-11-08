<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Provider\Mapper\Util;

use Bitrix\Main\Type\DateTime;
use CTimeZone;
use DateTimeZone;

class UserTimeZoneResolver
{
	public static function resolve(int $userId, DateTime $dateTime): DateTimeZone
	{
		$userOffsetFromUtc = CTimeZone::getOffset($userId, date: $dateTime) + $dateTime->getOffset();

		return new DateTimeZone(DateTime::secondsToOffset($userOffsetFromUtc, ':'));
	}
}
