<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Service\AiAssistant;

use Bitrix\Booking\Entity\DatePeriod;
use Bitrix\Booking\Internals\Exception\InvalidArgumentException;
use DateTimeImmutable;
use DateTimeZone;

class DateTimeService
{
	private const DATE_TIME_FORMAT = 'Y-m-d H:i';
	private const DATE_FORMAT = 'Y-m-d';

	public function getDateTimeFormat(): string
	{
		return self::DATE_TIME_FORMAT;
	}

	public function getDateFormat(): string
	{
		return self::DATE_FORMAT;
	}

	public function formatDateTime(DateTimeImmutable $dateTime): string
	{
		return $dateTime->format(self::DATE_TIME_FORMAT);
	}

	public function createDatePeriod($dateFrom, $dateTo): DatePeriod|null
	{
		try
		{
			return new DatePeriod($dateFrom, $dateTo);
		}
		catch (InvalidArgumentException $e)
		{
			return null;
		}
	}

	public function createDateTime(string $dateTime, string $timezone): DateTimeImmutable|null
	{
		if ($dateTime === '' || $timezone === '')
		{
			return null;
		}

		$dateTimeZone = $this->createTimezone($timezone);
		if (!$dateTimeZone)
		{
			return null;
		}

		$result = DateTimeImmutable::createFromFormat(
			self::DATE_TIME_FORMAT,
			$dateTime,
			$dateTimeZone
		);

		return $result ?: null;
	}

	public function createDate(string $date, string $timezone, $isEndOfDay = false): DateTimeImmutable|null
	{
		if ($date === '' || $timezone === '')
		{
			return null;
		}

		$dateTimeZone = $this->createTimezone($timezone);
		if (!$dateTimeZone)
		{
			return null;
		}

		$result = DateTimeImmutable::createFromFormat(self::DATE_FORMAT, $date, $dateTimeZone);
		if ($result)
		{
			if ($isEndOfDay)
			{
				$result = $result->setTime(23, 59, 59);
			}
			else
			{
				$result = $result->setTime(0, 0);
			}
		}

		return $result ?: null;
	}

	private function createTimezone(string $timezone): DateTimeZone|null
	{
		try
		{
			return new DateTimeZone($timezone);
		}
		catch (\Exception)
		{
			return null;
		}
	}
}
