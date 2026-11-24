<?php

namespace Bitrix\Mobile\Internal\Onboarding\Checkers;

use Bitrix\Main\Type\DateTime;
use Bitrix\Mobile\Internal\Onboarding\PushType;
use Bitrix\Mobile\Internal\Onboarding\Service\OnboardingPushService;
use Bitrix\Mobile\Internal\Service\Marketing\Time\PortalCalendar;

/**
 * Dates given to the checker are calendar dates of the series, and the stored moment of
 * the last push is brought into the same calendar before the days are compared: the day
 * that decides the dedup must be the day the schedule and the send window are counted in.
 *
 * The calendar is an argument of the query rather than a dependency of the checker, because
 * it belongs to the region scheme being evaluated - the portal one under a campaign
 * document, the server zone of the legacy day map (`ScheduleFactory::calendarForRegion()`) -
 * while the checker itself is built before the scheme of the pass is known.
 */
class LastPushSentChecker
{
	private OnboardingPushService $pushStorage;

	public function __construct(?OnboardingPushService $pushStorage = null)
	{
		$this->pushStorage = $pushStorage ?? new OnboardingPushService();
	}

	/**
	 * Whether any push of the series has already been sent on the given calendar
	 * day. Holds the "at most one series push per day" invariant of the activation
	 * series, where the resolved type may change between two runs of the same day
	 * once the user completes a step.
	 */
	public function isSentOnDate(int $userId, DateTime $date, ?PortalCalendar $calendar = null): bool
	{
		$sentDay = $this->getSentDay($userId, $calendar);

		return $sentDay !== null && $sentDay === $date->format('Y-m-d');
	}

	public function isAlreadySent(
		int $userId,
		PushType $pushType,
		DateTime $scheduledDate,
		?PortalCalendar $calendar = null,
	): bool
	{
		$lastPush = $this->pushStorage->getLastSentPush($userId);
		if ($lastPush === null || $lastPush->type !== $pushType->value)
		{
			return false;
		}

		return $this->toCalendarDay($lastPush->timestamp, $calendar) >= $scheduledDate->format('Y-m-d');
	}

	private function getSentDay(int $userId, ?PortalCalendar $calendar): ?string
	{
		$lastPush = $this->pushStorage->getLastSentPush($userId);

		return $lastPush === null ? null : $this->toCalendarDay($lastPush->timestamp, $calendar);
	}

	private function toCalendarDay(int $timestamp, ?PortalCalendar $calendar): string
	{
		$moment = DateTime::createFromTimestamp($timestamp);

		return $calendar === null ? $moment->format('Y-m-d') : $calendar->day($moment);
	}
}
