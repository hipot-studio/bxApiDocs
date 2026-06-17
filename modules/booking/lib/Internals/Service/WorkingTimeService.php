<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Service;

use DateTimeImmutable;
use DateTimeZone;

class WorkingTimeService
{
	private const START_HOUR = 8;
	private const END_HOUR = 21;

	/**
	 * Checks if the given DateTimeImmutable falls within working hours.
	 */
	public function isWithinWorkingHours(DateTimeImmutable $dateTime): bool
	{
		$hour = (int)$dateTime->format('H');

		return $hour >= self::START_HOUR && $hour < self::END_HOUR;
	}

	/**
	 * Checks if the given unix timestamp falls within working hours
	 * in the specified timezone.
	 */
	public function isWithinWorkingHoursAt(int $timestamp, string $timezone): bool
	{
		$dateTime = (new DateTimeImmutable("@{$timestamp}"))
			->setTimezone(new DateTimeZone($timezone))
		;

		return $this->isWithinWorkingHours($dateTime);
	}

	/**
	 * Returns the hour at which the working day starts.
	 */
	public function getStartHour(): int
	{
		return self::START_HOUR;
	}

	/**
	 * Returns the hour at which the working day ends.
	 */
	public function getEndHour(): int
	{
		return self::END_HOUR;
	}

	/**
	 * Adjusts the given datetime to fall within working hours.
	 * If already within working hours, returns as is.
	 * If before working hours, shifts to start of the same day.
	 * If after working hours, shifts to start of the next day.
	 */
	public function adjustToWorkingHours(DateTimeImmutable $dateTime): DateTimeImmutable
	{
		if ($this->isWithinWorkingHours($dateTime))
		{
			return $dateTime;
		}

		if ((int)$dateTime->format('H') < self::START_HOUR)
		{
			return $dateTime->setTime(self::START_HOUR, 0);
		}

		return $dateTime
			->modify('+1 day')
			->setTime(self::START_HOUR, 0)
		;
	}

	/**
	 * Returns the duration of the overnight non-working period in seconds.
	 * Calculated as: 24h - (END_HOUR - START_HOUR - 1) converted to seconds.
	 */
	public function getOvernightGapInSeconds(): int
	{
		return (
			Time::HOURS_IN_DAY - (self::END_HOUR - self::START_HOUR - 1)
		) * Time::SECONDS_IN_HOUR;
	}
}
