<?php


namespace Bitrix\Crm\Kanban;


use Bitrix\Main\Type\Date;
use Bitrix\Main\Type\DateTime;
use Bitrix\Crm\Kanban\Entity\Deadlines\DatePeriods;
use Bitrix\Crm\Kanban\Entity\EntityActivities;
use Bitrix\Crm\Settings\WorkTime;

class EntityActivityDeadline
{
	private DatetimeStages $datetimeStages;

	private DateTime $now;

	private DatePeriods $datePeriods;

	public function __construct(?DateTime $now = null)
	{
		$this->now = $now ?: new DateTime();
		$this->datetimeStages = new DatetimeStages(new WorkTime());
		$this->datePeriods = new DatePeriods($this->now);
	}

	public function setCurrentDeadline(DateTime $deadline): self
	{
		$this->datetimeStages->setCurrentDeadline($deadline);
		return $this;
	}

	public function getDeadline(string $statusTypeId): ?DateTime
	{
		if (strpos($statusTypeId, ':'))
		{
			[$prefix, $statusTypeId] = explode(':', $statusTypeId);
		}

		if (
			$statusTypeId === EntityActivities::STAGE_IDLE
			|| $statusTypeId === EntityActivities::STAGE_OVERDUE
		)
		{
			return null;
		}

		$dateTime = $this->getUserDateTime();

		if ($statusTypeId === EntityActivities::STAGE_PENDING)
		{
			$dateTime = $this->datetimeStages->current($dateTime);
		}
		elseif ($statusTypeId === EntityActivities::STAGE_THIS_WEEK)
		{
			$dateTime = $this->datetimeStages->currentFromThisWeek($dateTime);
		}
		elseif ($statusTypeId === EntityActivities::STAGE_NEXT_WEEK)
		{
			$dateTime = $this->datetimeStages->nextWeek($dateTime);
		}
		else
		{
			$dateTime = $this->datetimeStages->afterTwoWeek($dateTime);
		}

		$dateTime = $this->clampToStageWindow($statusTypeId, $dateTime);

		$deadline = \CCrmDateTimeHelper::getServerTime($dateTime);

		return $this->applyServerFloor($statusTypeId, $deadline);
	}

	/**
	 * Uses the independent server clock as an anchor, comparing calendar days
	 * in user time just like ActivityTrait::hasOverdueActivities().
	 */
	private function applyServerFloor(string $statusTypeId, DateTime $deadline): DateTime
	{
		$todayUser = Date::createFromTimestamp($this->getServerNow()->toUserTime()->getTimestamp());

		$deadline = clone $deadline;
		$userDeadline = (clone $deadline)->toUserTime();
		$deadlineDay = Date::createFromTimestamp($userDeadline->getTimestamp());
		if ($deadlineDay->getTimestamp() >= $todayUser->getTimestamp())
		{
			return $deadline;
		}

		$floorDay = $todayUser;
		if ($statusTypeId !== EntityActivities::STAGE_PENDING)
		{
			$floorDay->add('+1 day');
		}

		$this->setDate($userDeadline, $floorDay);

		return \CCrmDateTimeHelper::getServerTime($userDeadline);
	}

	/**
	 * Adjusts the calculated deadline date so it always falls into the target
	 * stage window and is never in the past. Time of day is preserved.
	 */
	private function clampToStageWindow(string $statusTypeId, DateTime $dateTime): DateTime
	{
		[$minDate, $maxDate] = $this->getStageWindow($statusTypeId);

		// Collapsed window (e.g. THIS_WEEK when today is the last day of the week):
		// the stage can only mean the single available day, so clamp to it.
		if ($minDate !== null && $maxDate !== null && $minDate->getTimestamp() > $maxDate->getTimestamp())
		{
			$minDate = $maxDate;
		}

		$dateTime = clone $dateTime;
		$currentDay = Date::createFromTimestamp($dateTime->getTimestamp());

		$lowerBound = $minDate;
		if (
			$lowerBound === null
			|| $this->datePeriods->today()->getTimestamp() > $lowerBound->getTimestamp()
		)
		{
			$lowerBound = $this->datePeriods->today();
		}

		if ($currentDay->getTimestamp() < $lowerBound->getTimestamp())
		{
			$this->setDate($dateTime, $lowerBound);
		}
		elseif ($maxDate !== null && $currentDay->getTimestamp() > $maxDate->getTimestamp())
		{
			$this->setDate($dateTime, $maxDate);
		}

		return $dateTime;
	}

	/**
	 * Returns the allowed date window [min, max] for the given stage.
	 * A null bound means the corresponding side is not limited.
	 *
	 * @return array{0: ?Date, 1: ?Date}
	 */
	private function getStageWindow(string $statusTypeId): array
	{
		if ($statusTypeId === EntityActivities::STAGE_PENDING)
		{
			return [$this->datePeriods->today(), $this->datePeriods->today()];
		}

		if ($statusTypeId === EntityActivities::STAGE_THIS_WEEK)
		{
			return [$this->datePeriods->tomorrow(), $this->datePeriods->currentWeekLastDay()];
		}

		if ($statusTypeId === EntityActivities::STAGE_NEXT_WEEK)
		{
			return [$this->datePeriods->nextWeekFirstDay(), $this->datePeriods->nextWeekLastDay()];
		}

		return [$this->datePeriods->afterNextWeek(), null];
	}

	private function setDate(DateTime $dateTime, Date $date): void
	{
		$dateTime->setDate(
			(int)$date->format('Y'),
			(int)$date->format('m'),
			(int)$date->format('d')
		);
	}

	protected function getUserDateTime(): DateTime
	{
		$userTimezoneDay = Datetime::createFromTimestamp($this->now->getTimestamp());
		$userTimezoneDay->toUserTime();

		return DateTime::createFromTimestamp($userTimezoneDay->getTimestamp());
	}

	/**
	 * Current server clock, independent of the injected calculation time.
	 */
	protected function getServerNow(): DateTime
	{
		return new DateTime();
	}
}
