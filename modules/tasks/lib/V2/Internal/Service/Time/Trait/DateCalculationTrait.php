<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Time\Trait;

use Bitrix\Tasks\Integration\Calendar\Calendar;
use Bitrix\Tasks\Util\Type\DateTime;
use Bitrix\Tasks\Util\User;

trait DateCalculationTrait
{
	private function calculateClosestDate(
		int $offsetInSeconds,
		bool $matchesWorkTime,
		int $userId,
		bool $roundDate = true,
	): ?DateTime
	{
		if (!$offsetInSeconds)
		{
			return null;
		}

		$calendar = Calendar::createFromPortalSchedule();

		$userTimeOffset = User::getTimeZoneOffset($userId, false, true);
		$userDateTime = (new DateTime())->add("{$userTimeOffset} seconds");

		$userTimeClosestDate = $calendar->getClosestDate(
			userDateTime: $userDateTime,
			offsetInSeconds: $offsetInSeconds,
			matchSchedule: $matchesWorkTime,
			roundDate: $roundDate,
		);

		$toServerTimeOffset = -$userTimeOffset;

		return $userTimeClosestDate->add("{$toServerTimeOffset} seconds");
	}

	/**
	 * Deadline for a zero offset ("on the creation day"): the end of the user's shift today,
	 * so the task is due this evening instead of the very moment it was created.
	 */
	private function calculateCreationDayDeadline(int $userId): DateTime
	{
		$calendar = Calendar::createFromPortalSchedule();

		$userTimeOffset = User::getTimeZoneOffset($userId, false, true);
		$userDateTime = (new DateTime())->add("{$userTimeOffset} seconds");

		$userTimeShiftEnd = $calendar->getSchedule()->getShiftEnd($userDateTime);

		$toServerTimeOffset = -$userTimeOffset;

		return $userTimeShiftEnd->add("{$toServerTimeOffset} seconds");
	}
}
