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
	 * Independent safety net anchored on the raw, unconverted server clock.
	 *
	 * Both the calculated deadline and the DatePeriods stage windows go through
	 * the same toUserTime()->getServerTime() round-trip, so a broken portal
	 * timezone/work-time sync shifts them together and clampToStageWindow() alone
	 * cannot detect a deadline that ended up in the past. The raw server clock
	 * does not pass through that round-trip, so it stays a stable anchor.
	 *
	 * The floor triggers only when the deadline server-day is actually earlier
	 * than the real today, i.e. exactly the overdue definition used by kanban
	 * classification in ActivityTrait::hasOverdueActivities(). In that case the
	 * date is lifted to today (PENDING) or tomorrow (future stages). Deadlines
	 * that are already today or later are left untouched, so the legitimate
	 * "today" result for the last day of the week is preserved.
	 *
	 * For portals where the user timezone differs a lot from the server one an
	 * extra correction could still be desirable, but that is out of the ticket's
	 * scope; the reported case has user timezone close to the server one.
	 */
	private function applyServerFloor(string $statusTypeId, DateTime $deadline): DateTime
	{
		$todayServer = Date::createFromTimestamp($this->getServerNow()->getTimestamp());

		$deadline = clone $deadline;
		$deadlineDay = Date::createFromTimestamp($deadline->getTimestamp());
		if ($deadlineDay->getTimestamp() >= $todayServer->getTimestamp())
		{
			return $deadline;
		}

		$floorDay = $todayServer;
		if ($statusTypeId !== EntityActivities::STAGE_PENDING)
		{
			$floorDay->add('+1 day');
		}

		$this->setDate($deadline, $floorDay);

		return $deadline;
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
	 * Raw server clock used solely as the floor anchor. Unlike getUserDateTime()
	 * it does not go through the toUserTime()/getServerTime() conversion, so it is
	 * not affected by a broken timezone sync.
	 */
	protected function getServerNow(): DateTime
	{
		return new DateTime();
	}
}
