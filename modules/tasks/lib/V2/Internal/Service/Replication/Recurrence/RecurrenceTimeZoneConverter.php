<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Replication\Recurrence;

use Bitrix\Main\Type\DateTime;
use CTimeZone;
use Throwable;

class RecurrenceTimeZoneConverter
{
	private const SECONDS_PER_MINUTE = 60;
	private const MINUTES_PER_HOUR = 60;
	private const HOURS_PER_DAY = 24;
	private const SECONDS_PER_HOUR = self::SECONDS_PER_MINUTE * self::MINUTES_PER_HOUR;
	private const SECONDS_PER_DAY = self::SECONDS_PER_HOUR * self::HOURS_PER_DAY;
	private const TIME_PATTERN = '/^(?<hours>[01]\d|2[0-3]):(?<minutes>[0-5]\d)(?::(?<seconds>[0-5]\d))?$/D';

	public function convertUserTimeToServer(
		?string $time,
		int $userId,
		?DateTime $referenceDate = null,
	): ?string
	{
		$referenceDate = $this->normalizeReferenceDate($referenceDate);

		return $this->shiftTime($time, -$this->getOffset($userId, $referenceDate));
	}

	public function convertServerTimeToUser(
		?string $time,
		int $userId,
		?string $referenceDate = null,
	): ?string
	{
		return $this->convertStoredTimeToUser($time, $userId, 0, $referenceDate);
	}

	public function convertServerDateTimeToUser(?string $dateTime, int $userId): ?string
	{
		if ($dateTime === null || $dateTime === '')
		{
			return $dateTime;
		}

		$value = DateTime::tryParse($dateTime);
		if ($value === null)
		{
			return $dateTime;
		}

		$offset = $this->getOffset($userId, $value);
		if ($offset !== 0)
		{
			$value->add(($offset < 0 ? '-' : '') . 'PT' . abs($offset) . 'S');
		}

		return $value->disableUserTime()->toString();
	}

	public function convertStoredTimeToUser(
		?string $time,
		int $userId,
		int $sourceTimeZoneOffset,
		?string $referenceDate = null,
	): ?string
	{
		$referenceDate = $this->parseReferenceDate($referenceDate);

		return $this->shiftTime(
			$time,
			-$sourceTimeZoneOffset + $this->getOffset($userId, $referenceDate),
		);
	}

	protected function getOffset(int $userId, DateTime $referenceDate): int
	{
		return CTimeZone::GetOffset($userId, date: $referenceDate);
	}

	private function normalizeReferenceDate(?DateTime $referenceDate): DateTime
	{
		return DateTime::createFromTimestamp(($referenceDate ?? new DateTime())->getTimestamp());
	}

	private function parseReferenceDate(?string $referenceDate): DateTime
	{
		if ($referenceDate === null || $referenceDate === '')
		{
			return new DateTime();
		}

		$formats = [
			'Y-m-d H:i:s',
			'd.m.Y H:i:s',
			'Y-m-d',
			'd.m.Y',
		];
		foreach ($formats as $format)
		{
			try
			{
				return new DateTime($referenceDate, $format);
			}
			catch (Throwable)
			{
				continue;
			}
		}

		return new DateTime();
	}

	private function shiftTime(?string $time, int $offset): ?string
	{
		if ($time === null || preg_match(self::TIME_PATTERN, $time, $matches) !== 1)
		{
			return $time;
		}

		$seconds =
			(int)$matches['hours'] * self::SECONDS_PER_HOUR
			+ (int)$matches['minutes'] * self::SECONDS_PER_MINUTE
			+ (int)($matches['seconds'] ?? 0)
			+ $offset
		;
		$seconds = ($seconds % self::SECONDS_PER_DAY + self::SECONDS_PER_DAY) % self::SECONDS_PER_DAY;

		$hours = intdiv($seconds, self::SECONDS_PER_HOUR);
		$minutes = intdiv($seconds % self::SECONDS_PER_HOUR, self::SECONDS_PER_MINUTE);
		$seconds = $seconds % self::SECONDS_PER_MINUTE;

		return ($matches['seconds'] ?? '') === ''
			? sprintf('%02d:%02d', $hours, $minutes)
			: sprintf('%02d:%02d:%02d', $hours, $minutes, $seconds)
		;
	}
}
