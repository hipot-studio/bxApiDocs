<?php
namespace Bitrix\Timeman\Service\Worktime\Action;

use Bitrix\Timeman\Helper\TimeHelper;
use Bitrix\Timeman\Model\Schedule\Schedule;
use Bitrix\Timeman\Model\Schedule\ScheduleCollection;
use Bitrix\Timeman\Model\Schedule\Shift\Shift;
use Bitrix\Timeman\Model\Worktime\Record\WorktimeRecord;
use Bitrix\Timeman\Provider\Schedule\ScheduleProvider;
use Bitrix\Timeman\Provider\Schedule\ShiftPlanProvider;
use Bitrix\Timeman\Repository\Schedule\ShiftPlanRepository;
use Bitrix\Timeman\Repository\Worktime\WorktimeRepository;

class WorktimeActionList
{
	/** @var WorktimeAction[] */
	private $actions;
	private $userId;
	/** @var \DateTime */
	private $userDateTime;

	/** @var WorktimeRepository */
	private $worktimeRepository;
	/** @var ScheduleProvider */
	private $scheduleProvider;
	/** @var ShiftPlanRepository */
	private $shiftPlanProvider;
	/** @var WorktimeRecordManager */
	private $recordManager;
	/** @var ShiftsManager */
	private $shiftsManager;

	public function __construct(
		ShiftPlanProvider $shiftPlanProvider,
		WorktimeRepository $worktimeRepository,
		ScheduleProvider $scheduleProvider
	)
	{
		$this->shiftPlanProvider = $shiftPlanProvider;
		$this->worktimeRepository = $worktimeRepository;
		$this->scheduleProvider = $scheduleProvider;
	}

	/**
	 * @param $userId
	 * @param null $currentUserDateTime
	 * @return $this
	 */
	public function buildPossibleActionsListForUser($paramUserId, ?\DateTime $paramUserDate = null)
	{
		$userId = (int)$paramUserId;
		if ($paramUserDate)
		{
			$userDate = clone $paramUserDate;
		}
		else
		{
			$userDate = TimeHelper::getInstance()->getUserDateTimeNow($userId);
		}

		$this->userId = $userId;
		$this->userDateTime = $userDate;

		$record = $this->worktimeRepository->findLatestRecord($this->userId);
		$scheduleCollection = $this->scheduleProvider->findSchedulesCollectionByUserId($this->userId);

		$this->shiftsManager = new ShiftsManager(
			$this->userId,
			$scheduleCollection,
			$this->shiftPlanProvider
		);

		if ($record)
		{
			$recordSchedule = $record->obtainSchedule();

			$this->recordManager = new WorktimeRecordManager(
				$record,
				$recordSchedule,
				$record->obtainShift(),
				$this->userDateTime,
				$this->shiftsManager
			);
		}

		$this->actions = $this->buildActions($record);
		return $this;
	}

	private function buildActions(WorktimeRecord $record = null)
	{
		/** @var WorktimeAction[] $actions */
		$actions = [];
		if ($this->isEligibleToStart($record))
		{
			$action = WorktimeAction::createStartAction($this->userId);
			$shiftWithDate = $this->getRelevantShiftWithDate($this->userDateTime, $record);
			if ($shiftWithDate)
			{
				$action->setShift($shiftWithDate->getShift());
				$action->setSchedule($shiftWithDate->getSchedule());
			}
			else
			{
				$action->setSchedule($this->shiftsManager->getScheduleToStart($this->userDateTime));
			}
			$actions[] = $action;
		}
		if ($record)
		{
			if ($this->recordManager->isEligibleToStop())
			{
				$actions[] = WorktimeAction::createStopAction($this->userId);
			}

			if ($this->recordManager->isEligibleToPause())
			{
				$actions[] = WorktimeAction::createPauseAction($this->userId);
			}

			if ($this->recordManager->isEligibleToReopen())
			{
				$actions[] = WorktimeAction::createReopenAction($this->userId);
			}
			if ($this->recordManager->isEligibleToContinue())
			{
				$actions[] = WorktimeAction::createContinueAction($this->userId);
			}

			if ($this->recordManager->isEligibleToEdit())
			{
				$actions[] = WorktimeAction::createEditAction($this->userId);
			}
		}

		return $this->fillActions($actions);
	}

	private function isEligibleToStart(?WorktimeRecord $record)
	{
		if (!$record || $record->getRecordedStartTimestamp() > TimeHelper::getInstance()->getUtcNowTimestamp())
		{
			return true;
		}

		// Only a consistently closed record can open the start: a record still holding an OPENED or PAUSED
		// status has to be closed first, whatever stop time is already recorded on it.
		if ($record->isClosed() && $record->getRecordedStopTimestamp() > 0)
		{
			if (!$this->recordManager->isEligibleToReopen())
			{
				return true;
			}

			$prevShift = $this->buildShiftWithDateByRecord($record);
			if ($prevShift)
			{
				if ($this->userDateTime->getTimestamp() <= $prevShift->getDateTimeEnd()->getTimestamp())
				{
					// The end of the record's own shift is worth waiting for only while that schedule is still
					// the user's; otherwise a shift of the current set open right now opens the start.
					return !$this->recordManager->isRecordScheduleActual()
						&& $this->getRelevantShiftWithDate($this->userDateTime, $record) !== null;
				}

				// While the schedule is still the user's, the start keeps waiting for a shift open right now.
				return !$this->recordManager->isRecordScheduleActual()
					|| $this->getRelevantShiftWithDate($this->userDateTime, $record) !== null;
			}

			// Nothing to wait for: the record has no shift of its own and its schedule is no longer the user's.
			return !$this->recordManager->isRecordScheduleActual();
		}

		return false;
	}

	/**
	 * @return WorktimeAction[]
	 */
	public function getStopActions()
	{
		$res = [];
		foreach ($this->actions as $action)
		{
			if ($action->isStop())
			{
				$res[] = $action;
			}
		}
		return $res;
	}

	/**
	 * @return WorktimeAction[]
	 */
	public function getEditActions()
	{
		$res = [];
		foreach ($this->actions as $action)
		{
			if ($action->isEdit())
			{
				$res[] = $action;
			}
		}
		return $res;
	}

	/**
	 * @return WorktimeAction[]
	 */
	public function getContinueActions()
	{
		$res = [];
		foreach ($this->actions as $action)
		{
			if ($action->isContinue())
			{
				$res[] = $action;
			}
		}
		return $res;
	}

	/**
	 * @return WorktimeAction[]
	 */
	public function getReopenActions()
	{
		$res = [];
		foreach ($this->actions as $action)
		{
			if ($action->isReopen())
			{
				$res[] = $action;
			}
		}
		return $res;
	}

	/**
	 * @return WorktimeAction[]
	 */
	public function getPauseActions()
	{
		$res = [];
		foreach ($this->actions as $action)
		{
			if ($action->isPause())
			{
				$res[] = $action;
			}
		}
		return $res;
	}

	/**
	 * @return WorktimeAction[]
	 */
	public function getStartActions()
	{
		$res = [];
		foreach ($this->actions as $action)
		{
			if ($action->isStart())
			{
				$res[] = $action;
			}
		}
		return $res;
	}

	/**
	 * @return WorktimeAction[]
	 */
	public function getAllActions()
	{
		return $this->actions;
	}

	public function buildApproveAction(WorktimeRecord $record, ?Schedule $schedule, ?Shift $shift, \DateTime $userDateTime)
	{
		$shiftsManager = new ShiftsManager(
			$record->getUserId(),
			$this->scheduleProvider->findSchedulesCollectionByUserId($record->getUserId()),
			$this->shiftPlanProvider
		);
		$recordManager = new WorktimeRecordManager(
			$record,
			$schedule,
			$shift,
			$userDateTime,
			$shiftsManager
		);
		return WorktimeAction::createApproveAction($record->getUserId())
			->setShift($record->obtainShift())
			->setSchedule($record->obtainSchedule())
			->setRecordManager($recordManager)
			->setRecord($record);
	}

	public function fillActions(array $actions): array
	{
		foreach ($actions as $action)
		{
			$action->setRecordManager($this->recordManager);
			$action->setShiftsManager($this->shiftsManager);
			if (!$action->isStart() && $this->recordManager)
			{
				$action->setRecord($this->recordManager->getRecord());
				$action->setSchedule($this->recordManager->getRecordSchedule());
				$action->setShift($this->recordManager->getRecordShift());
			}
		}

		return $actions;
	}

	private function buildShiftWithDateByRecord(?WorktimeRecord $record)
	{
		if ($record)
		{
			return $this->shiftsManager->buildRelevantRecordShiftWithDate(
				$record->buildRecordedStartDateTime(),
				$record->obtainSchedule(),
				$record->obtainShift()
			);
		}
		return null;
	}

	private function getRelevantShiftWithDate(\DateTime $userDateTime, ?WorktimeRecord $record)
	{
		$prevShift = $this->buildShiftWithDateByRecord($record);
		// The lower bound the record's shift sets - no day may start on a shift beginning before it - keeps the
		// user off a shift of their own schedule that has already passed. A schedule that left the set bounds
		// nothing: its shift must not hide a shift of the current set that starts earlier in the day.
		if ($this->recordManager && !$this->recordManager->isRecordScheduleActual())
		{
			$prevShift = null;
		}

		return $this->shiftsManager->buildRelevantShiftWithDate($userDateTime, $prevShift);
	}
}