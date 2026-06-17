<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Service;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Helper class for time-related constants and utilities.
 */
class Time
{
	public const HOURS_IN_DAY = 24;
	public const MINUTES_IN_HOUR = 60;
	public const SECONDS_IN_MINUTE = 60;
	public const SECONDS_IN_HOUR = 3600;
	public const SECONDS_IN_DAY = 86400;
	public const MINUTES_IN_DAY = 1440;

	//@todo move to a more appropriate place, does not belong to this class
	public const CONSIDER_BOOKING__DELAYED_AFTER_SECONDS = 300;

	/**
	 * Checks if two timestamps fall on the same calendar day
	 * in the specified timezone.
	 */
	public static function isSameDay(int $timestamp1, int $timestamp2, string $timezone): bool
	{
		$dateTime1 = (new DateTimeImmutable("@{$timestamp1}"))
			->setTimezone(new DateTimeZone($timezone))
		;
		$dateTime2 = (new DateTimeImmutable("@{$timestamp2}"))
			->setTimezone(new DateTimeZone($timezone))
		;

		return $dateTime1->format('Ymd') === $dateTime2->format('Ymd');
	}

	public static function getDayCode(DateTimeInterface $dateTime): string
	{
		$map = [
			'SU',
			'MO',
			'TU',
			'WE',
			'TH',
			'FR',
			'SA',
			'SU',
		];

		return $map[(int)$dateTime->format('w')];
	}
}
