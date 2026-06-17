<?php

declare(strict_types=1);

namespace Bitrix\Booking\Entity;

use Bitrix\Booking\Internals\Container;
use Bitrix\Booking\Internals\Exception\InvalidArgumentException;
use Bitrix\Booking\Internals\Service\Rrule;
use Bitrix\Booking\Internals\Service\Time;
use DateTimeImmutable;
use DateInterval;
use DateTimeZone;

class DatePeriod implements EventInterface
{
	use EventTrait;

	private DateTimeImmutable $dateFrom;
	private DateTimeImmutable $dateTo;

	/**
	 * @param DateTimeImmutable $dateFrom
	 * @param DateTimeImmutable $dateTo
	 * @throws InvalidArgumentException
	 */
	public function __construct(DateTimeImmutable $dateFrom, DateTimeImmutable $dateTo)
	{
		if ($dateTo <= $dateFrom)
		{
			throw new InvalidArgumentException('DateTo must be greater than DateFrom');
		}

		if (!Container::getTimezoneService()->isValid($dateFrom->getTimezone()->getName()))
		{
			$dateFrom = $dateFrom->setTimezone(new DateTimeZone('UTC'));
		}

		if (!Container::getTimezoneService()->isValid($dateTo->getTimezone()->getName()))
		{
			$dateTo = $dateTo->setTimezone(new DateTimeZone('UTC'));
		}

		$this->dateFrom = $dateFrom;
		$this->dateTo = $dateTo;
	}

	public function setTimezone(string $timezone): self
	{
		return new self(
			$this->dateFrom->setTimezone(new DateTimeZone($timezone)),
			$this->dateTo->setTimezone(new DateTimeZone($timezone)),
		);
	}

	public function contains(self $datePeriod): bool
	{
		return
			$datePeriod->getDateFrom()->getTimestamp() >= $this->getDateFrom()->getTimestamp()
			&& $datePeriod->getDateFrom()->getTimestamp() < $this->getDateTo()->getTimestamp()
			&& $datePeriod->getDateTo()->getTimestamp() > $this->getDateFrom()->getTimestamp()
			&& $datePeriod->getDateTo()->getTimestamp() <= $this->getDateTo()->getTimestamp();
	}

	public function intersects(self $datePeriod): bool
	{
		return
			(
				$this->getDateFrom()->getTimestamp() >= $datePeriod->getDateFrom()->getTimestamp()
				&& $this->getDateFrom()->getTimestamp() < $datePeriod->getDateTo()->getTimestamp()
			)
			|| (
				$datePeriod->getDateFrom()->getTimestamp() >= $this->getDateFrom()->getTimestamp()
				&& $datePeriod->getDateFrom()->getTimestamp() < $this->getDateTo()->getTimestamp()
			);
	}

	public function exclude(self $datePeriod): DatePeriodCollection
	{
		$datePeriodFromTs = $this->getDateFrom()->getTimestamp();
		$datePeriodToTs = $this->getDateTo()->getTimestamp();
		$excludedDatePeriodFromTs = $datePeriod->getDateFrom()->getTimestamp();
		$excludedDatePeriodToTs = $datePeriod->getDateTo()->getTimestamp();

		if ($datePeriodToTs <= $excludedDatePeriodFromTs || $datePeriodFromTs >= $excludedDatePeriodToTs)
		{
			return new DatePeriodCollection($this);
		}

		$result = new DatePeriodCollection();

		if ($datePeriodFromTs < $excludedDatePeriodFromTs)
		{
			$result->add(self::createDatePeriodFromTimestamps($datePeriodFromTs, $excludedDatePeriodFromTs));
		}

		if ($datePeriodToTs > $excludedDatePeriodToTs)
		{
			$result->add(self::createDatePeriodFromTimestamps($excludedDatePeriodToTs, $datePeriodToTs));
		}

		return $result;
	}

	public function addMinutes(int $minutes): self
	{
		return new self(
			$this->getDateFrom()->add(new DateInterval('PT' . $minutes . 'M')),
			$this->getDateTo()->add(new DateInterval('PT' . $minutes . 'M')),
		);
	}

	public function getDateFrom(): DateTimeImmutable
	{
		return $this->dateFrom;
	}

	public function getDateTo(): DateTimeImmutable
	{
		return $this->dateTo;
	}

	public function isOverMidnight(): bool
	{
		return (int)$this->dateFrom->format('j') !== (int)$this->dateTo->format('j');
	}

	public function getDateTimeCollection(): DateTimeCollection
	{
		$result = new DateTimeCollection();

		$dates = iterator_to_array(
			new \DatePeriod(
				$this->getDateFrom(),
				new DateInterval('P1D'),
				$this->getDateTo(),
			),
		);
		foreach ($dates as $date)
		{
			$result->add(DateTimeImmutable::createFromInterface($date));
		}

		return $result;
	}

	public function isGreaterThanDay(): bool
	{
		return $this->dateTo->getTimestamp() - $this->dateFrom->getTimestamp() > Time::SECONDS_IN_DAY;
	}

	public function diffMinutes(): int
	{
		$diffInSeconds = $this->dateTo->getTimestamp() - $this->dateFrom->getTimestamp();

		if ($diffInSeconds < Time::SECONDS_IN_MINUTE)
		{
			return 1;
		}

		return (int)($diffInSeconds / Time::SECONDS_IN_MINUTE);
	}

	public function toArray(): array
	{
		return [
			'dateFrom' => $this->dateFrom->getTimestamp(),
			'dateTo' => $this->dateTo->getTimestamp(),
		];
	}

	public function isEventRecurring(): bool
	{
		return false;
	}

	public function getEventDatePeriod(): self
	{
		return $this;
	}

	public function getEventRrule(): ?Rrule
	{
		return null;
	}

	private static function createDatePeriodFromTimestamps(int $dateFromTs, int $dateToTs): self
	{
		return new self(
			new DateTimeImmutable('@' . $dateFromTs),
			new DateTimeImmutable('@' . $dateToTs),
		);
	}
}
