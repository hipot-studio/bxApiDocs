<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Dto\Trait;

use Bitrix\Tasks\Util\User;

trait MapTimestampTrait
{
	use MapDateTimeTrait;

	private static function mapTimestampWithTimeZone(
		array $props,
		string $dateTimeKey,
		string $userKey = 'userId',
	): ?int
	{
		$dateTime = static::mapFormattedDateTime($props, $dateTimeKey);

		$timestamp = $dateTime?->getTimestamp();

		if ($timestamp === null)
		{
			return null;
		}

		$userId = static::mapInteger($props, $userKey);

		return $userId ? $timestamp - User::getTimeZoneOffset($userId) : $timestamp;
	}
}
