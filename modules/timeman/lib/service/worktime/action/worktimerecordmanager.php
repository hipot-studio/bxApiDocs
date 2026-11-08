<?php
namespace Bitrix\Timeman\Service\Worktime\Action;

use Bitrix\Timeman\Helper\TimeHelper;
use Bitrix\Timeman\Model\Schedule\Schedule;
use Bitrix\Timeman\Model\Schedule\Shift\Shift;
use Bitrix\Timeman\Model\Worktime\Record\WorktimeRecord;

class WorktimeRecordManager
{
	/**
	 * Cap applied to the resume/expiration threshold when no next shift can move it: 18 hours from the
	 * record start. Numerically equal to the 2 * 9 * 3600 literal in
	 * moreThanHalfTillNextShiftOrNewShiftStarted(), but a different notion - a schedule-agnostic neutral
	 * window, not two standard working days - so the two must stay independent.
	 */
	public const NEUTRAL_THRESHOLD_SECONDS = 18 * 3600;

	/** @var Shift|null */
	private $shift;
	/** @var Schedule|null */
	private $schedule;
	/** @var WorktimeRecord|null */
	private $record;
	/** @var \DateTime */
	private $currentDateTime;
	/** @var ShiftsManager */
	private $shiftsManager;

	public function __construct(WorktimeRecord $record, ?Schedule $schedule, ?Shift $shift, \DateTime $currentDateTime, ShiftsManager $shiftsManager)
	{
		$this->record = $record;
		$this->shift = $shift;
		$this->schedule = $schedule;
		$this->currentDateTime = clone $currentDateTime;
		$this->shiftsManager = $shiftsManager;
		return $this;
	}

	public function getRecommendedStopTimestamp(): ?int
	{
		if ($shift = $this->buildRecordShiftWithDate())
		{
			return $shift->getDateTimeEnd()->getTimestamp();
		}
		if ($nextShift = $this->buildNextClosestShiftWithDate())
		{
			return $this->buildRecordStartDateTime()->getTimestamp() + $nextShift->getShift()->getDuration();
		}
		return $this->buildRecordStartDateTime()->getTimestamp() + 9 * 3600;
	}

	public function isEligibleToReopen()
	{
		if ($this->record->getRecordedStopTimestamp() === 0)
		{
			return false;
		}
		if (!$this->schedule)
		{
			return false;
		}
		if ((!$this->schedule->isAllowedToReopenRecord() || $this->schedule->isFlextime()))
		{
			return false;
		}

		return !$this->thresholdPassedWithCeiling();
	}

	public function isEligibleToEdit()
	{
		if ($this->schedule && !$this->schedule->isAllowedToEditRecord())
		{
			return false;
		}
		if ($this->isRecordExpiredWithoutNeutralCeiling())
		{
			return true;
		}

		return !$this->moreThanHalfTillNextShiftOrNewShiftStarted();
	}

	public function isRecordExpired()
	{
		return !$this->hasRecordedStop() && $this->thresholdPassedWithCeiling();
	}

	public function isRecordScheduleActual(): bool
	{
		return $this->shiftsManager->containsSchedule((int)$this->record->getScheduleId());
	}

	/**
	 * Expiration for the edit window: it must stay on the plain threshold, ceiling excluded.
	 */
	private function isRecordExpiredWithoutNeutralCeiling(): bool
	{
		return !$this->hasRecordedStop() && $this->moreThanHalfTillNextShiftOrNewShiftStarted();
	}

	private function hasRecordedStop(): bool
	{
		return $this->record->getRecordedStopTimestamp() > 0;
	}

	/**
	 * Threshold seen by the resume and expiration decisions only: a record left without a next shift to
	 * wait for additionally expires at NEUTRAL_THRESHOLD_SECONDS after its start.
	 */
	private function thresholdPassedWithCeiling(): bool
	{
		if ($this->moreThanHalfTillNextShiftOrNewShiftStarted())
		{
			return true;
		}
		if (!$this->isCeilingApplicable())
		{
			return false;
		}

		return $this->currentDateTime->getTimestamp()
			> ($this->buildRecordStartDateTime()->getTimestamp() + self::NEUTRAL_THRESHOLD_SECONDS);
	}

	/**
	 * Either the record's schedule has left the employee's active set, or a flextime schedule was added
	 * beside it, silencing the shift search of the whole set: both leave the plain threshold with nothing
	 * to move it.
	 */
	private function isCeilingApplicable(): bool
	{
		// A record carrying no schedule has none that could leave the set, so it keeps the plain threshold.
		if ((int)$this->record->getScheduleId() <= 0)
		{
			return false;
		}
		if (!$this->isRecordScheduleActual())
		{
			return true;
		}

		// A flextime record of its own set never had a next shift to wait for - nothing changed for it.
		return $this->shiftsManager->hasFlextimeSchedule() && !Schedule::isScheduleFlextime($this->schedule);
	}

	private function moreThanHalfTillNextShiftOrNewShiftStarted()
	{
		if ($this->shift)
		{
			if ($this->schedule && $this->schedule->isShifted())
			{
				return $this->currentDateTime->getTimestamp() > ($this->getRecommendedStopTimestamp() + 3600);
			}
			return !$this->lessThanHalfBetweenShifts();
		}
		elseif ($this->schedule && !$this->schedule->isFlextime())
		{
			if ($nextShift = $this->buildNextClosestShiftWithDate())
			{
				if ($nextShift->getDateTimeStart()->getTimestamp() - $this->record->getRecordedStartTimestamp() < 24 * 3600)
				{
					$shiftDuration = $nextShift->getShift()->getDuration();
					return $this->currentDateTime->getTimestamp() > ($nextShift->getDateTimeStart()->getTimestamp() - $shiftDuration / 2);
				}
			}
			return $this->currentDateTime->getTimestamp() > ($this->record->getRecordedStartTimestamp() + 2 * 9 * 3600);
		}

		return $this->currentDateTime->getTimestamp() >= $this->buildStartPlusTwoDays()->getTimestamp();
	}

	private function buildRecordShiftWithDate(): ?ShiftWithDate
	{
		return $this->shiftsManager->buildRelevantRecordShiftWithDate($this->buildRecordStartDateTime(), $this->schedule, $this->shift);
	}

	public function isEligibleToStop()
	{
		return $this->record->isActive() || $this->record->getRecordedStopTimestamp() === 0;
	}

	public function isEligibleToPause()
	{
		if ($this->record->getRecordedStopTimestamp() === 0 && !$this->isRecordExpired() && !$this->record->isPaused())
		{
			return true;
		}
		return false;
	}

	public function isEligibleToContinue()
	{
		return $this->record->isPaused() && !$this->isRecordExpired();
	}

	private function buildStartPlusTwoDays()
	{
		$startDate = $this->buildRecordStartDateTime();
		$startDate->add(new \DateInterval('P2D'));
		$startDate->setTime(0, 0, 0);
		return $startDate;
	}

	private function lessThanHalfBetweenShifts()
	{
		$idealRecordEnd = $this->getRecommendedStopTimestamp();
		if ($nextShift = $this->buildNextClosestShiftWithDate())
		{
			$interval = $nextShift->getDateTimeStart()->getTimestamp() - $idealRecordEnd;
			if ($this->buildRecordShiftWithDate())
			{
				$shiftDuration = $this->buildRecordShiftWithDate()->getShift()->getDuration();
			}
			else
			{
				$shiftDuration = $nextShift->getShift()->getDuration();
			}
			$maxInterval = min($interval / 2, $shiftDuration);
			return $this->currentDateTime->getTimestamp() < ($idealRecordEnd + $maxInterval);
		}

		return $this->currentDateTime->getTimestamp() < $this->buildStartPlusTwoDays()->getTimestamp();
	}

	private function buildNextClosestShiftWithDate()
	{
		return $this->shiftsManager->buildNextShiftWithDate($this->buildRecordStartDateTime(), $this->buildRecordShiftWithDate());
	}

	public function getRecordSchedule()
	{
		return $this->schedule;
	}

	public function getRecordShift()
	{
		return $this->shift;
	}

	public function getRecord(): ?WorktimeRecord
	{
		return $this->record;
	}

	public function buildStopTimestampForAutoClose()
	{
		if (!($this->schedule instanceof Schedule))
		{
			return null;
		}

		$isCheckInStartEnabled = \Bitrix\Timeman\Integration\Stafftrack\CheckIn::isCheckInStartEnabled();

		if ($this->shift && !$isCheckInStartEnabled)
		{
			return $this->getRecommendedStopTimestamp();
		}

		if ($isCheckInStartEnabled)
		{
			return $this->buildStopTimestampForCheckInAutoClose();
		}

		if ($this->schedule->isShifted())
		{
			/** @var Shift $firstRandomShift */
			$shifts = $this->schedule->obtainActiveShifts();
			$firstRandomShift = reset($shifts);
			if (!$firstRandomShift)
			{
				return null;
			}
			return $this->record->getRecordedStartTimestamp() + $firstRandomShift->getDuration();
		}

		return null;
	}

	public function getSchedule()
	{
		return $this->schedule;
	}

	private function buildRecordStartDateTime()
	{
		if ($this->record->buildRecordedStartDateTime() === null)
		{
			return TimeHelper::getInstance()->createUserDateTimeFromFormat('U', $this->record->getDateStart()->getTimestamp(), $this->record->getUserId());
		}
		return $this->record->buildRecordedStartDateTime();
	}

	private function buildStopTimestampForCheckInAutoClose(): ?int
	{
		if ($this->schedule && $this->schedule->isFlextime())
		{
			return $this->record->getRecordedStartTimestamp() + 12 * 3600;
		}

		$shift = $this->buildShiftForAutoClose();
		if (!$shift)
		{
			return null;
		}

		if ($this->schedule && $this->schedule->isShifted())
		{
			return $this->buildShiftEndTimestampForAutoClose($shift);
		}

		return $this->record->getRecordedStartTimestamp() + $shift->getDuration();
	}

	private function buildShiftEndTimestampForAutoClose(Shift $shift): int
	{
		$recordStartDateTime = $this->buildRecordStartDateTime();

		$previousShiftDateTime = clone $recordStartDateTime;
		$previousShiftDateTime->sub(new \DateInterval('P1D'));
		$previousShiftWithDate = new ShiftWithDate($shift, $this->schedule, $previousShiftDateTime, (int)$this->record->getUserId());
		if (
			$recordStartDateTime->getTimestamp() >= $previousShiftWithDate->getDateTimeStart()->getTimestamp()
			&& $recordStartDateTime->getTimestamp() <= $previousShiftWithDate->getDateTimeEnd()->getTimestamp()
		)
		{
			return $previousShiftWithDate->getDateTimeEnd()->getTimestamp();
		}

		return (new ShiftWithDate($shift, $this->schedule, $recordStartDateTime, (int)$this->record->getUserId()))
			->getDateTimeEnd()
			->getTimestamp()
		;
	}

	private function buildShiftForAutoClose(): ?Shift
	{
		if ($this->shift)
		{
			return $this->shift;
		}

		if (!$this->schedule)
		{
			return null;
		}

		if ($this->record->getShiftId() > 0)
		{
			$shift = $this->schedule->obtainShiftByPrimary($this->record->getShiftId());
			if ($shift)
			{
				return $shift;
			}
		}

		if ($this->schedule->isFixed())
		{
			return $this->schedule->getShiftByWeekDay(
				TimeHelper::getInstance()->getDayOfWeek($this->buildRecordStartDateTime())
			);
		}

		if ($this->schedule->isShifted())
		{
			$shifts = $this->schedule->obtainActiveShifts();
			$shift = reset($shifts);

			return $shift ?: null;
		}

		return null;
	}
}
