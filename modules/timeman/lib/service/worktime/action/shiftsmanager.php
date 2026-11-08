<?php
namespace Bitrix\Timeman\Service\Worktime\Action;

use Bitrix\Main\Type\Date;
use Bitrix\Timeman\Helper\TimeHelper;
use Bitrix\Timeman\Model\Schedule\Schedule;
use Bitrix\Timeman\Model\Schedule\ScheduleCollection;
use Bitrix\Timeman\Model\Schedule\Shift\Shift;
use Bitrix\Timeman\Provider\Schedule\ShiftPlanProvider;

class ShiftsManager
{
	/** @var ScheduleCollection */
	private $activeSchedules;
	/** @var ShiftPlanProvider */
	private $shiftPlanProvider;
	private $userId;

	public function __construct($userId, ScheduleCollection $activeSchedules, ShiftPlanProvider $shiftPlanProvider)
	{
		$this->userId = $userId;
		$this->activeSchedules = $activeSchedules;
		$this->shiftPlanProvider = $shiftPlanProvider;
	}

	/**
	 * Whether the schedule still belongs to the user's active schedules.
	 */
	public function containsSchedule(?int $scheduleId): bool
	{
		// getByPrimary() casts an empty primary to 0, so without this guard a schedule stored under id 0
		// would count as a match.
		if ($scheduleId === null || $scheduleId <= 0)
		{
			return false;
		}

		return $this->activeSchedules->getByPrimary($scheduleId) !== null;
	}

	/**
	 * Whether the user's active schedules hold a flextime one. Such a set resolves no shift at all, so the
	 * decisions relying on the next shift have nothing to move their threshold.
	 */
	public function hasFlextimeSchedule(): bool
	{
		return $this->activeSchedules->hasFlextime();
	}

	private function buildShiftWithDate(\DateTime $userDateTime, $checkRelevant, ?ShiftWithDate $previousShitWithDate = null): ?ShiftWithDate
	{
		if ($this->activeSchedules->count() === 0 || $this->activeSchedules->hasFlextime() ||
			$this->activeSchedules->obtainActiveShifts()->count() === 0)
		{
			return null;
		}
		/** @var ShiftWithDate[] $nextShiftsWithDates */
		$nextShiftsWithDates = [];
		foreach ($this->activeSchedules->getAll() as $schedule)
		{
			$daysToCheck = $checkRelevant ? $this->getSearchDaysForRelevantShift($schedule) : $this->getSearchDaysForNextShift($schedule);
			$nextShiftsWithDates = array_merge($nextShiftsWithDates, $this->calculateShiftsWithDate($schedule, $userDateTime, $checkRelevant, $daysToCheck, $previousShitWithDate));
		}
		$nextShiftsWithDates = array_filter($nextShiftsWithDates);

		if (count($nextShiftsWithDates) <= 1)
		{
			return empty($nextShiftsWithDates) ? null : reset($nextShiftsWithDates);
		}

		$shiftedShifts = [];
		$fixedShifts = [];
		foreach ($nextShiftsWithDates as $shiftWithDate)
		{
			if ($shiftWithDate->getSchedule()->isShifted())
			{
				$shiftedShifts[] = $shiftWithDate;
			}
			else
			{
				$fixedShifts[] = $shiftWithDate;
			}
		}
		$shiftedShifts = $this->sortedByStartAndShiftId($shiftedShifts);
		$fixedShifts = $this->sortedByStartAndShiftId($fixedShifts);

		if ($checkRelevant)
		{
			foreach ($shiftedShifts as $shiftedShift)
			{
				if ($this->hasShiftPlan($shiftedShift))
				{
					return $shiftedShift;
				}
			}
		}

		// A fixed shift losing to the shifted ones only when those have several distinct starts has no domain
		// reason behind it - it is the legacy arbitration, kept deliberately and locked by
		// ShiftsManagerShiftPlanTest::testWithoutAnAssignmentTheFixedShiftOfTheMixedSetKeepsWinning().
		if ($this->hasSeveralStartTimes($shiftedShifts))
		{
			return $shiftedShifts[0];
		}
		if (!empty($fixedShifts))
		{
			return $fixedShifts[0];
		}

		return $shiftedShifts[0] ?? null;
	}

	/**
	 * Orders the candidates by the start instant, an equal start decided by the smaller shift id. The pair is
	 * unique among the candidates, so the order does not depend on the traversal of schedules and shifts.
	 *
	 * @param ShiftWithDate[] $shiftsWithDates
	 * @return ShiftWithDate[]
	 */
	private function sortedByStartAndShiftId(array $shiftsWithDates): array
	{
		usort(
			$shiftsWithDates,
			function (ShiftWithDate $first, ShiftWithDate $second) {
				return [$first->getDateTimeStart()->getTimestamp(), (int)$first->getShift()->getId()]
					<=> [$second->getDateTimeStart()->getTimestamp(), (int)$second->getShift()->getId()];
			}
		);

		return $shiftsWithDates;
	}

	/**
	 * @param ShiftWithDate[] $shiftsWithDates
	 */
	private function hasSeveralStartTimes(array $shiftsWithDates): bool
	{
		$starts = [];
		foreach ($shiftsWithDates as $shiftWithDate)
		{
			$starts[$shiftWithDate->getDateTimeStart()->getTimestamp()] = true;
		}

		return count($starts) > 1;
	}

	public function buildRelevantShiftWithDate(\DateTime $userDateTime, ?ShiftWithDate $previousShitWithDate = null): ?ShiftWithDate
	{
		return $this->buildShiftWithDate($userDateTime, true, $previousShitWithDate);
	}

	public function buildNextShiftWithDate(\DateTime $userDateTime, ?ShiftWithDate $previousShitWithDate = null): ?ShiftWithDate
	{
		return $this->buildShiftWithDate($userDateTime, false, $previousShitWithDate);
	}

	/**
	 * DATE_ASSIGNED of a shift plan is the local calendar date of the shift start, so the lookup takes the
	 * date dateTimeStart carries in the zone its caller supplied - which has to be the employee's. Only a
	 * real IANA zone matches Shift::buildUtcStartByShiftplan() exactly; a synthetic '+HH:MM' offset drifts
	 * from it inside the DST window around local midnight. For an overnight shift it is the date of the
	 * start: buildUtcEndByUserId() carries the end over itself.
	 */
	private function hasShiftPlan(ShiftWithDate $shift)
	{
		return (bool)$this->shiftPlanProvider->findActiveByComplexId(
			$shift->getShift()->getId(),
			$this->userId,
			new Date($shift->getDateTimeStart()->format('Y-m-d'), 'Y-m-d')
		);
	}

	private function calculateShiftsWithDate(Schedule $schedule, \DateTime $userDateTime, $checkRelevant = true, $daysToCheck = 0, ?ShiftWithDate $previousShiftWithDate = null): array
	{
		$possibleShifts = [];

		$periodIterator = $this->buildDatesIterator($userDateTime, $daysToCheck);

		foreach ($periodIterator as $date)
		{
			foreach ($this->getShiftsByDate($schedule, $date) as $shift)
			{
				$shift = new ShiftWithDate($shift, $schedule, $date, (int)$this->userId);
				if ($previousShiftWithDate)
				{
					if ($previousShiftWithDate->isEqualsTo($shift) ||
						$shift->getDateTimeStart()->getTimestamp() < $previousShiftWithDate->getDateTimeStart()->getTimestamp())
					{
						continue;
					}
				}
				if ($shift->endedByTime($userDateTime) || ($checkRelevant && !$shift->isEligibleToStart($userDateTime)))
				{
					continue;
				}

				$possibleShifts[] = $shift;
			}
		}
		return $possibleShifts;
	}

	public function getScheduleToStart($userDateTime)
	{
		if ($this->activeSchedules->hasFlextime())
		{
			foreach ($this->activeSchedules->getAll() as $schedule)
			{
				if ($schedule->isFlextime())
				{
					return $schedule;
				}
			}
		}
		if ($shiftDate = $this->buildRelevantShiftWithDate($userDateTime))
		{
			return $shiftDate->getSchedule();
		}
		if ($shiftDate = $this->buildNextShiftWithDate($userDateTime))
		{
			return $shiftDate->getSchedule();
		}
		return $this->activeSchedules->count() === 0 ? null : reset($this->activeSchedules->getAll());
	}

	private function getShiftsByDate(Schedule $schedule, \DateTime $date): array
	{
		if ($schedule->isShifted())
		{
			return $schedule->obtainActiveShifts();
		}
		if ($schedule->isFixed())
		{
			$weekDay = TimeHelper::getInstance()->getDayOfWeek($date);
			return array_filter([$schedule->getShiftByWeekDay($weekDay)]);
		}
		return [];
	}

	private function getSearchDaysForNextShift(Schedule $schedule): int
	{
		if ($schedule->isShifted())
		{
			return 2;
		}
		if ($schedule->isFixed())
		{
			return 8;
		}
		return 0;
	}

	private function getSearchDaysForRelevantShift(Schedule $schedule)
	{
		if ($schedule->isShifted())
		{
			return 2;
		}
		if ($schedule->isFixed())
		{
			return 2;
		}
		return 0;
	}

	public function buildRelevantRecordShiftWithDate(\DateTime $start, ?Schedule $schedule, ?Shift $shift): ?ShiftWithDate
	{
		if (!$schedule || !$shift)
		{
			return null;
		}
		foreach ($this->buildDatesIterator($start, $this->getSearchDaysForRelevantShift($schedule)) as $date)
		{
			$shiftWithDate = new ShiftWithDate($shift, $schedule, $date, (int)$this->userId);
			if ($shiftWithDate->isEligibleToStart($start))
			{
				return $shiftWithDate;
			}
		}
		return null;
	}

	private function buildDatesIterator(\DateTime $userDateTime, $daysToCheck)
	{
		$yesterdayUserDateTime = clone $userDateTime;
		$yesterdayUserDateTime->sub(new \DateInterval('P1D'));
		return TimeHelper::getInstance()->buildDatesIterator($yesterdayUserDateTime, $daysToCheck);
	}

	/**
	 * @param \DateTime $from
	 * @param \DateTime $to
	 * @return ShiftWithDate[]
	 * @throws \Bitrix\Main\ArgumentException
	 */
	public function buildShiftWithDates(\DateTime $from, \DateTime $to, bool $checkShiftPlan = true)
	{
		$workingShifts = [];
		$periodIterator = TimeHelper::getInstance()->buildDatesIterator($from, $to);
		foreach ($periodIterator as $date)
		{
			foreach ($this->activeSchedules as $schedule)
			{
				foreach ($this->getShiftsByDate($schedule, $date) as $shift)
				{
					$shiftWithDate = new ShiftWithDate($shift, $schedule, $date, (int)$this->userId);

					if (
						$checkShiftPlan
						&& $schedule->isShifted()
						&& !$this->hasShiftPlan($shiftWithDate)
					)
					{
						continue;
					}
					$workingShifts[] = $shiftWithDate;
				}
			}
		}
		return $workingShifts;
	}
}