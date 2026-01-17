<?php

namespace Bitrix\Tasks\Integration\Calendar;

use Bitrix\Tasks\Integration\Calendar\ClosestWorkDateStrategy\StrategyFactory;
use Bitrix\Tasks\Integration\Calendar\Schedule\PortalSchedule;
use Bitrix\Tasks\Util\Type\DateTime;

class Calendar
{
	protected const MINUTES_TO_ROUND_UP = 5;

	protected ScheduleInterface $schedule;

	protected static ?self $instance = null;

	public static function createFromPortalSchedule(?array $settings = null): static
	{
		$schedule = new PortalSchedule($settings);

		return new static($schedule);
	}

	public function __construct(ScheduleInterface $schedule)
	{
		$this->schedule = $schedule;
	}

	public function getSchedule(): ScheduleInterface
	{
		return $this->schedule;
	}

	/**
	 * @return DateTime in user timezone
	 */
	public function getClosestDate(
		\Bitrix\Main\Type\DateTime $userDateTime,
		int $offsetInSeconds,
		bool $matchSchedule = false,
		bool $matchWorkTime = false,
		bool $roundDate = true,
	): DateTime
	{
		$userDateTime = DateTime::createFromDateTime($userDateTime);
		$userDateTime->stripSeconds();

		$possibleDate = clone $userDateTime;
		$possibleDate = $possibleDate->disableUserTime();

		if ($offsetInSeconds <= 0)
		{
			return $possibleDate;
		}

		$possibleDate->add($offsetInSeconds . ' seconds');

		if ($matchSchedule)
		{
			$matchWorkTime = true;
		}

		$closestWorkDateStrategy = StrategyFactory::getStrategy(
			$this->schedule,
			$matchSchedule,
			$matchWorkTime,
		);

		$closestWorkDate = $closestWorkDateStrategy->getClosestWorkDate($userDateTime, $offsetInSeconds);

		if ($roundDate)
		{
			return $this->roundDate($closestWorkDate);
		}

		return $closestWorkDate;
	}

	protected function roundDate(DateTime $date): DateTime
	{
		$divisionRemainder = $date->getMinute() % static::MINUTES_TO_ROUND_UP;
		if ($divisionRemainder === 0)
		{
			return $date;
		}
		$restOfMinutes = static::MINUTES_TO_ROUND_UP - $divisionRemainder;

		return $date->setTime($date->getHour(), $date->getMinute() + $restOfMinutes);
	}
}
