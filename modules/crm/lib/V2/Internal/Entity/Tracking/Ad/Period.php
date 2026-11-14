<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Entity\Tracking\Ad;

final readonly class Period
{
	private function __construct(
		public string $dateFrom,
		public string $dateTo,
		public \DateTimeImmutable $start,
		public \DateTimeImmutable $endExclusive,
		public int $totalDays,
	)
	{
	}

	public static function fromStrings(
		string $from,
		string $to,
		\DateTimeZone $portalTimezone,
		\DateTimeImmutable $today,
	): self
	{
		$start = self::parseDate($from, $portalTimezone);
		$end = self::parseDate($to, $portalTimezone);
		$days = (int)$start->diff($end)->format('%r%a') + 1;
		if ($days < 1 || $days > 365 || $to > $today->setTimezone($portalTimezone)->format('Y-m-d'))
		{
			throw Refusal::PeriodInvalid->exception();
		}

		return new self($from, $to, $start, $end->modify('+1 day'), $days);
	}

	private static function parseDate(string $value, \DateTimeZone $timezone): \DateTimeImmutable
	{
		if (preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $value) !== 1)
		{
			throw Refusal::PeriodInvalid->exception();
		}
		$date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, $timezone);
		if ($date === false || $date->format('Y-m-d') !== $value || $date->format('H:i:s') !== '00:00:00'
			|| (int)$date->format('Y') === 0)
		{
			throw Refusal::PeriodInvalid->exception();
		}

		return $date;
	}
}
