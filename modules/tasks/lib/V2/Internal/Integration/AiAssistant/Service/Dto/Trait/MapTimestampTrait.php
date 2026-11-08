<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Dto\Trait;

trait MapTimestampTrait
{
	use MapDateTimeTrait;

	private static function mapTimestampWithTimeZone(
		array $props,
		string $dateTimeKey,
		string $userKey = 'userId',
	): ?int
	{
		$userId = static::mapInteger($props, $userKey);

		$dateTime = static::mapFormattedDateTime($props, $dateTimeKey, $userId);

		return $dateTime?->getTimestamp();
	}
}
