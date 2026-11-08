<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Dto\Trait;

use Bitrix\Main\Type\DateTime;
use Bitrix\Tasks\V2\Internal\Entity\Trait\MapTypeTrait;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Provider\Mapper\Util\DateFormatter;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Provider\Mapper\Util\UserTimeZoneResolver;
use DateTimeImmutable;
use Throwable;

trait MapDateTimeTrait
{
	use MapTypeTrait;

	private static function mapFormattedDateTime(array $props, string $key, ?int $userId = null): ?DateTime
	{
		$formattedDate = static::mapString($props, $key);
		if ($formattedDate === null)
		{
			return null;
		}

		$formattedDate = trim($formattedDate);
		if ($formattedDate === '' || !DateFormatter::isSupportedDateTime($formattedDate))
		{
			return null;
		}

		try
		{
			$parsedDate = new DateTimeImmutable($formattedDate);

			if ($userId !== null && !DateFormatter::hasExplicitTimeZone($formattedDate))
			{
				$userTimeZone = UserTimeZoneResolver::resolve(
					$userId,
					DateTime::createFromTimestamp($parsedDate->getTimestamp()),
				);

				$parsedDate = new DateTimeImmutable($formattedDate, $userTimeZone);
			}
		}
		catch (Throwable)
		{
			return null;
		}

		$dateTime = DateTime::createFromTimestamp($parsedDate->getTimestamp());

		if ($userId !== null)
		{
			$dateTime->setTimeZone(UserTimeZoneResolver::resolve($userId, $dateTime));
		}

		return $dateTime;
	}
}
